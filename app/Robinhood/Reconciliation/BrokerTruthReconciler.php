<?php

declare(strict_types=1);

namespace App\Robinhood\Reconciliation;

/**
 * Broker/account truth outranks logs. Classifies every execution row
 * explicitly; a `filled` label without filled_qty>0 and fill_price>0 is a
 * label conflict, not a fill. Pure arrays in -> ledger out, so the
 * classification logic is unit-testable without a broker.
 */
final class BrokerTruthReconciler
{
    public const LIVE_FILLED = 'LIVE_FILLED';

    public const LIVE_POSTED_NOT_FILLED = 'LIVE_POSTED_NOT_FILLED';

    public const LIVE_REJECTED = 'LIVE_REJECTED';

    public const LIVE_CANCELLED = 'LIVE_CANCELLED';

    public const LIVE_RISK_ABORTED = 'LIVE_RISK_ABORTED';

    public const TEST_FIXTURE_ONLY = 'TEST_FIXTURE_ONLY';

    public const BROKER_CAPABILITY_PENDING = 'BROKER_CAPABILITY_PENDING';

    public const LABEL_CONFLICT_NOT_BROKER_FILLED = 'LABEL_CONFLICT_NOT_BROKER_FILLED';

    public const UNKNOWN_EXECUTION_STATE = 'UNKNOWN_EXECUTION_STATE';

    private const OPEN_STATES = ['queued', 'unconfirmed', 'confirmed', 'placed', 'pending', 'open', 'new', 'accepted'];

    private const FIXTURE_STATES = ['fixture', 'simulated', 'inert_abort', 'test_fixture_only'];

    public function classify(array $row): string
    {
        $status = strtolower((string) ($row['state'] ?? $row['status'] ?? ''));

        if (($row['dry_run'] ?? false) === true || in_array($status, self::FIXTURE_STATES, true)) {
            return self::TEST_FIXTURE_ONLY;
        }

        if ($status === 'broker_capability_pending') {
            return self::BROKER_CAPABILITY_PENDING;
        }

        if ($status === 'risk_aborted') {
            return self::LIVE_RISK_ABORTED;
        }

        $filledQty = $this->numeric($row, ['filled_quantity', 'filled_qty', 'cumulative_quantity']);
        $fillPrice = $this->numeric($row, ['average_fill_price', 'fill_price', 'average_price', 'executed_price']);
        $brokerProvenFill = $filledQty > 0 && $fillPrice > 0;

        if (in_array($status, ['filled', 'partially_filled'], true)) {
            return $brokerProvenFill ? self::LIVE_FILLED : self::LABEL_CONFLICT_NOT_BROKER_FILLED;
        }

        if (in_array($status, ['cancelled', 'canceled'], true)) {
            // A cancel after a partial fill is still a market outcome.
            return $brokerProvenFill ? self::LIVE_FILLED : self::LIVE_CANCELLED;
        }

        if (in_array($status, ['rejected', 'failed'], true)) {
            return self::LIVE_REJECTED;
        }

        if (in_array($status, self::OPEN_STATES, true)) {
            return self::LIVE_POSTED_NOT_FILLED;
        }

        return self::UNKNOWN_EXECUTION_STATE;
    }

    /**
     * Option-order classification: same taxonomy, with the option-specific
     * broker-truth requirement — a filled option order must also carry an
     * option_id (single leg) or it is a label conflict, not a fill.
     */
    public function classifyOption(array $row): string
    {
        $classification = $this->classify($this->normalizeOptionRow($row));

        if ($classification === self::LIVE_FILLED && $this->optionId($row) === null) {
            return self::LABEL_CONFLICT_NOT_BROKER_FILLED;
        }

        return $classification;
    }

    /**
     * Reconcile broker option orders against local journal option rows.
     *
     * @param  list<array>  $brokerOrders  rows from get_option_orders(placed_agent='agentic')
     * @param  list<array>  $journalRows  local option journal rows
     */
    public function reconcileOptions(array $brokerOrders, array $journalRows): array
    {
        $journalByRef = [];
        $journalByBrokerId = [];
        foreach ($journalRows as $row) {
            if (! empty($row['client_ref_id'])) {
                $journalByRef[(string) $row['client_ref_id']] = $row;
            }
            if (! empty($row['broker_order_id'])) {
                $journalByBrokerId[(string) $row['broker_order_id']] = $row;
            }
        }

        $ledger = [];
        $matched = [];

        foreach ($brokerOrders as $order) {
            $refId = (string) ($order['ref_id'] ?? $order['client_ref_id'] ?? '');
            $brokerOrderId = (string) ($order['id'] ?? $order['order_id'] ?? '');

            $journal = $journalByRef[$refId] ?? $journalByBrokerId[$brokerOrderId] ?? null;
            if ($journal !== null && ! empty($journal['client_ref_id'])) {
                $matched[(string) $journal['client_ref_id']] = true;
            }

            $normalized = $this->normalizeOptionRow($order);
            $classification = $this->classifyOption($order);
            $leg = $this->firstLeg($order);

            $ledger[] = [
                'agent_id' => 'theta-harness',
                'source' => 'broker',
                'asset_class' => 'option',
                'classification' => $classification,
                'broker_order_id' => $brokerOrderId !== '' ? $brokerOrderId : null,
                'client_ref_id' => $refId !== '' ? $refId : null,
                'option_id' => $this->optionId($order),
                'symbol' => strtoupper((string) ($order['chain_symbol'] ?? $order['symbol'] ?? '')),
                'side' => $leg['side'] ?? $order['side'] ?? null,
                'position_effect' => $leg['position_effect'] ?? $order['position_effect'] ?? null,
                'broker_status' => $order['state'] ?? $order['status'] ?? null,
                'filled_qty' => $this->numeric($normalized, ['filled_quantity']),
                'fill_price' => $this->numeric($normalized, ['average_fill_price']),
                'journal_matched' => $journal !== null,
                'journal_status' => $journal['status'] ?? null,
                'is_market_outcome' => $this->isMarketOutcome($classification),
            ];
        }

        foreach ($journalRows as $row) {
            $refId = (string) ($row['client_ref_id'] ?? '');
            if ($refId !== '' && isset($matched[$refId])) {
                continue;
            }

            $localStatus = strtolower((string) ($row['status'] ?? ''));
            if (! in_array($localStatus, ['submitted', 'open', 'partially_filled', 'filled'], true)) {
                continue;
            }

            $ledger[] = [
                'agent_id' => 'theta-harness',
                'source' => 'journal_orphan',
                'asset_class' => 'option',
                'classification' => self::UNKNOWN_EXECUTION_STATE,
                'broker_order_id' => $row['broker_order_id'] ?? null,
                'client_ref_id' => $refId !== '' ? $refId : null,
                'option_id' => $row['option_id'] ?? null,
                'symbol' => strtoupper((string) ($row['underlying_symbol'] ?? $row['symbol'] ?? '')),
                'side' => $row['side'] ?? null,
                'position_effect' => $row['position_effect'] ?? null,
                'broker_status' => null,
                'filled_qty' => 0.0,
                'fill_price' => 0.0,
                'journal_matched' => false,
                'journal_status' => $row['status'] ?? null,
                'is_market_outcome' => false,
            ];
        }

        return [
            'ledger' => $ledger,
            'summary' => $this->summarize($ledger),
        ];
    }

    /**
     * Map option-order payload key variants onto the generic classifier's
     * vocabulary (option orders report premium/processed quantities).
     */
    private function normalizeOptionRow(array $row): array
    {
        $filledQuantity = $this->numeric($row, ['processed_quantity', 'filled_quantity', 'filled_qty', 'cumulative_quantity']);
        $averageFillPrice = $this->numeric($row, ['average_price', 'processed_premium_per_contract', 'average_fill_price', 'fill_price']);

        if ($averageFillPrice <= 0.0) {
            $execution = $this->firstLeg($row)['executions'][0] ?? null;
            if (is_array($execution)) {
                $averageFillPrice = $this->numeric($execution, ['price']);
            }
        }

        if ($averageFillPrice <= 0.0 && $filledQuantity > 0.0) {
            $premium = $this->numeric($row, ['processed_premium', 'premium']);
            $multiplier = $this->numeric($row, ['trade_value_multiplier']);
            if ($premium > 0.0 && $multiplier > 0.0) {
                $averageFillPrice = round($premium / ($filledQuantity * $multiplier), 8);
            }
        }

        $row['filled_quantity'] = $filledQuantity;
        $row['average_fill_price'] = $averageFillPrice;

        return $row;
    }

    /** Defensive single-leg extraction — a malformed broker payload yields [], never an error. */
    private function firstLeg(array $row): array
    {
        $legs = $row['legs'] ?? null;
        if (! is_array($legs)) {
            return [];
        }

        $leg = $legs[0] ?? null;

        return is_array($leg) ? $leg : [];
    }

    private function optionId(array $row): ?string
    {
        $optionId = $this->firstLeg($row)['option_id'] ?? $row['option_id'] ?? null;

        return is_string($optionId) && $optionId !== '' ? $optionId : null;
    }

    /** A row counts as a market outcome only under these classifications. */
    public function isMarketOutcome(string $classification): bool
    {
        return in_array($classification, [
            self::LIVE_FILLED,
            self::LIVE_POSTED_NOT_FILLED,
            self::LIVE_REJECTED,
            self::LIVE_CANCELLED,
        ], true);
    }

    /**
     * Reconcile broker order rows against local journal rows.
     *
     * @param  list<array>  $brokerOrders  rows from get_equity_orders(placed_agent='agentic')
     * @param  list<array>  $journalRows  local journal rows (client_ref_id, broker_order_id, status, symbol)
     */
    public function reconcile(array $brokerOrders, array $journalRows): array
    {
        $journalByRef = [];
        $journalByBrokerId = [];
        foreach ($journalRows as $row) {
            if (! empty($row['client_ref_id'])) {
                $journalByRef[(string) $row['client_ref_id']] = $row;
            }
            if (! empty($row['broker_order_id'])) {
                $journalByBrokerId[(string) $row['broker_order_id']] = $row;
            }
        }

        $ledger = [];
        $matchedJournalRefs = [];

        foreach ($brokerOrders as $order) {
            $refId = (string) ($order['ref_id'] ?? $order['client_ref_id'] ?? '');
            $brokerOrderId = (string) ($order['id'] ?? $order['order_id'] ?? '');

            $journal = $journalByRef[$refId] ?? $journalByBrokerId[$brokerOrderId] ?? null;
            if ($journal !== null && ! empty($journal['client_ref_id'])) {
                $matchedJournalRefs[(string) $journal['client_ref_id']] = true;
            }

            $classification = $this->classify($order);

            $ledger[] = [
                'agent_id' => 'theta-harness',
                'source' => 'broker',
                'classification' => $classification,
                'broker_order_id' => $brokerOrderId !== '' ? $brokerOrderId : null,
                'client_ref_id' => $refId !== '' ? $refId : null,
                'symbol' => strtoupper((string) ($order['symbol'] ?? '')),
                'side' => $order['side'] ?? null,
                'broker_status' => $order['state'] ?? $order['status'] ?? null,
                'filled_qty' => $this->numeric($order, ['filled_quantity', 'filled_qty', 'cumulative_quantity']),
                'fill_price' => $this->numeric($order, ['average_fill_price', 'fill_price', 'average_price', 'executed_price']),
                'journal_matched' => $journal !== null,
                'journal_status' => $journal['status'] ?? null,
                'is_market_outcome' => $this->isMarketOutcome($classification),
            ];
        }

        // Local rows that claim submission but have no broker order: trace the producer.
        foreach ($journalRows as $row) {
            $refId = (string) ($row['client_ref_id'] ?? '');
            if ($refId !== '' && isset($matchedJournalRefs[$refId])) {
                continue;
            }

            $localStatus = strtolower((string) ($row['status'] ?? ''));
            if (! in_array($localStatus, ['submitted', 'open', 'partially_filled', 'filled'], true)) {
                continue;
            }

            $ledger[] = [
                'agent_id' => 'theta-harness',
                'source' => 'journal_orphan',
                'classification' => self::UNKNOWN_EXECUTION_STATE,
                'broker_order_id' => $row['broker_order_id'] ?? null,
                'client_ref_id' => $refId !== '' ? $refId : null,
                'symbol' => strtoupper((string) ($row['symbol'] ?? '')),
                'side' => $row['side'] ?? null,
                'broker_status' => null,
                'filled_qty' => 0.0,
                'fill_price' => 0.0,
                'journal_matched' => false,
                'journal_status' => $row['status'] ?? null,
                'is_market_outcome' => false,
            ];
        }

        return [
            'ledger' => $ledger,
            'summary' => $this->summarize($ledger),
        ];
    }

    private function summarize(array $ledger): array
    {
        $counts = [];
        foreach ($ledger as $row) {
            $counts[$row['classification']] = ($counts[$row['classification']] ?? 0) + 1;
        }
        ksort($counts);

        return $counts;
    }

    private function numeric(array $row, array $keys): float
    {
        foreach ($keys as $key) {
            if (isset($row[$key]) && is_numeric($row[$key])) {
                return (float) $row[$key];
            }
        }

        return 0.0;
    }
}
