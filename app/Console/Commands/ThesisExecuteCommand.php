<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\AssemblesHarnessConfig;
use App\Models\RobinhoodTradeJournal;
use App\Models\ThesisIntent;
use App\Robinhood\Execution\OrderRefStore;
use App\Robinhood\Mcp\OAuthTokenStore;
use App\Robinhood\Mcp\PortfolioReader;
use App\Robinhood\Mcp\RobinhoodMcpClient;
use App\Robinhood\Options\OptionBrokerContext;
use App\Robinhood\Options\OptionContractResolver;
use App\Robinhood\Options\OptionExecutor;
use App\Robinhood\Options\OptionOrderIntent;
use App\Robinhood\Options\OptionRailPipeline;
use App\Robinhood\Rails\SessionClock;
use App\Robinhood\Telemetry\JsonlRecorder;
use App\Robinhood\Thesis\ThesisEntryPricer;
use App\Robinhood\Thesis\ThesisLaneConfig;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Sleep;

/**
 * Open executor for the thesis-position lane (S5 of
 * docs/thesis-position-lane.md).
 *
 * The operator does not wake at 6:00 AM PT. This command owns the open:
 * it places the assembled slate play as a worked limit inside the live book,
 * re-pegs it against the live quote at a precommitted cadence, and shuts the
 * whole thing down at the window close. It never chases past the window —
 * that is the firm's no-chase playbook rule, encoded.
 *
 * APPROVAL-FIRST BY CONSTRUCTION: this command never reads `harness.mode`.
 * Activating the ThesisIntent is the operator's standing approval; the lane
 * flag and kill switch are the arm/disarm surface.
 *
 * THREE INVARIANTS THIS COMMAND EXISTS TO HOLD
 *
 * 1. NEVER TWO LIVE ORDERS FOR ONE PLAY. A re-peg takes an atomic
 *    `thesis_repegging` claim (`withoutOverlapping` only guards the scheduler
 *    against itself — a manual run races it), then cancel-then-replace, and
 *    the cancel must be PROVED from broker truth: the broker must advertise
 *    `cancel_option_order` (fail closed when the versionless tool surface
 *    cannot be read), the cancel is polled up to CANCEL_VERIFY_ATTEMPTS times
 *    because Robinhood cancels are asynchronous, and only a dead state clears
 *    a re-place. `filled` never re-places. Anything else is ambiguous and
 *    stops the re-peg.
 *
 * 2. NEVER STOP SUPERVISING A POSSIBLY-LIVE ORDER. `blocked` means "this lane
 *    will not re-peg or re-place this play" — it never means "walk away". A
 *    gfd entry order left unattended fills at 15:59 on a play the no-chase
 *    rule expired hours earlier. Blocked rows stay in SUPERVISED_STATUSES,
 *    get a cancel retry every tick, and leave the lane only when broker truth
 *    shows a dead or filled order.
 *
 * 3. NEVER PLACE OUTSIDE THE WINDOW. The window is re-read immediately before
 *    every submit, not once per tick — resolver, broker-context and review
 *    I/O sit between the two, and a placement that starts inside the window
 *    must not land outside it.
 */
class ThesisExecuteCommand extends Command
{
    use AssemblesHarnessConfig;

    private const CANCEL_TOOL = 'cancel_option_order';

    private const STALE_CLAIM_MINUTES = 5;

    /** Robinhood cancels are async: the first readback routinely still says queued. */
    private const CANCEL_VERIFY_ATTEMPTS = 3;

    private const CANCEL_VERIFY_DELAY_MS = 2000;

    /** Consecutive pre-place failures before a play with no live order is retired. */
    private const MAX_PLACE_ATTEMPTS = 5;

    protected $signature = 'theta:thesis-execute
        {--limit= : Maximum fresh entries to place this run; defaults to harness.thesis.max_open_positions}';

    protected $description = 'Work thesis-lane entries at the open: place, re-peg within bounds, cancel at the window close';

    public function handle(): int
    {
        $cfg = $this->assembleConfig();
        $lane = ThesisLaneConfig::lane($cfg);
        $recorder = JsonlRecorder::fromConfig($cfg);

        if (! (bool) ($lane['enabled'] ?? false)) {
            $this->warn('thesis executor disarmed: thesis lane disabled');

            return self::SUCCESS;
        }

        if ((bool) ($cfg['kill_switch'] ?? true)) {
            $this->warn('thesis executor disarmed: kill switch engaged');

            return self::SUCCESS;
        }

        if (! OAuthTokenStore::fromConfig($cfg)->hasToken()) {
            $this->error('blocker: oauth token absent');

            return self::FAILURE;
        }

        if (! (new SessionClock)->isRegularHoursOpen()) {
            $this->info('thesis executor idle: regular options session closed');

            return self::SUCCESS;
        }

        $windowClose = ThesisLaneConfig::resolveWindowClose($cfg);
        if ($windowClose['clamped']) {
            $this->warn(sprintf(
                'thesis entry window %s clamped to %s: %s',
                $windowClose['configured'] === '' ? '(unset)' : $windowClose['configured'],
                $windowClose['value'],
                (string) $windowClose['reason'],
            ));
            $recorder->record('thesis_execution', array_merge([
                'event' => 'window_close_clamped',
                'classification' => 'UNKNOWN_EXECUTION_STATE',
            ], $windowClose));
        }

        $withinWindow = ThesisLaneConfig::withinEntryWindow($cfg);
        $client = RobinhoodMcpClient::fromConfig($cfg, $recorder);
        $cancelCapable = $this->cancelCapable($client);

        $this->recoverStaleClaims();

        $tally = ['placed' => 0, 'repegged' => 0, 'expired' => 0, 'blocked' => 0];

        // Rows the supervision pass already acted on. A play that supervision
        // returned to placeable (its prior order proven dead) must wait for
        // the next tick rather than being re-attempted — and re-reviewed —
        // twice inside one run.
        $touched = [];

        foreach ($this->supervisedRows() as $row) {
            $touched[] = $row->id;
            $this->tally($tally, $this->superviseRow($row, $cfg, $client, $recorder, $cancelCapable, $withinWindow));
        }

        if ($withinWindow) {
            $limit = $this->placementLimit($cfg);
            if ($limit <= 0) {
                $this->warn('thesis executor: no fresh entries — harness.thesis.max_open_positions is not configured');
            }
            foreach ($this->placeableRows($limit, $touched) as $row) {
                $this->tally($tally, $this->placeFreshEntry($row, $cfg, $client, $recorder));
            }
        } else {
            foreach ($this->placeableRows(null, $touched) as $row) {
                $this->markExpired($row, $recorder, 'entry_window_closed_unplaced');
                $tally['expired']++;
            }
        }

        $this->info(sprintf(
            'thesis executor complete: window=%s placed=%d repegged=%d expired=%d blocked=%d',
            $withinWindow ? 'open' : 'closed',
            $tally['placed'],
            $tally['repegged'],
            $tally['expired'],
            $tally['blocked'],
        ));

        return self::SUCCESS;
    }

    /** @param array<string,int> $tally */
    private function tally(array &$tally, string $outcome): void
    {
        if (array_key_exists($outcome, $tally)) {
            $tally[$outcome]++;
        }
    }

    // ── Supervision ──────────────────────────────────────────────────────

    /**
     * One decision per supervised row. Order matters: a blocked play and a
     * stood-down thesis both outrank the re-peg cadence, and a play mid-re-peg
     * in another invocation is never touched.
     */
    private function superviseRow(
        RobinhoodTradeJournal $row,
        array $cfg,
        RobinhoodMcpClient $client,
        JsonlRecorder $recorder,
        bool $cancelCapable,
        bool $withinWindow,
    ): string {
        if (is_array(data_get($row->notes, 'thesis_entry.blocked'))) {
            return $this->superviseBlocked($row, $client, $recorder, $cancelCapable);
        }

        if ($row->status === ThesisLaneConfig::STATUS_REPEGGING) {
            // Another invocation owns the critical section. Stale claims are
            // recovered by recoverStaleClaims(), never stolen mid-flight.
            $this->record($recorder, $row, 'repeg_in_flight', 'LIVE_POSTED_NOT_FILLED', [
                'reason' => 'repeg_claim_held_elsewhere',
            ]);

            return 'working';
        }

        // The operator parking or closing a thesis is a kill surface: it must
        // reach a live entry order on the very next tick, ahead of the reprice
        // cadence and regardless of the window.
        $thesis = $this->thesisFor($row);
        if (! $thesis instanceof ThesisIntent || $thesis->status !== ThesisIntent::STATUS_ACTIVE) {
            return $this->standDown($row, $client, $recorder, $cancelCapable);
        }

        return $withinWindow
            ? $this->workRestingOrder($row, $cfg, $client, $recorder, $cancelCapable, $thesis)
            : $this->closeOutRestingOrder($row, $client, $recorder, $cancelCapable, 'entry_window_closed');
    }

    /**
     * A blocked play never re-pegs again, but the lane keeps resolving its
     * order until broker truth says there is no exposure left.
     */
    private function superviseBlocked(RobinhoodTradeJournal $row, RobinhoodMcpClient $client, JsonlRecorder $recorder, bool $cancelCapable): string
    {
        $brokerOrderId = (string) ($row->broker_order_id ?? '');
        if ($brokerOrderId === '') {
            return $this->adoptOrphanOrder($row, $client, $recorder);
        }

        $readback = $this->readOrderState($client, $brokerOrderId);

        if ($readback['state'] === 'dead') {
            $this->markExpired($row, $recorder, 'blocked_order_confirmed_dead', [
                'broker_order_id' => $brokerOrderId,
                'broker_state' => $readback['broker_state'] ?? null,
            ]);

            return 'expired';
        }

        if ($readback['state'] === 'filled') {
            $this->recordFillDuringCancel($row, $recorder, $readback, $brokerOrderId);

            return 'working';
        }

        if ($cancelCapable) {
            $cancel = $this->cancelAndVerify($client, $brokerOrderId);
            if ($cancel['state'] === 'cancelled') {
                $this->markExpired($row, $recorder, 'blocked_order_cancel_retry_confirmed', [
                    'broker_order_id' => $brokerOrderId,
                    'broker_state' => $cancel['broker_state'] ?? null,
                ]);

                return 'expired';
            }
            if ($cancel['state'] === 'filled') {
                $this->recordFillDuringCancel($row, $recorder, $cancel, $brokerOrderId);

                return 'working';
            }
        }

        $this->record($recorder, $row, 'blocked_supervised', 'UNKNOWN_EXECUTION_STATE', [
            'reason' => (string) (data_get($row->notes, 'thesis_entry.blocked.reason') ?? 'blocked'),
            'broker_order_id' => $brokerOrderId,
            'broker_state' => $readback['broker_state'] ?? null,
            'cancel_capable' => $cancelCapable,
        ]);
        $this->warn("thesis play {$row->id}: blocked with a live order — supervising");

        return 'blocked';
    }

    /**
     * A placement whose outcome was never confirmed leaves no order id to
     * chase. Look for a matching open order on the same contract and adopt it
     * so normal supervision can cancel it. Absence is never treated as proof:
     * the play stays blocked for reconciliation.
     */
    private function adoptOrphanOrder(RobinhoodTradeJournal $row, RobinhoodMcpClient $client, JsonlRecorder $recorder): string
    {
        $optionId = (string) ($row->option_id ?? '');

        try {
            $orders = PortfolioReader::listFrom($client->getOptionOrders());
        } catch (\Throwable $e) {
            $this->record($recorder, $row, 'blocked_supervised', 'UNKNOWN_EXECUTION_STATE', [
                'reason' => 'orphan_scan_failed: '.$e->getMessage(),
            ]);

            return 'blocked';
        }

        foreach ($orders as $order) {
            if (! is_array($order)) {
                continue;
            }
            $state = strtolower((string) ($order['state'] ?? $order['status'] ?? ''));
            if (in_array($state, ThesisLaneConfig::DEAD_ORDER_STATES, true) || $state === '') {
                continue;
            }
            foreach ((array) ($order['legs'] ?? []) as $leg) {
                if (! is_array($leg) || (string) ($leg['option_id'] ?? '') !== $optionId || $optionId === '') {
                    continue;
                }

                $row->forceFill(['broker_order_id' => (string) ($order['id'] ?? '')])->save();
                $this->record($recorder, $row, 'blocked_supervised', 'UNKNOWN_EXECUTION_STATE', [
                    'reason' => 'orphan_order_adopted',
                    'broker_order_id' => $order['id'] ?? null,
                    'broker_state' => $state,
                ]);

                return 'blocked';
            }
        }

        $this->record($recorder, $row, 'blocked_supervised', 'UNKNOWN_EXECUTION_STATE', [
            'reason' => 'orphan_order_not_found',
            'option_id' => $optionId,
            'requires_operator_reconcile' => true,
        ]);

        return 'blocked';
    }

    /** The operator parked or closed the thesis: kill the live entry order. */
    private function standDown(RobinhoodTradeJournal $row, RobinhoodMcpClient $client, JsonlRecorder $recorder, bool $cancelCapable): string
    {
        return $this->closeOutRestingOrder($row, $client, $recorder, $cancelCapable, 'thesis_stood_down');
    }

    // ── Working (resting) order ──────────────────────────────────────────

    private function workRestingOrder(
        RobinhoodTradeJournal $row,
        array $cfg,
        RobinhoodMcpClient $client,
        JsonlRecorder $recorder,
        bool $cancelCapable,
        ThesisIntent $thesis,
    ): string {
        $brokerOrderId = (string) ($row->broker_order_id ?? '');
        if ($brokerOrderId === '') {
            $this->markBlocked($row, $recorder, 'broker_order_id_missing');

            return 'blocked';
        }

        $placedAt = $this->placedAt($row);
        if ($placedAt === null) {
            $this->markBlocked($row, $recorder, 'placement_time_unknown', ['broker_order_id' => $brokerOrderId]);

            return 'blocked';
        }

        $restingSeconds = (int) abs($placedAt->diffInSeconds(now()));
        $repriceSeconds = ThesisLaneConfig::repriceSeconds($cfg);
        if ($restingSeconds < $repriceSeconds) {
            $this->record($recorder, $row, 'working', 'LIVE_POSTED_NOT_FILLED', [
                'reason' => 'inside_reprice_window',
                'resting_seconds' => $restingSeconds,
                'reprice_seconds' => $repriceSeconds,
                'broker_order_id' => $brokerOrderId,
            ]);

            return 'working';
        }

        $perPlayMaxDebit = $this->perPlayMaxDebit($row, $thesis, $cfg);
        $laneCfg = ThesisLaneConfig::railsConfig($cfg, $perPlayMaxDebit);

        $resolution = $this->resolve($row, $client, $laneCfg);
        if ($resolution === null || $resolution['blockers'] !== []) {
            // The resting order stays exactly where it is — a quote or
            // resolver hiccup is never a reason to create order churn.
            $this->record($recorder, $row, 'repeg_skipped', 'LIVE_POSTED_NOT_FILLED', [
                'reason' => $resolution === null
                    ? 'contract_unresolved'
                    : 'contract_blocked: '.implode('; ', $resolution['blockers']),
                'broker_order_id' => $brokerOrderId,
            ]);

            return 'working';
        }

        $quote = $resolution['quote'];
        $anchorMid = $this->anchorMid($row, $quote, reanchor: false);
        $ceiling = ThesisEntryPricer::chaseCeiling($anchorMid, ThesisLaneConfig::maxChaseBps($cfg));
        $priced = ThesisEntryPricer::derive($quote, $this->offsetBps($row, $thesis), $ceiling);

        if ($priced['limit'] === null) {
            $this->record($recorder, $row, 'repeg_skipped', 'LIVE_POSTED_NOT_FILLED', [
                'reason' => (string) ($priced['reason'] ?? 'entry_limit_underivable'),
                'anchor_mid' => $anchorMid,
                'chase_ceiling' => $ceiling,
                'broker_order_id' => $brokerOrderId,
            ]);

            return 'working';
        }

        $newLimit = (float) $priced['limit'];
        $currentLimit = round((float) ($row->limit_price ?? 0.0), 2);
        if (abs($newLimit - $currentLimit) < 0.005) {
            $this->record($recorder, $row, 'repeg_skipped', 'LIVE_POSTED_NOT_FILLED', [
                'reason' => 'repeg_noop_same_limit',
                'limit_price' => $currentLimit,
                'broker_order_id' => $brokerOrderId,
            ]);

            return 'working';
        }

        if (! $cancelCapable) {
            $this->record($recorder, $row, 'repeg_skipped', 'LIVE_POSTED_NOT_FILLED', [
                'reason' => 'cancel_capability_unavailable',
                'required_tool' => self::CANCEL_TOOL,
                'broker_order_id' => $brokerOrderId,
            ]);

            return 'working';
        }

        // MUTEX. Everything above is read-only; from here the play is cancelled
        // and replaced, and exactly one invocation may be inside.
        if (! $this->claimRepeg($row, $brokerOrderId)) {
            $this->record($recorder, $row, 'repeg_skipped', 'LIVE_POSTED_NOT_FILLED', [
                'reason' => 'repeg_claim_lost',
                'broker_order_id' => $brokerOrderId,
            ]);

            return 'working';
        }

        $cancel = $this->cancelAndVerify($client, $brokerOrderId);

        if ($cancel['state'] === 'error') {
            $this->releaseRepegClaim($row);
            $this->record($recorder, $row, 'repeg_skipped', 'LIVE_POSTED_NOT_FILLED', array_merge($cancel, [
                'reason' => 'repeg_cancel_failed',
                'broker_order_id' => $brokerOrderId,
            ]));

            return 'working';
        }

        if ($cancel['state'] === 'filled') {
            $this->releaseRepegClaim($row);
            $this->recordFillDuringCancel($row, $recorder, $cancel, $brokerOrderId);

            return 'working';
        }

        if ($cancel['state'] !== 'cancelled') {
            $this->markBlocked($row, $recorder, 'cancel_unconfirmed', array_merge($cancel, [
                'broker_order_id' => $brokerOrderId,
            ]));

            return 'blocked';
        }

        $this->record($recorder, $row, 'cancelled', 'LIVE_CANCELLED', [
            'reason' => 'repeg_cancel_confirmed',
            'broker_order_id' => $brokerOrderId,
            'broker_state' => $cancel['broker_state'] ?? null,
            'from_limit_price' => $currentLimit,
            'to_limit_price' => $newLimit,
        ]);

        return $this->submit(
            $row, $cfg, $laneCfg, $client, $recorder, $resolution, $newLimit, 'repegged',
            priorDebit: (float) ($row->dollar_amount ?? 0.0),
            priorOrderConfirmedDead: true,
        ) ? 'repegged' : 'blocked';
    }

    /**
     * Cancel a resting entry order and retire the play. Used both at the
     * window close and when the operator stands the thesis down mid-window.
     */
    private function closeOutRestingOrder(
        RobinhoodTradeJournal $row,
        RobinhoodMcpClient $client,
        JsonlRecorder $recorder,
        bool $cancelCapable,
        string $reason,
    ): string {
        $brokerOrderId = (string) ($row->broker_order_id ?? '');
        if ($brokerOrderId === '') {
            $this->markBlocked($row, $recorder, 'broker_order_id_missing', ['close_out_reason' => $reason]);

            return 'blocked';
        }

        if (! $cancelCapable) {
            // Blocked, but STILL SUPERVISED: the next tick re-reads the order
            // and retries the cancel if the tool surface comes back.
            $this->markBlocked($row, $recorder, 'cancel_capability_unavailable', [
                'required_tool' => self::CANCEL_TOOL,
                'broker_order_id' => $brokerOrderId,
                'close_out_reason' => $reason,
            ]);

            return 'blocked';
        }

        $cancel = $this->cancelAndVerify($client, $brokerOrderId);

        if ($cancel['state'] === 'filled') {
            $this->recordFillDuringCancel($row, $recorder, $cancel, $brokerOrderId);

            return 'working';
        }

        if ($cancel['state'] === 'error') {
            // Retryable and still live: the next supervision tick tries again
            // rather than abandoning a working order.
            $this->record($recorder, $row, 'repeg_skipped', 'LIVE_POSTED_NOT_FILLED', array_merge($cancel, [
                'reason' => $reason.'_cancel_failed',
                'broker_order_id' => $brokerOrderId,
            ]));

            return 'working';
        }

        if ($cancel['state'] !== 'cancelled') {
            $this->markBlocked($row, $recorder, 'cancel_unconfirmed', array_merge($cancel, [
                'broker_order_id' => $brokerOrderId,
                'close_out_reason' => $reason,
            ]));

            return 'blocked';
        }

        $this->markExpired($row, $recorder, $reason, [
            'broker_order_id' => $brokerOrderId,
            'broker_state' => $cancel['broker_state'] ?? null,
        ]);

        return 'expired';
    }

    // ── Fresh entry ──────────────────────────────────────────────────────

    private function placeFreshEntry(RobinhoodTradeJournal $row, array $cfg, RobinhoodMcpClient $client, JsonlRecorder $recorder): string
    {
        if (! $this->claimRow($row)) {
            $this->line("thesis play {$row->id}: skipped (already claimed)");

            return 'skipped';
        }

        $thesis = $this->thesisFor($row);
        if (! $thesis instanceof ThesisIntent || ! $thesis->isEnterable()) {
            $this->releaseClaim($row);
            $this->record($recorder, $row, 'place_blocked', 'UNKNOWN_EXECUTION_STATE', ['reason' => 'thesis_not_enterable']);
            $this->warn("thesis play {$row->id}: thesis no longer enterable");

            return 'skipped';
        }

        $perPlayMaxDebit = $this->perPlayMaxDebit($row, $thesis, $cfg);
        $laneCfg = ThesisLaneConfig::railsConfig($cfg, $perPlayMaxDebit);

        $resolution = $this->resolve($row, $client, $laneCfg);
        if ($resolution === null) {
            $this->releaseClaim($row);
            $this->record($recorder, $row, 'place_blocked', 'UNKNOWN_EXECUTION_STATE', ['reason' => 'contract_unresolved']);

            return 'skipped';
        }
        if ($resolution['blockers'] !== []) {
            $this->releaseClaim($row);
            $this->record($recorder, $row, 'place_blocked', 'LIVE_RISK_ABORTED', [
                'reason' => 'contract_blocked: '.implode('; ', $resolution['blockers']),
            ]);

            return 'skipped';
        }

        $quote = $resolution['quote'];
        $anchorMid = $this->anchorMid($row, $quote);
        $priced = ThesisEntryPricer::derive(
            $quote,
            $this->offsetBps($row, $thesis),
            ThesisEntryPricer::chaseCeiling($anchorMid, ThesisLaneConfig::maxChaseBps($cfg)),
        );

        if ($priced['limit'] === null) {
            $this->releaseClaim($row);
            $this->record($recorder, $row, 'place_blocked', 'LIVE_RISK_ABORTED', [
                'reason' => 'entry_limit_underivable: '.(string) ($priced['reason'] ?? 'unknown'),
                'anchor_mid' => $anchorMid,
                'quote' => $quote,
            ]);
            $this->warn("thesis play {$row->id}: entry limit underivable ({$priced['reason']})");

            return 'skipped';
        }

        return $this->submit($row, $cfg, $laneCfg, $client, $recorder, $resolution, (float) $priced['limit'], 'placed')
            ? 'placed'
            : 'blocked';
    }

    // ── Placement spine ──────────────────────────────────────────────────

    /**
     * Place (or re-place) through the shared option execution spine:
     * rails -> persisted ref -> broker review -> rails -> place -> journal.
     *
     * @param  array{contract: array<string,mixed>, quote: array<string,mixed>, blockers: list<string>}  $resolution
     * @param  float  $priorDebit  debit this row already contributes to today's
     *                             journal-derived gross-entry total. On a re-peg the row is
     *                             already working, so leaving it in would make the play
     *                             compete against its own budget.
     * @param  bool  $priorOrderConfirmedDead  the previous order for this row is
     *                                         proven dead at the broker, so a failure here leaves NO
     *                                         live exposure and the row can safely return to placeable.
     */
    private function submit(
        RobinhoodTradeJournal $row,
        array $cfg,
        array $laneCfg,
        RobinhoodMcpClient $client,
        JsonlRecorder $recorder,
        array $resolution,
        float $limit,
        string $event,
        float $priorDebit = 0.0,
        bool $priorOrderConfirmedDead = false,
    ): bool {
        // Re-read the window HERE, not at tick start: resolver, broker-context
        // and review I/O sit in between and can span the close.
        if (! ThesisLaneConfig::withinEntryWindow($cfg)) {
            $this->abandonSubmit($row, $recorder, 'entry_window_closed_before_submit', $priorOrderConfirmedDead);

            return false;
        }

        $contract = $resolution['contract'];
        $attempt = $this->nextAttempt($row);

        $intent = new OptionOrderIntent(
            underlyingSymbol: strtoupper((string) ($row->underlying_symbol ?: $row->symbol)),
            optionType: (string) $row->option_type,
            expirationDate: (string) ($row->expiration_date?->toDateString() ?? $contract['expiration_date']),
            strikePrice: (float) $row->strike_price,
            side: 'buy',
            positionEffect: 'open',
            quantity: 1,
            limitPrice: $limit,
            optionId: (string) $contract['option_id'],
            chainId: $contract['chain_id'] ?? null,
            underlyingType: $contract['underlying_type'] ?? 'equity',
            // The attempt counter is part of the ref identity. Without it a
            // re-peg back to a previously used price would hash to the same
            // intent_key, return the old `placed` ref, and abort at the
            // duplicate gate AFTER the old order was already cancelled.
            source: "theta:thesis-execute:journal:{$row->id}:attempt:{$attempt}",
        );

        try {
            $context = OptionBrokerContext::assemble($client, $cfg);
        } catch (\Throwable $e) {
            $this->abandonSubmit($row, $recorder, 'broker_truth_blocker: '.$e->getMessage(), $priorOrderConfirmedDead);

            return false;
        }

        if ($priorDebit > 0.0 && is_numeric($context['options_spent_today_debit'] ?? null)) {
            $context['options_spent_today_debit'] = round(max(0.0, (float) $context['options_spent_today_debit'] - $priorDebit), 2);
        }

        $context['contract'] = $contract;
        $context['quote'] = $resolution['quote'];
        $context['journal_id'] = $row->id;

        // Last gate before the broker write path. The broker-context read
        // above is a live round trip, so the window is checked again here
        // rather than trusting the check at the top of this method.
        if (! ThesisLaneConfig::withinEntryWindow($cfg)) {
            $this->abandonSubmit($row, $recorder, 'entry_window_closed_before_submit', $priorOrderConfirmedDead);

            return false;
        }

        $executor = new OptionExecutor($client, new OptionRailPipeline, new OrderRefStore, $recorder, $laneCfg);

        try {
            // requireMarketableBuy: false — a thesis entry is worked inside
            // the live book, not lifted at the ask (see OptionRailPipeline).
            $result = $executor->execute($intent, $context, requireMarketableBuy: false);
        } catch (\Throwable $e) {
            // A throw around the place call is NOT proof the order was
            // rejected: a timeout after the broker accepted it looks the same.
            $this->markUnconfirmedPlacement($row, $recorder, $e->getMessage(), $limit);

            return false;
        }

        if ($result['classification'] !== 'LIVE_POSTED_NOT_FILLED') {
            // Every rail abort happens BEFORE the place call, so no order
            // exists from this attempt.
            $this->abandonSubmit($row, $recorder, 'rail_block: '.$result['verdict']->verdict(), $priorOrderConfirmedDead, [
                'block_reasons' => $result['verdict']->blockReasons(),
                'limit_price' => $limit,
            ]);

            return false;
        }

        // The executor stamps the ref and the broker order id onto this exact
        // journal row; read them back from there rather than from the result
        // bag so the durable record is the single source.
        $row->refresh();
        $brokerOrderId = (string) ($row->broker_order_id ?? '');
        $clientRefId = (string) ($row->client_ref_id ?? '');

        $this->stampPlacement($row, $limit, $brokerOrderId ?: null, $clientRefId ?: null, $event);
        $this->record($recorder, $row, $event, 'LIVE_POSTED_NOT_FILLED', [
            'limit_price' => $limit,
            'estimated_debit' => $intent->estimatedDebit(),
            'client_ref_id' => $clientRefId ?: null,
            'broker_order_id' => $brokerOrderId ?: null,
            'attempt' => $attempt,
            'contract' => $intent->contractLabel(),
        ]);
        $this->info(sprintf('thesis play %d: %s %s @ %0.2f', $row->id, $event, $intent->contractLabel(), $limit));

        return true;
    }

    /**
     * A submit that failed before any order could exist. There is provably no
     * live exposure, so the play returns to a placeable lane status instead of
     * dying in `risk_aborted` — bounded by MAX_PLACE_ATTEMPTS so a permanently
     * blocked play cannot churn the broker every minute.
     */
    private function abandonSubmit(RobinhoodTradeJournal $row, JsonlRecorder $recorder, string $reason, bool $priorOrderConfirmedDead, array $detail = []): void
    {
        $row->refresh();
        $notes = (array) ($row->notes ?? []);
        $entry = (array) ($notes['thesis_entry'] ?? []);

        $failed = (int) ($entry['place_attempts_failed'] ?? 0) + 1;
        $entry['place_attempts_failed'] = $failed;
        $entry['last_place_block'] = array_merge(['reason' => $reason, 'at' => now()->toISOString()], $detail);
        unset($entry['blocked']);

        $updates = [
            'strategy_label' => ThesisLaneConfig::STRATEGY_LABEL,
        ];

        if ($priorOrderConfirmedDead) {
            $prior = (array) ($entry['prior_broker_order_ids'] ?? []);
            if (($row->broker_order_id ?? '') !== '') {
                $prior[] = (string) $row->broker_order_id;
            }
            $entry['prior_broker_order_ids'] = array_values(array_unique($prior));
            $updates['broker_order_id'] = null;
        }

        if ($failed >= self::MAX_PLACE_ATTEMPTS) {
            $notes['thesis_entry'] = $entry;
            $row->forceFill(array_merge($updates, ['notes' => $notes]))->save();
            $this->markExpired($row, $recorder, 'place_attempts_exhausted', [
                'attempts' => $failed,
                'last_reason' => $reason,
            ]);

            return;
        }

        $notes['thesis_entry'] = $entry;
        $row->forceFill(array_merge($updates, [
            'status' => ThesisLaneConfig::STATUS_ASSEMBLED,
            'notes' => $notes,
        ]))->save();

        $this->record($recorder, $row, 'place_blocked', 'LIVE_RISK_ABORTED', array_merge([
            'reason' => $reason,
            'place_attempts_failed' => $failed,
            'prior_order_confirmed_dead' => $priorOrderConfirmedDead,
        ], $detail));
        $this->warn("thesis play {$row->id}: placement abandoned — {$reason}");
    }

    /**
     * The place call threw. The order may or may not be live at the broker, so
     * the play is blocked (never re-placed) but stays supervised, and its
     * debit stays on the books until broker truth says otherwise.
     */
    private function markUnconfirmedPlacement(RobinhoodTradeJournal $row, JsonlRecorder $recorder, string $error, float $limit): void
    {
        $this->markBlocked($row, $recorder, 'place_unconfirmed_after_throw', [
            'detail' => $error,
            'limit_price' => $limit,
            'requires_operator_reconcile' => true,
        ]);
        $this->error("thesis play {$row->id}: placement outcome unconfirmed: {$error}");
    }

    // ── Broker cancel ────────────────────────────────────────────────────

    /**
     * Send the cancel, then poll broker truth for it. Robinhood option cancels
     * are asynchronous — a first readback that still says `queued` or
     * `pending_cancel` is routine, not evidence of failure — so declaring
     * ambiguity on one look would strand plays that are simply mid-cancel.
     *
     * The `detail` key (never `reason`) carries the broker-side explanation so
     * the caller's own named reason always survives the merge into telemetry
     * and the journal's blocked stamp.
     *
     * @return array{state: string, detail?: string, broker_state?: string|null, attempts?: int}
     */
    private function cancelAndVerify(RobinhoodMcpClient $client, string $brokerOrderId): array
    {
        try {
            $client->cancelOptionOrder($brokerOrderId);
        } catch (\Throwable $e) {
            return ['state' => 'error', 'detail' => 'cancel_call_failed: '.$e->getMessage()];
        }

        $last = ['state' => 'ambiguous', 'detail' => 'cancel_never_read_back'];

        for ($attempt = 1; $attempt <= self::CANCEL_VERIFY_ATTEMPTS; $attempt++) {
            if ($attempt > 1) {
                Sleep::for(self::CANCEL_VERIFY_DELAY_MS)->milliseconds();
            }

            $readback = $this->readOrderState($client, $brokerOrderId);

            if ($readback['state'] === 'dead') {
                return ['state' => 'cancelled', 'broker_state' => $readback['broker_state'] ?? null, 'attempts' => $attempt];
            }
            if ($readback['state'] === 'filled') {
                return ['state' => 'filled', 'broker_state' => $readback['broker_state'] ?? null, 'attempts' => $attempt];
            }

            $last = [
                'state' => 'ambiguous',
                'detail' => (string) ($readback['detail'] ?? 'order_state_unknown'),
                'broker_state' => $readback['broker_state'] ?? null,
                'attempts' => $attempt,
            ];
        }

        return $last;
    }

    /**
     * One broker-truth read of a single order.
     *
     * @return array{state: string, detail?: string, broker_state?: string|null, order?: array<string,mixed>}
     */
    private function readOrderState(RobinhoodMcpClient $client, string $brokerOrderId): array
    {
        try {
            $orders = PortfolioReader::listFrom($client->getOptionOrders());
        } catch (\Throwable $e) {
            return ['state' => 'unreadable', 'detail' => 'order_readback_failed: '.$e->getMessage()];
        }

        foreach ($orders as $order) {
            if (! is_array($order) || (string) ($order['id'] ?? '') !== $brokerOrderId) {
                continue;
            }

            $state = strtolower((string) ($order['state'] ?? $order['status'] ?? ''));

            if (in_array($state, ThesisLaneConfig::DEAD_ORDER_STATES, true)) {
                return ['state' => 'dead', 'broker_state' => $state];
            }
            if (in_array($state, ThesisLaneConfig::FILLED_ORDER_STATES, true)) {
                return ['state' => 'filled', 'broker_state' => $state, 'order' => $order];
            }

            return [
                'state' => 'live',
                'detail' => 'order_still_'.($state !== '' ? $state : 'unknown'),
                'broker_state' => $state !== '' ? $state : null,
            ];
        }

        // Absent from broker truth is UNKNOWN, never "gone". Fail closed.
        return ['state' => 'absent', 'detail' => 'order_absent_from_broker_readback'];
    }

    private function cancelCapable(RobinhoodMcpClient $client): bool
    {
        try {
            $tools = array_map(
                static fn (array $tool): string => (string) ($tool['name'] ?? ''),
                $client->listTools(),
            );
        } catch (\Throwable) {
            // The MCP surface is versionless and has moved without notice
            // before. An unreadable tool list is not permission to cancel.
            return false;
        }

        return in_array(self::CANCEL_TOOL, $tools, true);
    }

    // ── Row selection / claiming ─────────────────────────────────────────

    /** @return Collection<int, RobinhoodTradeJournal> */
    private function supervisedRows()
    {
        return $this->laneQuery()
            ->whereIn('status', ThesisLaneConfig::SUPERVISED_STATUSES)
            ->oldest('id')
            ->get();
    }

    /**
     * @param  list<int>  $excludeIds  rows the supervision pass already handled this tick
     * @return Collection<int, RobinhoodTradeJournal>
     */
    private function placeableRows(?int $limit, array $excludeIds = [])
    {
        $query = $this->laneQuery()
            ->whereIn('status', ThesisLaneConfig::PLACEABLE_STATUSES)
            // A placeable row has no live order by definition; a blocked flag
            // on one means the lane already refused to place it.
            ->whereNull('notes->thesis_entry->blocked')
            ->oldest('id');

        if ($excludeIds !== []) {
            $query->whereNotIn('id', $excludeIds);
        }

        if ($limit !== null) {
            if ($limit <= 0) {
                return $query->whereRaw('1 = 0')->get();
            }
            $query->limit($limit);
        }

        return $query->get();
    }

    /** @return Builder<RobinhoodTradeJournal> */
    private function laneQuery()
    {
        return RobinhoodTradeJournal::query()
            ->where('asset_class', 'option')
            ->whereNotNull('thesis_intent_id')
            ->where('side', 'buy')
            ->where('position_effect', 'open');
    }

    private function placementLimit(array $cfg): int
    {
        $laneMax = ThesisLaneConfig::maxOpenPositions($cfg);
        if ($laneMax <= 0) {
            // Zero is a hard stop at BOTH layers, never "unbounded".
            return 0;
        }

        $option = $this->option('limit');
        if (is_numeric($option)) {
            return max(0, min((int) $option, $laneMax));
        }

        return $laneMax;
    }

    private function claimRow(RobinhoodTradeJournal $row): bool
    {
        $claimed = RobinhoodTradeJournal::query()
            ->whereKey($row->id)
            ->whereIn('status', ThesisLaneConfig::PLACEABLE_STATUSES)
            ->update([
                'status' => ThesisLaneConfig::STATUS_CLAIMED,
                'updated_at' => now(),
            ]);

        if ($claimed === 1) {
            $row->refresh();
        }

        return $claimed === 1;
    }

    /**
     * Atomic mutex for the cancel-then-replace critical section, keyed on the
     * exact order this invocation intends to cancel. A concurrent invocation
     * updates zero rows and backs off.
     */
    private function claimRepeg(RobinhoodTradeJournal $row, string $brokerOrderId): bool
    {
        $priorStatus = (string) $row->status;

        $claimed = RobinhoodTradeJournal::query()
            ->whereKey($row->id)
            ->whereIn('status', ThesisLaneConfig::WORKING_STATUSES)
            ->where('broker_order_id', $brokerOrderId)
            ->update([
                'status' => ThesisLaneConfig::STATUS_REPEGGING,
                'updated_at' => now(),
            ]);

        if ($claimed !== 1) {
            return false;
        }

        $row->refresh();
        $notes = (array) ($row->notes ?? []);
        $entry = (array) ($notes['thesis_entry'] ?? []);
        $entry['repeg_prior_status'] = $priorStatus;
        $notes['thesis_entry'] = $entry;
        $row->forceFill(['notes' => $notes])->save();

        return true;
    }

    private function releaseRepegClaim(RobinhoodTradeJournal $row): void
    {
        $row->refresh();
        $notes = (array) ($row->notes ?? []);
        $entry = (array) ($notes['thesis_entry'] ?? []);
        $prior = (string) ($entry['repeg_prior_status'] ?? 'submitted');
        unset($entry['repeg_prior_status']);
        $notes['thesis_entry'] = $entry;

        $row->forceFill([
            'status' => in_array($prior, ThesisLaneConfig::WORKING_STATUSES, true) ? $prior : 'submitted',
            'strategy_label' => ThesisLaneConfig::STRATEGY_LABEL,
            'notes' => $notes,
        ])->save();
    }

    private function releaseClaim(RobinhoodTradeJournal $row): void
    {
        $row->forceFill([
            'status' => ThesisLaneConfig::STATUS_ASSEMBLED,
            'strategy_label' => ThesisLaneConfig::STRATEGY_LABEL,
        ])->save();
    }

    /** Recover claims and re-peg mutexes abandoned by a crashed invocation. */
    private function recoverStaleClaims(): void
    {
        $threshold = now()->subMinutes(self::STALE_CLAIM_MINUTES);

        RobinhoodTradeJournal::query()
            ->where('asset_class', 'option')
            ->whereNotNull('thesis_intent_id')
            ->where('status', ThesisLaneConfig::STATUS_CLAIMED)
            ->where('updated_at', '<', $threshold)
            ->update([
                'status' => ThesisLaneConfig::STATUS_ASSEMBLED,
                'updated_at' => now(),
            ]);

        // A crashed re-peg leaves the row mid-mutex. Returning it to a working
        // status is safe: the next tick re-reads broker truth for its order
        // before touching anything.
        RobinhoodTradeJournal::query()
            ->where('asset_class', 'option')
            ->whereNotNull('thesis_intent_id')
            ->where('status', ThesisLaneConfig::STATUS_REPEGGING)
            ->where('updated_at', '<', $threshold)
            ->update([
                'status' => 'submitted',
                'updated_at' => now(),
            ]);
    }

    // ── Journal + telemetry stamps ───────────────────────────────────────

    private function nextAttempt(RobinhoodTradeJournal $row): int
    {
        $row->refresh();
        $notes = (array) ($row->notes ?? []);
        $entry = (array) ($notes['thesis_entry'] ?? []);
        $attempt = (int) ($entry['attempt_seq'] ?? 0) + 1;
        $entry['attempt_seq'] = $attempt;
        $notes['thesis_entry'] = $entry;
        $row->forceFill(['notes' => $notes])->save();

        return $attempt;
    }

    private function stampPlacement(RobinhoodTradeJournal $row, float $limit, ?string $brokerOrderId, ?string $clientRefId, string $event): void
    {
        $row->refresh();
        $notes = (array) ($row->notes ?? []);
        $entry = (array) ($notes['thesis_entry'] ?? []);

        $entry['placed_at'] = now()->toISOString();
        $entry['working_limit_price'] = $limit;
        unset($entry['repeg_prior_status']);
        $placements = (array) ($entry['placements'] ?? []);
        $placements[] = [
            'event' => $event,
            'limit_price' => $limit,
            'client_ref_id' => $clientRefId,
            'broker_order_id' => $brokerOrderId,
            'at' => now()->toISOString(),
        ];
        $entry['placements'] = array_values($placements);
        $notes['thesis_entry'] = $entry;

        $row->forceFill([
            'strategy_label' => ThesisLaneConfig::STRATEGY_LABEL,
            'notes' => $notes,
        ])->save();
    }

    private function markBlocked(RobinhoodTradeJournal $row, JsonlRecorder $recorder, string $reason, array $detail = []): void
    {
        $row->refresh();
        $notes = (array) ($row->notes ?? []);
        $entry = (array) ($notes['thesis_entry'] ?? []);
        $entry['blocked'] = array_merge($detail, ['reason' => $reason, 'at' => now()->toISOString()]);
        unset($entry['repeg_prior_status']);
        $notes['thesis_entry'] = $entry;

        $row->forceFill([
            // Still inside SUPERVISED_STATUSES: blocked stops the re-peg, it
            // never stops the lane watching a possibly-live order.
            'status' => ThesisLaneConfig::STATUS_ENTRY_BLOCKED,
            'strategy_label' => ThesisLaneConfig::STRATEGY_LABEL,
            'notes' => $notes,
        ])->save();

        $this->record($recorder, $row, 'blocked', 'UNKNOWN_EXECUTION_STATE', array_merge($detail, ['reason' => $reason]));
        $this->error("thesis play {$row->id}: blocked — {$reason}");
    }

    private function markExpired(RobinhoodTradeJournal $row, JsonlRecorder $recorder, string $reason, array $detail = []): void
    {
        $row->refresh();
        $notes = (array) ($row->notes ?? []);
        $entry = (array) ($notes['thesis_entry'] ?? []);
        $entry['expired'] = array_merge($detail, ['reason' => $reason, 'at' => now()->toISOString()]);
        unset($entry['repeg_prior_status']);
        $notes['thesis_entry'] = $entry;

        $row->forceFill([
            'status' => ThesisLaneConfig::STATUS_EXPIRED_UNFILLED,
            'strategy_label' => ThesisLaneConfig::STRATEGY_LABEL,
            'closed_at' => $row->closed_at ?? now(),
            'notes' => $notes,
        ])->save();

        $this->record($recorder, $row, 'expired', 'LIVE_CANCELLED', array_merge($detail, ['reason' => $reason]));
        $this->warn("thesis play {$row->id}: expired unfilled — {$reason}");
    }

    private function recordFillDuringCancel(RobinhoodTradeJournal $row, JsonlRecorder $recorder, array $cancel, string $brokerOrderId): void
    {
        $row->refresh();
        $notes = (array) ($row->notes ?? []);
        $entry = (array) ($notes['thesis_entry'] ?? []);
        $entry['filled_during_cancel'] = [
            'at' => now()->toISOString(),
            'broker_order_id' => $brokerOrderId,
            'broker_state' => $cancel['broker_state'] ?? null,
        ];
        unset($entry['repeg_prior_status']);
        $notes['thesis_entry'] = $entry;
        $row->forceFill([
            'notes' => $notes,
            'strategy_label' => ThesisLaneConfig::STRATEGY_LABEL,
        ])->save();

        // Broker truth outranks this lane: theta:option-reconcile owns the
        // fill quantities, prices, and terminal status.
        $this->record($recorder, $row, 'filled', 'LIVE_FILLED', [
            'reason' => 'filled_before_cancel_landed',
            'broker_order_id' => $brokerOrderId,
            'broker_state' => $cancel['broker_state'] ?? null,
        ]);
        $this->info("thesis play {$row->id}: entry filled before the cancel landed");
    }

    private function record(JsonlRecorder $recorder, RobinhoodTradeJournal $row, string $event, string $classification, array $detail = []): void
    {
        $recorder->record('thesis_execution', array_merge([
            'event' => $event,
            'classification' => $classification,
            'journal_id' => $row->id,
            'thesis_intent_id' => $row->thesis_intent_id,
            'symbol' => $row->underlying_symbol ?? $row->symbol,
            'option_id' => $row->option_id,
            'status' => $row->status,
        ], $detail));
    }

    // ── Per-play inputs ──────────────────────────────────────────────────

    private function thesisFor(RobinhoodTradeJournal $row): ?ThesisIntent
    {
        if ($row->thesis_intent_id === null) {
            return null;
        }

        return ThesisIntent::query()->find($row->thesis_intent_id);
    }

    private function perPlayMaxDebit(RobinhoodTradeJournal $row, ?ThesisIntent $thesis, array $cfg): float
    {
        $maxDebit = $thesis instanceof ThesisIntent
            ? (float) $thesis->max_debit
            : (float) (data_get($row->notes, 'thesis_entry.per_play_max_debit') ?? 0.0);

        return ThesisLaneConfig::perPlayMaxDebit($cfg, $maxDebit);
    }

    private function offsetBps(RobinhoodTradeJournal $row, ?ThesisIntent $thesis): float
    {
        if ($thesis instanceof ThesisIntent) {
            return (float) $thesis->entry_limit_offset_bps;
        }

        return (float) (data_get($row->notes, 'thesis_entry.entry_limit_offset_bps') ?? 0.0);
    }

    /**
     * The chase ladder's fixed anchor. An assembly that could only see a
     * pre-open (stale) option quote re-anchors ONCE against the first live
     * quote; after that the anchor never moves, so re-pegging can never
     * ratchet its own ceiling.
     */
    private function anchorMid(RobinhoodTradeJournal $row, array $quote, bool $reanchor = true): float
    {
        $stored = data_get($row->notes, 'thesis_entry.anchor_mid');
        $source = (string) (data_get($row->notes, 'thesis_entry.anchor_source') ?? '');
        $liveMid = is_numeric($quote['mid'] ?? null) ? (float) $quote['mid'] : null;

        if ($reanchor && $source === 'assembly_stale_quote' && $liveMid !== null && $liveMid > 0.0) {
            $notes = (array) ($row->notes ?? []);
            $entry = (array) ($notes['thesis_entry'] ?? []);
            $entry['anchor_mid'] = $liveMid;
            $entry['anchor_source'] = 'first_live_placement';
            $entry['anchor_reanchored_from'] = is_numeric($stored) ? (float) $stored : null;
            $notes['thesis_entry'] = $entry;
            $row->forceFill(['notes' => $notes])->save();

            return $liveMid;
        }

        if (is_numeric($stored) && (float) $stored > 0.0) {
            return (float) $stored;
        }

        return $liveMid ?? 0.0;
    }

    private function placedAt(RobinhoodTradeJournal $row): ?Carbon
    {
        $stamped = data_get($row->notes, 'thesis_entry.placed_at');
        if (is_string($stamped) && $stamped !== '') {
            try {
                return Carbon::parse($stamped);
            } catch (\Throwable) {
                // Fall through to the journal column.
            }
        }

        return $row->submitted_at;
    }

    /**
     * @return array{contract: array<string,mixed>, quote: array<string,mixed>, blockers: list<string>}|null
     */
    private function resolve(RobinhoodTradeJournal $row, RobinhoodMcpClient $client, array $laneCfg): ?array
    {
        $expiration = $row->expiration_date?->toDateString();
        if ($expiration === null || $row->strike_price === null || $row->option_type === null) {
            return null;
        }

        try {
            $resolution = (new OptionContractResolver($client, $laneCfg))->resolveExact(
                strtoupper((string) ($row->underlying_symbol ?: $row->symbol)),
                (string) $row->option_type,
                $expiration,
                (float) $row->strike_price,
            );
        } catch (\Throwable $e) {
            return ['contract' => [], 'quote' => [], 'blockers' => ['resolver_blocker: '.$e->getMessage()]];
        }

        if (! is_array($resolution['contract']) || ! is_array($resolution['quote'])) {
            return null;
        }

        return [
            'contract' => $resolution['contract'],
            'quote' => $resolution['quote'],
            'blockers' => array_values((array) $resolution['blockers']),
        ];
    }
}
