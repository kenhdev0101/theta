<?php

declare(strict_types=1);

namespace App\Robinhood\Options;

use App\Models\ConvexProbeRun;
use App\Models\RobinhoodTradeJournal;
use App\Robinhood\Convex\ConvexMatrixAdmission;
use App\Robinhood\Execution\OrderRefStore;
use App\Robinhood\Mcp\RobinhoodMcpClient;
use App\Robinhood\Telemetry\JsonlRecorder;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Deterministic single-leg option executor: resolve -> pre-review rails ->
 * persisted ref -> broker review -> full rails -> place -> journal.
 * No model anywhere on this path; the contract is resolver-chosen, never
 * model-chosen. The ref_id is durable before any broker review/place call.
 */
final class OptionExecutor
{
    public function __construct(
        private readonly RobinhoodMcpClient $client,
        private readonly OptionRailPipeline $rails,
        private readonly OrderRefStore $refs,
        private readonly JsonlRecorder $telemetry,
        private readonly array $cfg,
    ) {}

    /**
     * @param  array  $context  broker/account truth snapshot (see
     *                          OptionRailPipeline context contract)
     */
    public function execute(OptionOrderIntent $intent, array $context = []): array
    {
        if (($intent->optionId ?? '') === '') {
            throw new RuntimeException('executor requires a resolver-stamped option_id — symbolic intents resolve before execution');
        }

        $boundedConvex = $intent->source === 'theta:convex-probe' && $intent->isOpening()
            && ($intent->metadata['entry_mode'] ?? null) === 'convex_bounded_resting';
        if ($boundedConvex) {
            $source = $context['convex_matrix_source'] ?? null;
            $block = $this->boundedConvexOwnershipBlocker($intent, $source);
            if ($block === null) {
                $block = $this->matrixAdmissionBlocker($intent, $source);
            }
            if ($block !== null) {
                return $this->abort($intent, new OptionRailVerdict($intent, [
                    OptionGateResult::block('matrix_admission', 'BLOCK_ADMISSION', $block),
                ]), refRow: null, journalId: $this->contextJournalId($context));
            }
        }

        // Phase 1 — everything except review. A capability/permission/cap
        // block must not generate broker review traffic.
        $preVerdict = $this->rails->evaluate($intent, $this->cfg, array_merge($context, [
            'existing_ref' => $this->refSummary($context),
        ]), includeReview: false);

        if (! $preVerdict->allowed()) {
            return $this->abort($intent, $preVerdict, refRow: null, journalId: $this->contextJournalId($context));
        }

        // Idempotency ref is durable BEFORE any broker write-path call.
        $ref = $this->refs->reserve($intent);
        $context['existing_ref'] = [
            'client_ref_id' => $ref->client_ref_id,
            'status' => $ref->status,
            'broker_order_id' => $ref->broker_order_id,
        ];

        if (! array_key_exists('review', $context)) {
            // An already-attempted ref will block at the duplicate gate —
            // do not generate broker review traffic for it.
            $context['review'] = $ref->status === 'reserved' ? $this->safeReview($intent) : null;
        }

        // Phase 2 — full chain including review-dependent checks.
        $verdict = $this->rails->evaluate($intent, $this->cfg, $context);

        if (! $verdict->allowed()) {
            return $this->abort($intent, $verdict, $ref->client_ref_id, $context['review'] ?? null, $this->contextJournalId($context));
        }

        // Review is a broker round trip. The convex probe must still have a
        // fresh quote at the actual placement boundary, not merely at the
        // earlier resolver/rail snapshots.
        if ($intent->source === 'theta:convex-probe') {
            $updatedAt = data_get($context, 'quote.updated_at');
            $maxAge = (int) data_get($this->cfg, 'options.min_quote_fresh_seconds', 10);
            try {
                $age = is_string($updatedAt) ? abs(Carbon::parse($updatedAt)->diffInSeconds(now())) : INF;
            } catch (\Throwable) {
                $age = INF;
            }
            if ($age > $maxAge) {
                $verdict = new OptionRailVerdict($intent, [
                    ...$verdict->results,
                    OptionGateResult::block('quote_freshness', OptionRailPipeline::BLOCK_STALE_QUOTE, 'quote_stale_before_place', [
                        'age_seconds' => is_finite($age) ? $age : null,
                        'gate_seconds' => $maxAge,
                    ]),
                ]);

                return $this->abort($intent, $verdict, $ref->client_ref_id, $context['review'] ?? null, $this->contextJournalId($context));
            }
        }

        // An ADMIT observed before contract selection can be superseded during
        // broker context and review round trips. Recheck the immutable row at
        // the placement boundary so a newer REJECT cannot open a stale thesis.
        $matrixSource = $context['convex_matrix_source'] ?? null;
        if ($intent->source === 'theta:convex-probe' && $intent->isOpening() && is_array($matrixSource)) {
            $block = $this->matrixAdmissionBlocker($intent, $matrixSource);
            if ($block !== null) {
                $verdict = new OptionRailVerdict($intent, [
                    ...$verdict->results,
                    OptionGateResult::block('matrix_admission', 'BLOCK_ADMISSION', $block),
                ]);

                return $this->abort($intent, $verdict, $ref->client_ref_id, $context['review'] ?? null, $this->contextJournalId($context));
            }
        }

        $payload = $this->placePayload($intent);

        try {
            $placement = $this->client->placeOptionOrder($payload, $ref->client_ref_id);
        } catch (\Throwable $e) {
            $this->refs->markFailed($ref, $e->getMessage());
            $this->journal($intent, $ref->client_ref_id, 'rejected', [
                'rail_verdict' => $verdict->toArray(),
                'placement_error' => $e->getMessage(),
            ], journalId: $this->contextJournalId($context));
            $this->telemetry->record('options_execution', [
                'event' => 'place_failed',
                'client_ref_id' => $ref->client_ref_id,
                'contract' => $intent->contractLabel(),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        $brokerOrderId = $this->brokerOrderId($placement);
        $this->refs->markPlaced($ref, is_string($brokerOrderId) ? $brokerOrderId : null);

        $journal = $this->journal($intent, $ref->client_ref_id, 'submitted', [
            'rail_verdict' => $verdict->toArray(),
        ], [
            'broker_order_id' => is_string($brokerOrderId) ? $brokerOrderId : null,
            'submitted_at' => now(),
            'review_payload' => is_array($context['review'] ?? null) ? $context['review'] : null,
            'broker_payload' => ['placement' => $placement, 'dry_run' => false],
        ], $this->contextJournalId($context));

        $this->telemetry->record('options_execution', [
            'event' => 'placed',
            'classification' => 'LIVE_POSTED_NOT_FILLED',
            'client_ref_id' => $ref->client_ref_id,
            'broker_order_id' => $brokerOrderId,
            'contract' => $intent->contractLabel(),
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

    private function brokerOrderId(array $placement): ?string
    {
        $brokerOrderId = $placement['order_id']
            ?? $placement['id']
            ?? $placement['data']['order']['id']
            ?? null;

        return is_string($brokerOrderId) && $brokerOrderId !== '' ? $brokerOrderId : null;
    }

    /** Matrix evidence is checked before broker review and again immediately before place. */
    private function boundedConvexOwnershipBlocker(OptionOrderIntent $intent, mixed $source): ?string
    {
        if (! is_array($source)) {
            return 'matrix_evidence_required_for_bounded_entry';
        }
        $thesisId = $intent->metadata['thesis_id'] ?? null;
        $specHash = $intent->metadata['spec_hash'] ?? null;
        $run = is_string($thesisId) && $thesisId !== ''
            ? ConvexProbeRun::query()->where('thesis_id', $thesisId)->first()
            : null;
        if (! $run instanceof ConvexProbeRun || ! is_string($specHash) || $run->spec_hash !== $specHash
            || $run->account_number !== (string) ($this->cfg['agentic_account'] ?? '')
            || $run->status !== 'entry_pending' || $run->option_id !== $intent->optionId
            || abs((float) $run->entry_limit_price - (float) $intent->limitPrice) > 0.000001
            || $run->entry_intent_key !== $intent->intentKey(now()->toDateString())
            || data_get($run->spec, 'matrix_source.row_sha256') !== ($source['row_sha256'] ?? null)
            || (float) data_get($run->spec, 'legs.0.fast_exit.hard_stop_pct', 0) !== (float) ($intent->metadata['hard_stop_pct'] ?? 0)) {
            return 'bounded_convex_run_ownership_unverified';
        }

        return null;
    }

    /** Matrix evidence is checked before broker review and again immediately before place. */
    private function matrixAdmissionBlocker(OptionOrderIntent $intent, array $matrixSource): ?string
    {
        try {
            $dte = (int) Carbon::now('America/New_York')->startOfDay()->diffInDays(
                Carbon::parse($intent->expirationDate, 'America/New_York')->startOfDay(), false,
            );

            return ConvexMatrixAdmission::currentBlocker(
                $matrixSource, $intent->underlyingSymbol, $dte,
                (string) data_get($this->cfg, 'convex.matrix_path', storage_path('harness/convex_feature_matrix.csv')),
            );
        } catch (\Throwable) {
            return 'matrix_unavailable_before_place';
        }
    }

    /** Wire payload pinned to the live-measured place_option_order schema. */
    public function placePayload(OptionOrderIntent $intent): array
    {
        $payload = [
            'legs' => [[
                'option_id' => (string) $intent->optionId,
                'side' => $intent->side,
                'position_effect' => $intent->positionEffect,
                'ratio_quantity' => 1,
            ]],
            'type' => $intent->orderType,
            'quantity' => (string) $intent->quantity,
            'time_in_force' => $intent->timeInForce,
            'market_hours' => $intent->marketHours,
        ];

        if ($intent->limitPrice !== null) {
            $payload['price'] = number_format($intent->limitPrice, 2, '.', '');
        }

        return $payload;
    }

    /** Review payload adds chain_symbol/underlying_type for fees+collateral (broker guidance). */
    public function reviewPayload(OptionOrderIntent $intent): array
    {
        return array_merge($this->placePayload($intent), [
            'chain_symbol' => strtoupper($intent->underlyingSymbol),
            'underlying_type' => $intent->underlyingType ?? 'equity',
        ]);
    }

    private function safeReview(OptionOrderIntent $intent): array
    {
        try {
            return $this->client->reviewOptionOrder($this->reviewPayload($intent));
        } catch (\Throwable $e) {
            // A failed review blocks; it never passes through.
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private function abort(OptionOrderIntent $intent, OptionRailVerdict $verdict, ?string $refRow, ?array $review = null, ?int $journalId = null): array
    {
        $recordedJournalId = $journalId;
        if ($refRow !== null) {
            $journal = $this->journal($intent, $refRow, 'risk_aborted', [
                'rail_verdict' => $verdict->toArray(),
            ], [
                'review_payload' => $review,
            ], $journalId);
            $recordedJournalId = $journal->id;
        }

        $this->telemetry->record('options_execution', [
            'event' => 'rail_block',
            'classification' => 'LIVE_RISK_ABORTED',
            'verdict' => $verdict->verdict(),
            'client_ref_id' => $refRow,
            'contract' => $intent->contractLabel(),
            'block_reasons' => $verdict->blockReasons(),
            'journal_id' => $recordedJournalId,
        ]);

        return [
            'classification' => 'LIVE_RISK_ABORTED',
            'verdict' => $verdict,
            'ref' => null,
            'journal_id' => $recordedJournalId,
            'placement' => null,
        ];
    }

    private function refSummary(array $context): ?array
    {
        if (isset($context['existing_ref']) && is_array($context['existing_ref'])) {
            return $context['existing_ref'];
        }

        return null;
    }

    private function contextJournalId(array $context): ?int
    {
        $journalId = $context['journal_id'] ?? null;

        return is_numeric($journalId) ? (int) $journalId : null;
    }

    private function journal(OptionOrderIntent $intent, string $clientRefId, string $status, array $notes, array $extra = [], ?int $journalId = null): RobinhoodTradeJournal
    {
        $values = array_merge([
            'account_number' => (string) ($this->cfg['agentic_account'] ?? ''),
            'asset_class' => 'option',
            'symbol' => strtoupper($intent->underlyingSymbol),
            'underlying_symbol' => strtoupper($intent->underlyingSymbol),
            'option_id' => $intent->optionId,
            'chain_id' => $intent->chainId,
            'option_type' => $intent->optionType,
            'expiration_date' => $intent->expirationDate,
            'strike_price' => $intent->strikePrice,
            'position_effect' => $intent->positionEffect,
            'status' => $status,
            'intent' => $intent->isOpening()
                ? ($intent->optionType === 'call' ? 'long_call' : 'long_put')
                : ($intent->optionType === 'call' ? 'close_long_call' : 'close_long_put'),
            'side' => $intent->side,
            'order_type' => $intent->orderType,
            'time_in_force' => $intent->timeInForce,
            'market_hours' => $intent->marketHours,
            'client_ref_id' => $clientRefId,
            'quantity' => $intent->quantity,
            'limit_price' => $intent->limitPrice,
            'dollar_amount' => $intent->estimatedDebit(),
            'strategy_label' => $intent->strategyLabel,
            'structure_class' => 'single_leg_long',
            'scanner_payload' => ['intent_source' => $intent->source, 'logical_order_id' => $intent->logicalOrderId, 'strategy_metadata' => $intent->metadata],
            'notes' => $notes,
        ], $extra);

        if ($journalId !== null) {
            $journal = RobinhoodTradeJournal::query()->find($journalId);
            if ($journal instanceof RobinhoodTradeJournal) {
                if (isset($values['notes']) && is_array($values['notes'])) {
                    $values['notes'] = array_merge((array) ($journal->notes ?? []), $values['notes']);
                }
                if (isset($values['scanner_payload']) && is_array($values['scanner_payload'])) {
                    $executionSource = $values['scanner_payload']['intent_source'] ?? null;
                    unset($values['scanner_payload']['intent_source']);
                    $values['scanner_payload'] = array_merge((array) ($journal->scanner_payload ?? []), $values['scanner_payload']);
                    if (is_string($executionSource) && $executionSource !== '') {
                        $values['scanner_payload']['execution_intent_source'] = $executionSource;
                    }
                }
                $journal->forceFill($values)->save();

                return $journal->refresh();
            }
        }

        return RobinhoodTradeJournal::query()->updateOrCreate(['client_ref_id' => $clientRefId], $values);
    }
}
