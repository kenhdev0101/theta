<?php

declare(strict_types=1);

namespace App\Robinhood\Convex;

use App\Models\ConvexProbeRun;
use App\Models\HarnessOrderRef;
use App\Robinhood\Execution\OrderRefStore;
use App\Robinhood\Mcp\BrokerCreditExhausted;
use App\Robinhood\Mcp\PortfolioReader;
use App\Robinhood\Mcp\RobinhoodMcpClient;
use App\Robinhood\Options\OptionBrokerContext;
use App\Robinhood\Options\OptionContractResolver;
use App\Robinhood\Options\OptionExecutor;
use App\Robinhood\Options\OptionOrderIntent;
use App\Robinhood\Options\OptionRailPipeline;
use App\Robinhood\Telemetry\JsonlRecorder;
use App\Robinhood\Thesis\ThesisSpec;
use App\Robinhood\Thesis\ThesisSpecLeg;
use App\Robinhood\Thesis\ThesisSpecValidator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * One-contract thesis-spec probe. The desk owns wallet sizing; this class owns
 * order identity, broker-confirmed quantity, and a restart-safe exit.
 */
final class ConvexProbeLifecycle
{
    public const ENTRY_CREDIT_DEFERRED = 'entry_broker_credit_throttled_retry_later';

    private const STRATEGY = 'convex_thesis_probe';

    private const OPEN_STATES = ['queued', 'unconfirmed', 'confirmed', 'placed', 'pending', 'pending_cancel', 'pending_replace', 'partially_filled', 'open', 'new', 'accepted'];

    private const CANCEL_STATES = ['cancelled', 'canceled', 'rejected', 'expired', 'failed'];

    public function __construct(private readonly RobinhoodMcpClient $client, private readonly array $cfg, private readonly JsonlRecorder $recorder) {}

    public function stage(ThesisSpec $spec): ConvexProbeRun
    {
        $check = (new ThesisSpecValidator)->validate($spec);
        if ($check['blockers'] !== []) {
            throw new RuntimeException('spec blocked: '.implode('; ', $check['blockers']));
        }
        $legs = array_values(array_filter($spec->legs(), static fn (ThesisSpecLeg $leg): bool => $leg->isProbe()));
        if (count($legs) !== 1 || count($spec->legs()) !== 1) {
            throw new RuntimeException('mechanical probe requires exactly one probe leg; sibling size-up is a later thesis action');
        }
        $leg = $legs[0];
        if (! $leg->isOption() || $leg->direction() !== 'long' || $leg->moneynessMode() !== ThesisSpecLeg::MONEYNESS_DELTA
            || $leg->targetDelta() === null || $leg->fastExitMaxHoldMinutes() === null || $leg->fastExitMaxHoldMinutes() <= 0) {
            throw new RuntimeException('probe requires a long option, target delta, and fast_exit.max_hold_minutes');
        }
        if (! in_array($leg->sizingMode(), [ThesisSpecLeg::SIZING_NOTIONAL, ThesisSpecLeg::SIZING_BALANCE_FRACTION], true)) {
            throw new RuntimeException('one-contract probe supports notional or balance_fraction sizing');
        }
        if ($leg->scouts() !== null || $leg->priceStop() !== null || $leg->fastExitMaxHoldDte() !== null
            || array_diff(array_keys($leg->fastExit()), ['max_hold_minutes', 'profit_target_mult', 'hard_stop_pct', 'delta_take', 'delta_close']) !== []) {
            throw new RuntimeException('probe spec contains a sibling or exit rule this one-contract supervisor does not execute');
        }
        if ($leg->thesisStop() !== null && $leg->thesisStop() !== 'external_event:thesis_expired') {
            throw new RuntimeException('probe thesis_stop must be external_event:thesis_expired for mechanical supervision');
        }
        if ($leg->entryOffsetBps() !== 0.0 || $leg->entryMaxChaseBps() !== null) {
            throw new RuntimeException('probe uses one bounded limit attempt; offset and chase rules are unsupported');
        }
        $raw = $spec->toArray();
        if ($leg->entryType() === ThesisSpecLeg::ENTRY_BOUNDED_RESTING_LIMIT &&
            (! isset($raw['matrix_source']) || $leg->fastExitHardStopPct() === null)) {
            throw new RuntimeException('bounded resting entry requires matrix evidence and a thesis hard stop');
        }
        if (isset($raw['matrix_source'])) {
            ConvexMatrixAdmission::verify((array) $raw['matrix_source'], $leg, (string) data_get($this->cfg, 'convex.matrix_path', storage_path('harness/convex_feature_matrix.csv')));
        }
        $hash = hash('sha256', json_encode($raw, JSON_THROW_ON_ERROR));
        $existing = ConvexProbeRun::query()->where('thesis_id', $spec->thesisId())->first();
        if ($existing instanceof ConvexProbeRun) {
            if ($existing->spec_hash !== $hash) {
                throw new RuntimeException('thesis_id already staged with different immutable spec');
            }

            return $existing;
        }

        return ConvexProbeRun::query()->create([
            'thesis_id' => $spec->thesisId(), 'leg_id' => $leg->legId(),
            'account_number' => (string) $this->cfg['agentic_account'],
            'status' => 'staged', 'spec' => $raw, 'spec_hash' => $hash,
            'underlying_symbol' => $leg->symbol(),
            'option_type' => $leg->optionStructure() === ThesisSpecLeg::STRUCTURE_LONG_PUT ? 'put' : 'call',
        ])->refresh();
    }

    public function enter(ConvexProbeRun $run): ConvexProbeRun
    {
        $walletLock = Cache::lock('theta:convex-probe:entry-wallet:'.$run->account_number, 120);
        if (! $walletLock->get()) {
            throw new RuntimeException('another convex probe entry owns the wallet lock');
        }
        try {
            return $this->locked($run, function (ConvexProbeRun $run): ConvexProbeRun {
                if ($run->status !== 'staged') {
                    throw new RuntimeException('probe is not staged: '.$run->status);
                }
                // A resting or uncertain buy consumes potential cash and
                // underlying exposure even before a broker position appears.
                // Wait for broker reconciliation before admitting another leg.
                $unresolvedEntry = ConvexProbeRun::query()
                    ->where('account_number', $run->account_number)->where('id', '!=', $run->id)
                    ->whereIn('status', ['entry_pending', 'ambiguous'])
                    ->whereNotNull('entry_intent_key')->exists();
                if ($unresolvedEntry) {
                    return $this->mark($run, 'staged', 'unresolved_probe_entry_blocks_new_entry');
                }
                if ($run->thesis_expired_at !== null) {
                    return $this->mark($run, 'closed', 'thesis_expired_before_entry');
                }
                $currentBlockers = (new ThesisSpecValidator)->structuralBlockers(ThesisSpec::fromArray((array) $run->spec));
                if ($currentBlockers !== []) {
                    return $this->mark($run, 'staged', 'spec_no_longer_enterable: '.implode('; ', $currentBlockers));
                }
                $leg = $this->leg($run);
                $matrixRow = isset($run->spec['matrix_source'])
                    ? ConvexMatrixAdmission::verify((array) $run->spec['matrix_source'], $leg, (string) data_get($this->cfg, 'convex.matrix_path', storage_path('harness/convex_feature_matrix.csv')))
                    : null;
                if ($matrixRow !== null && ($leg->fastExitHardStopPct() === null || $leg->fastExitProfitTargetMult() === null)) {
                    return $this->mark($run, 'staged', 'matrix_entry_risk_targets_required');
                }
                $context = OptionBrokerContext::assemble($this->client, $this->cfg);
                foreach ((array) ($context['open_option_orders'] ?? []) as $order) {
                    if (! is_array($order) || ! in_array(strtolower((string) ($order['state'] ?? $order['status'] ?? '')), self::OPEN_STATES, true)) {
                        continue;
                    }
                    foreach ((array) ($order['legs'] ?? []) as $orderLeg) {
                        if (is_array($orderLeg) && ($orderLeg['side'] ?? null) === 'buy'
                            && ($orderLeg['position_effect'] ?? null) === 'open') {
                            return $this->mark($run, 'staged', 'unresolved_broker_option_entry_blocks_new_entry');
                        }
                    }
                }
                $settled = $context['settled_cash'] ?? null;
                $buyingPower = $context['buying_power'] ?? null;
                if (! is_numeric($settled) || ! is_numeric($buyingPower) || (float) $settled <= 0 || (float) $buyingPower <= 0) {
                    return $this->mark($run, 'staged', 'settled_cash_or_buying_power_unverified');
                }
                $available = min((float) $settled, (float) $buyingPower);
                $sizing = $leg->sizingMode() === ThesisSpecLeg::SIZING_BALANCE_FRACTION
                    ? $available * (float) $leg->balanceFraction()
                    : (float) $leg->targetUsd();
                if ($leg->maxUsd() !== null) {
                    $sizing = min($sizing, $leg->maxUsd());
                }
                $ceiling = min($available, $sizing);
                if ($ceiling < 1.0) {
                    return $this->mark($run, 'staged', 'one_contract_not_fundable');
                }

                $reservedOptionIds = ConvexProbeRun::query()
                    ->where('account_number', $run->account_number)->where('id', '!=', $run->id)
                    ->whereIn('status', ['entry_pending', 'held', 'exit_pending', 'ambiguous'])
                    ->whereNotNull('option_id')->pluck('option_id')->all();
                foreach ((array) ($context['open_option_positions'] ?? []) as $position) {
                    if ($this->positionQuantity($position) > 0) {
                        $reservedOptionIds[] = $this->positionOptionId($position);
                    }
                }
                $selection = (new ConvexProbeContractSelector($this->client, $this->probeConfig()))->select(
                    $leg->symbol(), (string) $run->option_type, (int) $leg->dteMin(), (int) $leg->dteMax(),
                    (float) $leg->targetDelta(), $ceiling, array_values(array_unique($reservedOptionIds)),
                    $matrixRow !== null ? $leg->fastExitHardStopPct() : null,
                    $matrixRow !== null ? $leg->fastExitProfitTargetMult() : null,
                    [
                        'probe_id' => $run->id, 'thesis_id' => $run->thesis_id,
                        'matrix_row_hash' => data_get($run->spec, 'matrix_source.row_sha256'),
                        'settled_cash' => $settled, 'buying_power' => $buyingPower,
                        'options_spent_today_debit' => $context['options_spent_today_debit'] ?? null,
                        'caps' => array_intersect_key((array) ($this->cfg['options'] ?? []), array_flip([
                            'per_play_max_debit', 'per_day_max_debit', 'per_underlying_max_debit',
                        ])),
                    ],
                );
                if ($selection['blockers'] !== []) {
                    return $this->mark($run, 'staged', implode('; ', $selection['blockers']));
                }
                $contract = $selection['contract'];
                $quote = $selection['quote'];
                if ($matrixRow !== null) {
                    if (($blocker = ConvexMatrixAdmission::contractBlocker($matrixRow, $contract, $quote)) !== null) {
                        return $this->mark($run, 'staged', $blocker);
                    }
                }
                foreach ((array) ($context['open_option_positions'] ?? []) as $position) {
                    if ($this->positionOptionId($position) === $contract['option_id'] && $this->positionQuantity($position) > 0) {
                        return $this->mark($run, 'staged', 'contract_already_held_outside_probe');
                    }
                }
                $limit = (float) ($quote['entry_limit'] ?? ceil((float) $quote['ask'] * 100) / 100);
                if ($limit * 100 > $ceiling) {
                    return $this->mark($run, 'staged', 'one_contract_exceeds_available_cash');
                }
                $intent = $this->intent($run, $contract, 'buy', $limit, 1);
                $run->forceFill([
                    'status' => 'entry_pending', 'option_id' => $contract['option_id'], 'chain_id' => $contract['chain_id'],
                    'expiration_date' => $contract['expiration_date'], 'strike_price' => $contract['strike_price'],
                    'entry_limit_price' => $limit, 'entry_intent_key' => $intent->intentKey(now()->toDateString()),
                    'entry_submitted_at' => now(), 'block_reason' => null,
                ])->save();

                try {
                    $result = $this->executor()->execute($intent, array_merge($context, [
                        'contract' => $contract, 'quote' => $quote,
                        'convex_matrix_source' => $run->spec['matrix_source'] ?? null,
                    ]));
                } catch (BrokerCreditExhausted $e) {
                    $this->recoverRef($run, 'entry');
                    $run->forceFill(['entry_submitted_at' => null])->save();
                    $run = $this->mark($run, 'staged', self::ENTRY_CREDIT_DEFERRED);
                    $this->record($run, 'entry_credit_deferred');

                    return $run;
                } catch (\Throwable $e) {
                    $this->recoverRef($run, 'entry');

                    return $this->mark($run, 'ambiguous', 'entry_placement_unknown_reconcile_before_retry');
                }
                if ($result['classification'] !== 'LIVE_POSTED_NOT_FILLED') {
                    // A deterministic rail/admission abort made no broker
                    // submission. Keep the durable ref for audit, but do not
                    // count this staged attempt as an entered contract.
                    $run->forceFill(['entry_submitted_at' => null])->save();

                    return $this->mark($run, 'staged', implode('; ', $result['verdict']->blockReasons()));
                }
                $run->forceFill([
                    'entry_client_ref_id' => $result['ref']->client_ref_id,
                    'entry_order_id' => $result['ref']->broker_order_id,
                ])->save();
                $this->record($run, 'entry_submitted');

                return $run->refresh();
            });
        } finally {
            $walletLock->release();
        }
    }

    public function expire(ConvexProbeRun $run, string $eventId, string $source, string $reason): ConvexProbeRun
    {
        $eventId = trim($eventId);
        $source = trim($source);
        $reason = trim($reason);
        if ($eventId === '' || $source === '' || $reason === '' || strlen($eventId) > 128 || strlen($source) > 128 || strlen($reason) > 255) {
            throw new RuntimeException('thesis_expired event requires bounded event_id, source, and reason');
        }

        return $this->locked($run, function (ConvexProbeRun $run) use ($eventId, $source, $reason): ConvexProbeRun {
            if ($run->thesis_expiry_event_id !== null) {
                if ($run->thesis_expiry_event_id !== $eventId || $run->thesis_expiry_source !== $source
                    || $run->thesis_expiry_reason !== $reason) {
                    throw new RuntimeException('probe already has a different immutable thesis_expired event');
                }

                return $run;
            }
            if ($run->status === 'closed') {
                throw new RuntimeException('probe is already broker-closed; expiry event cannot rewrite its exit');
            }
            $run->forceFill([
                'thesis_expired_at' => now(), 'thesis_expiry_event_id' => $eventId,
                'thesis_expiry_source' => $source, 'thesis_expiry_reason' => $reason,
                'status' => $run->status === 'staged' ? 'closed' : $run->status,
                'closed_at' => $run->status === 'staged' ? now() : $run->closed_at,
                'exit_reason' => $run->status === 'staged' ? 'thesis_expired_before_entry' : 'thesis_expired',
            ])->save();
            $this->record($run, 'thesis_expired');

            return $run->refresh();
        });
    }

    public function tick(ConvexProbeRun $run, bool $forceExit = false): ConvexProbeRun
    {
        return $this->locked($run, function (ConvexProbeRun $run) use ($forceExit): ConvexProbeRun {
            if (in_array($run->status, ['staged', 'closed'], true)) {
                return $run;
            }
            $orders = PortfolioReader::listFrom($this->client->getOptionOrders());
            $positions = PortfolioReader::listFrom($this->client->getOptionPositions(nonzero: true));
            $run->forceFill(['last_reconciled_at' => now()])->save();
            $this->recoverRef($run, 'entry');
            $this->recoverRef($run, 'exit');
            if ($run->exit_intent_key !== null && $run->exit_order_id === null) {
                return $this->mark($run, 'ambiguous', 'exit_placement_identity_unverified_reconcile_before_retry');
            }
            if ($run->entry_order_id === null) {
                return $this->mark($run, 'ambiguous', 'entry_order_id_unverified');
            }
            $entry = $this->findOrder($orders, $run->entry_order_id);
            if ($entry === null) {
                return $this->mark($run, 'ambiguous', 'entry_order_missing_from_broker_readback');
            }
            if (! $this->matchesOrderLeg($entry, (string) $run->option_id, 'buy', 'open')) {
                return $this->mark($run, 'ambiguous', 'entry_order_leg_conflicts_with_probe_ownership');
            }
            if (! is_numeric($entry['processed_quantity'] ?? null)) {
                return $this->mark($run, 'ambiguous', 'entry_processed_quantity_unverified');
            }
            $entryQty = $this->processed($entry);
            if ($entryQty > 1.0 || $entryQty < (float) $run->entry_filled_qty) {
                return $this->mark($run, 'ambiguous', 'entry_fill_quantity_conflict');
            }
            $run->forceFill(['entry_filled_qty' => $entryQty, 'entry_average_price' => $this->averagePrice($entry)])->save();
            $this->orderEvidence($run, 'entry', $entry);
            $entryState = strtolower((string) ($entry['state'] ?? ''));
            if (in_array($entryState, self::OPEN_STATES, true)) {
                $reason = $run->entry_cancel_reason;
                if ($reason === null && $run->thesis_expired_at !== null) {
                    $reason = 'thesis_expired';
                }
                if ($reason === null && $entryQty < 1.0 && isset($run->spec['matrix_source'])) {
                    $reason = $this->restingEntryBlocker($run);
                }
                if ($reason === null && $run->entry_submitted_at !== null
                    && $run->entry_submitted_at->diffInSeconds(now()) >= (int) data_get($this->cfg, 'convex.entry_rest_max_seconds', 180)) {
                    $reason = 'entry_rest_timeout';
                }
                if ($reason !== null) {
                    $retrySeconds = max(1, (int) data_get($this->cfg, 'convex.entry_cancel_retry_seconds', 30));
                    if ($run->entry_cancel_requested_at === null
                        || $run->entry_cancel_requested_at->diffInSeconds(now()) >= $retrySeconds) {
                        // Persist intent before the broker call. A crash or an
                        // uncertain response retries cancellation of this same
                        // order ID; neither case releases the wallet or position.
                        $run->forceFill(['entry_cancel_requested_at' => now(), 'entry_cancel_reason' => $reason])->save();
                        $this->client->cancelOptionOrder((string) $run->entry_order_id);
                        $this->record($run, 'entry_cancel_requested');
                    }

                    return $this->mark($run, 'entry_pending', 'entry_cancel_requested_await_broker_confirmation');
                }

                return $this->mark($run, 'entry_pending', $entryQty > 0 ? 'partial_entry_await_terminal_order' : $run->block_reason);
            }
            if ($entryQty <= 0) {
                if (in_array($entryState, self::CANCEL_STATES, true)) {
                    if ($this->matchingPositionQuantity($positions, (string) $run->option_id) !== 0.0) {
                        return $this->mark($run, 'ambiguous', 'cancelled_unfilled_entry_has_broker_position');
                    }
                    $run->forceFill(['status' => 'closed', 'closed_at' => now(),
                        'block_reason' => $run->entry_cancel_reason === null
                            ? 'entry_not_filled' : 'entry_not_filled:'.$run->entry_cancel_reason])->save();
                    $this->record($run, 'entry_terminal_without_fill');

                    return $run->refresh();
                }

                return $this->mark($run, 'ambiguous', 'entry_terminal_state_unrecognized');
            }
            if ($entryQty !== 1.0) {
                return $this->mark($run, 'ambiguous', 'non_integral_entry_fill_requires_broker_reconciliation');
            }

            $positionQty = $this->matchingPositionQuantity($positions, (string) $run->option_id);
            $ownedQty = $entryQty - (float) $run->exit_filled_qty;
            if ($run->exit_order_id === null && ($positionQty !== $ownedQty || $ownedQty < 0 || $ownedQty > 1)) {
                return $this->mark($run, 'ambiguous', 'broker_position_quantity_conflicts_with_probe_ownership');
            }
            if ($run->opened_at === null) {
                $opened = $this->firstExecutionAt($entry) ?? now();
                $minutes = (float) $this->leg($run)->fastExitMaxHoldMinutes();
                $run->forceFill(['opened_at' => $opened, 'exit_due_at' => $opened->copy()->addSeconds((int) round($minutes * 60))])->save();
            }

            if ($run->exit_order_id !== null) {
                $exit = $this->findOrder($orders, (string) $run->exit_order_id);
                if ($exit === null) {
                    return $this->mark($run, 'ambiguous', 'exit_order_missing_from_broker_readback');
                }
                if (! $this->matchesOrderLeg($exit, (string) $run->option_id, 'sell', 'close')) {
                    return $this->mark($run, 'ambiguous', 'exit_order_leg_conflicts_with_probe_ownership');
                }
                if (! is_numeric($exit['processed_quantity'] ?? null)) {
                    return $this->mark($run, 'ambiguous', 'exit_processed_quantity_unverified');
                }
                $exitQty = $this->processed($exit);
                if ($exitQty > $entryQty || $exitQty < (float) $run->exit_filled_qty) {
                    return $this->mark($run, 'ambiguous', 'exit_fill_quantity_conflict');
                }
                $run->forceFill(['exit_filled_qty' => $exitQty, 'exit_average_price' => $this->averagePrice($exit)])->save();
                $this->orderEvidence($run, 'exit', $exit);
                $positionQty = $this->matchingPositionQuantity($positions, (string) $run->option_id);
                if ($positionQty !== $entryQty - $exitQty) {
                    return $this->mark($run, 'ambiguous', 'broker_position_quantity_conflicts_with_probe_ownership');
                }
                if ($exitQty === $entryQty && $positionQty === 0.0 && ! in_array(strtolower((string) ($exit['state'] ?? '')), self::OPEN_STATES, true)) {
                    $run->forceFill(['status' => 'closed', 'closed_at' => now(), 'block_reason' => null])->save();
                    $this->record($run, 'closed_broker_confirmed');

                    return $run->refresh();
                }
                if ($exitQty > 0 && $exitQty < $entryQty) {
                    return $this->mark($run, 'ambiguous', 'partial_exit_requires_quantity_reconciliation');
                }
                $exitState = strtolower((string) ($exit['state'] ?? ''));
                if (in_array($exitState, self::OPEN_STATES, true)) {
                    if (($run->thesis_expired_at !== null
                        || ($run->exit_submitted_at !== null && $run->exit_submitted_at->diffInMinutes(now()) >= 2))
                            && $run->block_reason !== 'exit_cancel_requested_await_broker_confirmation') {
                        $this->client->cancelOptionOrder((string) $run->exit_order_id);

                        return $this->mark($run, 'exit_pending', 'exit_cancel_requested_await_broker_confirmation');
                    }

                    return $this->mark($run, 'exit_pending', $run->block_reason);
                }
                if (in_array($exitState, self::CANCEL_STATES, true) && $exitQty === 0.0) {
                    $run->forceFill(['status' => 'held', 'exit_order_id' => null, 'exit_client_ref_id' => null,
                        'exit_intent_key' => null, 'exit_submitted_at' => null, 'block_reason' => 'exit_cancel_confirmed_reprice_next_tick'])->save();
                } else {
                    return $this->mark($run, 'ambiguous', 'exit_terminal_state_unrecognized');
                }
            }

            if ($ownedQty === 0.0) {
                return $this->mark($run, 'ambiguous', 'position_zero_without_confirmed_exit');
            }
            $resolution = (new OptionContractResolver($this->client, $this->probeConfig()))->resolveExact(
                (string) $run->underlying_symbol, (string) $run->option_type,
                $run->expiration_date->toDateString(), (float) $run->strike_price,
            );
            if (is_array($resolution['contract'] ?? null)
                && (string) ($resolution['contract']['option_id'] ?? '') !== (string) $run->option_id) {
                return $this->mark($run, 'ambiguous', 'exit_contract_id_conflicts_with_owned_option');
            }
            $quote = $resolution['quote'] ?? null;
            $this->evidence($run, 'exit_quote_observed', ['quote' => is_array($quote) ? ConvexEvidence::quote($quote) : null]);
            if (! is_array($quote) || ($quote['stale'] ?? true) || ! is_numeric($quote['bid'] ?? null)) {
                return $this->mark($run, 'held', 'fresh_exit_bid_unavailable');
            }
            $exitReason = $run->thesis_expired_at !== null ? 'thesis_expired'
                : ($forceExit ? 'manual_exit' : self::exitReason($run, $quote));
            if ($exitReason === null) {
                return $this->mark($run, 'held', null);
            }
            if (! is_array($resolution['contract'] ?? null)) {
                return $this->mark($run, 'held', 'exit_contract_unverified');
            }
            $limit = floor((float) $quote['bid'] * 100) / 100;
            if ($limit < 0.01) {
                return $this->mark($run, 'held', 'exit_bid_below_minimum_tick');
            }
            $attempt = (int) $run->exit_attempt + 1;
            $intent = $this->intent($run, $resolution['contract'], 'sell', $limit, $attempt);
            $run->forceFill(['status' => 'exit_pending', 'exit_attempt' => $attempt,
                'exit_intent_key' => $intent->intentKey(now()->toDateString()), 'exit_submitted_at' => now(),
                'exit_reason' => $exitReason, 'block_reason' => null])->save();
            try {
                $context = array_merge(OptionBrokerContext::assemble($this->client, $this->cfg), [
                    'contract' => $resolution['contract'], 'quote' => $quote,
                ]);
                $result = $this->executor()->execute($intent, $context);
            } catch (BrokerCreditExhausted $e) {
                // Owned risk remains held. The next stewardship tick may
                // attempt a fresh bounded exit after checking broker truth.
                $run->forceFill(['exit_intent_key' => null, 'exit_client_ref_id' => null,
                    'exit_submitted_at' => null])->save();
                $run = $this->mark($run, 'held', 'exit_broker_credit_throttled_retry_later');
                $this->record($run, 'exit_credit_deferred');

                return $run;
            } catch (\Throwable $e) {
                $this->recoverRef($run, 'exit');

                return $this->mark($run, 'ambiguous', 'exit_placement_unknown_reconcile_before_retry');
            }
            if ($result['classification'] !== 'LIVE_POSTED_NOT_FILLED') {
                // The executor has explicitly returned without placing. Clear
                // only this attempt's pending marker, then let a later tick
                // create a fresh logical key for another bounded exit try.
                $run->forceFill(['exit_intent_key' => null, 'exit_submitted_at' => null])->save();

                return $this->mark($run, 'held', implode('; ', $result['verdict']->blockReasons()));
            }
            $run->forceFill(['exit_client_ref_id' => $result['ref']->client_ref_id,
                'exit_order_id' => $result['ref']->broker_order_id])->save();
            $this->record($run, 'exit_submitted');

            return $run->refresh();
        });
    }

    public static function exitTriggered(ConvexProbeRun $run, array $quote, ?Carbon $now = null): bool
    {
        return self::exitReason($run, $quote, $now) !== null;
    }

    public static function exitReason(ConvexProbeRun $run, array $quote, ?Carbon $now = null): ?string
    {
        $now ??= now();
        if ($run->thesis_expired_at !== null) {
            return 'thesis_expired';
        }
        if ($run->exit_due_at !== null && $now->greaterThanOrEqualTo($run->exit_due_at)) {
            return 'max_hold';
        }
        $leg = ThesisSpec::fromArray((array) $run->spec)->legs()[0] ?? null;
        if (! $leg instanceof ThesisSpecLeg) {
            return null;
        }
        if ($leg->timeStop() !== null && $now->copy()->setTimezone('America/New_York')->toDateString() >= $leg->timeStop()) {
            return 'time_stop';
        }
        $entry = (float) $run->entry_average_price;
        // The bid is the executable sell-to-close reference. Mid-mark triggers
        // can claim a gain or defer a stop that cannot be realized after spread.
        $exitBid = (float) ($quote['bid'] ?? 0);
        if ($entry > 0 && $exitBid > 0) {
            if (($multiple = $leg->fastExitProfitTargetMult()) !== null && $exitBid >= $entry * $multiple) {
                return 'profit_target';
            }
            if (($target = $leg->targetPct()) !== null && $exitBid >= $entry * (1 + $target / 100)) {
                return 'target_pct';
            }
            if (($stop = $leg->fastExitHardStopPct()) !== null && $exitBid <= $entry * (1 - $stop / 100)) {
                return 'hard_stop';
            }
        }
        if (($delta = $quote['delta'] ?? null) !== null) {
            if (($take = $leg->fastExitDeltaTake()) !== null && abs((float) $delta) >= $take) {
                return 'delta_take';
            }
            if (($close = $leg->fastExitDeltaClose()) !== null && abs((float) $delta) <= $close) {
                return 'delta_close';
            }
        }

        return null;
    }

    private function intent(ConvexProbeRun $run, array $contract, string $side, float $limit, int $attempt): OptionOrderIntent
    {
        return new OptionOrderIntent(
            underlyingSymbol: (string) $run->underlying_symbol,
            optionType: (string) $run->option_type,
            expirationDate: (string) $contract['expiration_date'],
            strikePrice: (float) $contract['strike_price'],
            side: $side, positionEffect: $side === 'buy' ? 'open' : 'close', quantity: 1,
            limitPrice: $limit, optionId: (string) $contract['option_id'],
            chainId: (string) ($contract['chain_id'] ?? ''),
            underlyingType: (string) ($contract['underlying_type'] ?? 'equity'),
            source: 'theta:convex-probe',
            logicalOrderId: $run->thesis_id.':'.$run->leg_id.':'.$side.':'.$attempt.':'.$contract['option_id'],
            strategyLabel: self::STRATEGY,
            metadata: [
                'thesis_id' => $run->thesis_id, 'leg_id' => $run->leg_id, 'spec_hash' => $run->spec_hash,
                'entry_mode' => $side === 'buy' && isset($run->spec['matrix_source']) ? 'convex_bounded_resting' : null,
                'hard_stop_pct' => $side === 'buy' ? $this->leg($run)->fastExitHardStopPct() : null,
                'profit_target_mult' => $side === 'buy' ? $this->leg($run)->fastExitProfitTargetMult() : null,
            ],
        );
    }

    private function executor(): OptionExecutor
    {
        return new OptionExecutor($this->client, new OptionRailPipeline, new OrderRefStore, $this->recorder, $this->probeConfig());
    }

    private function probeConfig(): array
    {
        $cfg = $this->cfg;
        // Reuse the convex data plane's measured quote age for this family.
        // The rest of the existing option rails and wallet configuration stay
        // exactly as supplied by the desk.
        $cfg['options']['min_quote_fresh_seconds'] = (int) data_get($cfg, 'convex.min_quote_fresh_seconds', 60);

        return $cfg;
    }

    /** Review a still-resting matrix-backed buy against its immutable thesis. */
    private function restingEntryBlocker(ConvexProbeRun $run): ?string
    {
        try {
            $dte = (int) Carbon::now('America/New_York')->startOfDay()->diffInDays(
                Carbon::parse($run->expiration_date->toDateString(), 'America/New_York')->startOfDay(), false,
            );
            $block = ConvexMatrixAdmission::currentBlocker(
                (array) $run->spec['matrix_source'], (string) $run->underlying_symbol, $dte,
                (string) data_get($this->cfg, 'convex.matrix_path', storage_path('harness/convex_feature_matrix.csv')),
            );
            if ($block !== null) {
                return 'resting_matrix_admit_superseded';
            }
        } catch (\Throwable) {
            return 'resting_matrix_unavailable';
        }

        try {
            $quote = (new ConvexQuoteReader($this->client, $this->probeConfig()))->forOptionId((string) $run->option_id);
        } catch (\Throwable) {
            return 'resting_quote_unavailable';
        }
        if ($quote === null) {
            return 'resting_quote_unavailable';
        }
        $this->evidence($run, 'resting_quote_observed', ['quote' => ConvexEvidence::quote($quote)]);
        if ($quote['stale']) {
            return 'resting_quote_stale';
        }
        if ($run->entry_limit_price === null || ! ConvexEntryPricing::limitAllowed(
            (float) $quote['bid'], (float) $quote['ask'], (float) $run->entry_limit_price,
            (float) $this->leg($run)->fastExitHardStopPct(),
            (float) data_get($this->cfg, 'convex.entry_repeg_cap_frac', 0.5),
            $this->leg($run)->fastExitProfitTargetMult(),
            (float) data_get($this->cfg, 'convex.round_trip_cost_budget_fraction', 0.5),
        )) {
            return 'resting_bid_outside_thesis_risk';
        }

        return null;
    }

    private function leg(ConvexProbeRun $run): ThesisSpecLeg
    {
        $leg = ThesisSpec::fromArray((array) $run->spec)->legs()[0] ?? null;
        if (! $leg instanceof ThesisSpecLeg) {
            throw new RuntimeException('stored probe spec has no leg');
        }

        return $leg;
    }

    private function locked(ConvexProbeRun $run, callable $callback): ConvexProbeRun
    {
        $lock = Cache::lock('theta:convex-probe:'.$run->id, 120);
        if (! $lock->get()) {
            throw new RuntimeException('probe tick already owns the lifecycle lock');
        }
        try {
            return $callback($run->refresh());
        } finally {
            $lock->release();
        }
    }

    private function recoverRef(ConvexProbeRun $run, string $phase): void
    {
        $key = $phase === 'entry' ? $run->entry_intent_key : $run->exit_intent_key;
        if ($key === null) {
            return;
        }
        $ref = HarnessOrderRef::query()->where('intent_key', $key)->first();
        if ($ref instanceof HarnessOrderRef) {
            $run->forceFill([
                $phase.'_client_ref_id' => $ref->client_ref_id,
                $phase.'_order_id' => $ref->broker_order_id ?: $run->getAttribute($phase.'_order_id'),
            ])->save();
        }
    }

    private function findOrder(array $orders, string $id): ?array
    {
        foreach ($orders as $order) {
            if (is_array($order) && (string) ($order['id'] ?? '') === $id) {
                return $order;
            }
        }

        return null;
    }

    private function processed(array $order): float
    {
        return round((float) ($order['processed_quantity'] ?? 0), 4);
    }

    private function matchesOrderLeg(array $order, string $optionId, string $side, string $effect): bool
    {
        $legs = $order['legs'] ?? null;
        if (! is_array($legs) || count($legs) !== 1 || ! is_array($legs[0])) {
            return false;
        }
        $leg = $legs[0];

        return (string) ($leg['option_id'] ?? '') === $optionId
            && strtolower((string) ($leg['side'] ?? '')) === $side
            && strtolower((string) ($leg['position_effect'] ?? '')) === $effect;
    }

    private function averagePrice(array $order): ?float
    {
        $numerator = 0.0;
        $denominator = 0.0;
        foreach ((array) ($order['legs'] ?? []) as $leg) {
            foreach ((array) ($leg['executions'] ?? []) as $fill) {
                $qty = (float) ($fill['quantity'] ?? 0);
                $price = (float) ($fill['price'] ?? 0);
                if ($qty > 0 && $price > 0) {
                    $numerator += $qty * $price;
                    $denominator += $qty;
                }
            }
        }

        return $denominator > 0 ? round($numerator / $denominator, 4) : null;
    }

    private function firstExecutionAt(array $order): ?Carbon
    {
        foreach ((array) ($order['legs'] ?? []) as $leg) {
            foreach ((array) ($leg['executions'] ?? []) as $fill) {
                if (is_string($fill['timestamp'] ?? null)) {
                    return Carbon::parse($fill['timestamp']);
                }
            }
        }

        return null;
    }

    private function matchingPositionQuantity(array $positions, string $optionId): float
    {
        $qty = 0.0;
        foreach ($positions as $position) {
            if (is_array($position) && $this->positionOptionId($position) === $optionId) {
                $qty += $this->positionQuantity($position);
            }
        }

        return round($qty, 4);
    }

    private function positionOptionId(array $position): string
    {
        return (string) ($position['option_id'] ?? data_get($position, 'option.id') ?? $position['instrument_id'] ?? '');
    }

    private function positionQuantity(array $position): float
    {
        return round((float) ($position['quantity'] ?? $position['quantity_available'] ?? 0), 4);
    }

    private function mark(ConvexProbeRun $run, string $status, ?string $reason): ConvexProbeRun
    {
        $changed = $run->status !== $status || $run->block_reason !== $reason;
        $run->forceFill(['status' => $status, 'block_reason' => $reason])->save();
        if ($changed) {
            $this->evidence($run, 'probe_decision');
        }

        return $run->refresh();
    }

    private function record(ConvexProbeRun $run, string $event): void
    {
        $this->evidence($run, $event);
        $this->recorder->record('convex_probe', [
            'event' => $event, 'thesis_id' => $run->thesis_id, 'leg_id' => $run->leg_id,
            'status' => $run->status, 'entry_order_id' => $run->entry_order_id,
            'exit_order_id' => $run->exit_order_id, 'entry_filled_qty' => $run->entry_filled_qty,
            'exit_filled_qty' => $run->exit_filled_qty,
            'exit_reason' => $run->exit_reason,
            'thesis_expiry_event_id' => $run->thesis_expiry_event_id,
            'thesis_expiry_source' => $run->thesis_expiry_source,
            'thesis_expiry_reason' => $run->thesis_expiry_reason,
            'entry_cancel_reason' => $run->entry_cancel_reason,
            'entry_cancel_requested_at' => $run->entry_cancel_requested_at?->toIso8601String(),
            'matrix_source' => data_get($run->spec, 'matrix_source'),
        ]);
    }

    private function orderEvidence(ConvexProbeRun $run, string $phase, array $order): void
    {
        $this->evidence($run, 'broker_order_observed', [
            'phase' => $phase, 'broker_order_id' => $order['id'] ?? null,
            'outcome' => $order['state'] ?? null, 'processed_quantity' => $order['processed_quantity'] ?? null,
            'created_at' => $order['created_at'] ?? null, 'last_transaction_at' => $order['last_transaction_at'] ?? null,
        ]);
    }

    private function evidence(ConvexProbeRun $run, string $event, array $extra = []): void
    {
        try {
            $entry = is_numeric($run->entry_average_price) ? (float) $run->entry_average_price : null;
            $exit = is_numeric($run->exit_average_price) ? (float) $run->exit_average_price : null;
            $realized = $event === 'closed_broker_confirmed' && $entry !== null && $entry > 0 && $exit !== null;
            ConvexEvidence::stamp($this->cfg, $event, [
                'probe_id' => $run->id, 'thesis_id' => $run->thesis_id, 'leg_id' => $run->leg_id,
                'matrix_row_hash' => data_get($run->spec, 'matrix_source.row_sha256'),
                'ticker' => $run->underlying_symbol, 'option_id' => $run->option_id,
                'option_type' => $run->option_type, 'expiration_date' => $run->expiration_date?->toDateString(),
                'strike_price' => $run->strike_price, 'status' => $run->status,
                'entry_order_id' => $run->entry_order_id, 'exit_order_id' => $run->exit_order_id,
                'entry_filled_qty' => $run->entry_filled_qty, 'exit_filled_qty' => $run->exit_filled_qty,
                'entry_average_price' => $entry, 'exit_average_price' => $exit,
                'entry_limit_price' => $run->entry_limit_price,
                'opened_at' => $run->opened_at?->toIso8601String(), 'closed_at' => $run->closed_at?->toIso8601String(),
                'blockers' => ConvexEvidence::blockers($run->block_reason),
                'entry_cancel_reason' => $run->entry_cancel_reason, 'exit_reason' => $run->exit_reason,
                'gross_pnl_usd' => $realized ? round(($exit - $entry) * 100 * (float) $run->exit_filled_qty, 4) : null,
                'gross_return_bps' => $realized ? round(($exit / $entry - 1) * 10000, 2) : null,
                // Fee completeness is not verified by this broker adapter.
                'fees_usd' => null, 'net_pnl_usd' => null, 'net_return_bps' => null, 'fee_status' => 'unverified',
                ...$extra,
            ]);
        } catch (\Throwable) {
            // Optional observations must not interfere with stewardship.
        }
    }
}
