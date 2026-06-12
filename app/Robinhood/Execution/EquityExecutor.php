<?php

declare(strict_types=1);

namespace App\Robinhood\Execution;

use App\Models\RobinhoodTradeJournal;
use App\Robinhood\Mcp\RobinhoodMcpClient;
use App\Robinhood\Rails\OrderIntent;
use App\Robinhood\Rails\RailPipeline;
use App\Robinhood\Telemetry\JsonlRecorder;

/**
 * Deterministic equity execution: review -> rails -> persisted ref ->
 * place -> journal. No model anywhere on this path. The ref_id is durable
 * before the place call; a transport retry replays the same ref verbatim.
 */
final class EquityExecutor
{
    public function __construct(
        private readonly RobinhoodMcpClient $client,
        private readonly RailPipeline $rails,
        private readonly OrderRefStore $refs,
        private readonly JsonlRecorder $telemetry,
        private readonly array $cfg,
    ) {}

    /**
     * @param  array  $context  broker/account truth snapshot assembled by the
     *                          caller (settled_cash, spent_today_debit, open
     *                          orders/positions, session, tradability, review)
     */
    public function execute(OrderIntent $intent, array $context = []): array
    {
        // Programmatic pre-trade review unless the caller already holds one.
        if (! array_key_exists('review', $context)) {
            $context['review'] = $this->safeReview($intent);
        }

        // Idempotency ref is persisted BEFORE rails/place; a re-run of the
        // same logical intent returns the same ref.
        $ref = $this->refs->reserve($intent);
        $context['existing_ref'] = [
            'client_ref_id' => $ref->client_ref_id,
            'status' => $ref->status,
            'broker_order_id' => $ref->broker_order_id,
        ];

        $verdict = $this->rails->evaluate($intent, $this->cfg, $context);

        if (! $verdict->allowed()) {
            $journal = $this->journal($intent, $ref->client_ref_id, 'risk_aborted', [
                'rail_verdict' => $verdict->toArray(),
            ]);

            $this->telemetry->record('execution', [
                'event' => 'rail_block',
                'classification' => 'LIVE_RISK_ABORTED',
                'client_ref_id' => $ref->client_ref_id,
                'symbol' => strtoupper($intent->symbol),
                'block_reasons' => $verdict->blockReasons(),
                'journal_id' => $journal->id,
            ]);

            return [
                'classification' => 'LIVE_RISK_ABORTED',
                'verdict' => $verdict,
                'ref' => $ref,
                'journal_id' => $journal->id,
                'placement' => null,
            ];
        }

        $order = $this->orderPayload($intent);

        try {
            $placement = $this->client->placeEquityOrder($order, $ref->client_ref_id);
        } catch (\Throwable $e) {
            $this->refs->markFailed($ref, $e->getMessage());

            $journal = $this->journal($intent, $ref->client_ref_id, 'rejected', [
                'rail_verdict' => $verdict->toArray(),
                'placement_error' => $e->getMessage(),
            ]);

            $this->telemetry->record('execution', [
                'event' => 'place_failed',
                'client_ref_id' => $ref->client_ref_id,
                'symbol' => strtoupper($intent->symbol),
                'error' => $e->getMessage(),
                'journal_id' => $journal->id,
            ]);

            throw $e;
        }

        $brokerOrderId = $placement['order_id'] ?? $placement['id'] ?? null;
        $this->refs->markPlaced($ref, is_string($brokerOrderId) ? $brokerOrderId : null);

        if (! is_string($brokerOrderId)) {
            // Reconciliation still matches by ref_id, but a placement response
            // without an order id is a broker-payload anomaly worth tracing.
            $this->telemetry->record('execution', [
                'event' => 'placed_missing_broker_order_id',
                'severity' => 'warn',
                'client_ref_id' => $ref->client_ref_id,
                'symbol' => strtoupper($intent->symbol),
                'placement_keys' => array_keys($placement),
            ]);
        }

        $journal = $this->journal($intent, $ref->client_ref_id, 'submitted', [
            'rail_verdict' => $verdict->toArray(),
        ], [
            'broker_order_id' => is_string($brokerOrderId) ? $brokerOrderId : null,
            'submitted_at' => now(),
            'review_payload' => is_array($context['review']) ? $context['review'] : null,
            'broker_payload' => ['placement' => $placement, 'dry_run' => false],
        ]);

        $this->telemetry->record('execution', [
            'event' => 'placed',
            'classification' => 'LIVE_POSTED_NOT_FILLED',
            'client_ref_id' => $ref->client_ref_id,
            'broker_order_id' => $brokerOrderId,
            'symbol' => strtoupper($intent->symbol),
            'journal_id' => $journal->id,
        ]);

        return [
            'classification' => 'LIVE_POSTED_NOT_FILLED',
            'verdict' => $verdict,
            'ref' => $ref->refresh(),
            'journal_id' => $journal->id,
            'placement' => $placement,
        ];
    }

    /**
     * Broker wire format (live-measured schema): field is `type`, numeric
     * values are strings, additionalProperties=false.
     */
    public function orderPayload(OrderIntent $intent): array
    {
        return array_filter([
            'symbol' => strtoupper($intent->symbol),
            'side' => $intent->side,
            'type' => $intent->orderType,
            'time_in_force' => $intent->timeInForce,
            'market_hours' => $intent->marketHours,
            'quantity' => $this->decimalString($intent->quantity, 6),
            'dollar_amount' => $this->decimalString($intent->dollarAmount, 2, trim: false),
            'limit_price' => $this->decimalString($intent->limitPrice, 4),
            'stop_price' => $this->decimalString($intent->stopPrice, 4),
        ], fn ($v) => $v !== null);
    }

    private function decimalString(?float $value, int $precision, bool $trim = true): ?string
    {
        if ($value === null) {
            return null;
        }

        $formatted = number_format($value, $precision, '.', '');

        if ($trim && str_contains($formatted, '.')) {
            $formatted = rtrim(rtrim($formatted, '0'), '.');
        }

        return $formatted;
    }

    private function safeReview(OrderIntent $intent): array
    {
        try {
            return $this->client->reviewEquityOrder($this->orderPayload($intent));
        } catch (\Throwable $e) {
            // A failed review is a block (review_rejected), never a pass-through.
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private function journal(OrderIntent $intent, string $clientRefId, string $status, array $notes, array $extra = []): RobinhoodTradeJournal
    {
        return RobinhoodTradeJournal::query()->updateOrCreate(
            ['client_ref_id' => $clientRefId],
            array_merge([
                'account_number' => (string) ($this->cfg['agentic_account'] ?? ''),
                'asset_class' => $intent->assetClass,
                'symbol' => strtoupper($intent->symbol),
                'status' => $status,
                'intent' => $intent->side === 'buy' ? 'long_equity' : 'reduce_equity',
                'side' => $intent->side,
                'order_type' => $intent->orderType,
                'time_in_force' => $intent->timeInForce,
                'market_hours' => $intent->marketHours,
                'quantity' => $intent->quantity,
                'dollar_amount' => $intent->dollarAmount,
                'limit_price' => $intent->limitPrice,
                'stop_price' => $intent->stopPrice,
                'strategy_label' => 'theta_equity_probe',
                'scanner_payload' => ['intent_source' => $intent->source],
                'notes' => $notes,
            ], $extra),
        );
    }
}
