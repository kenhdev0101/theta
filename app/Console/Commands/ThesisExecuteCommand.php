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
 * SAFETY CONTRACT FOR RE-PEGGING. A re-peg is cancel-then-replace, and the
 * cancel must be *proved* from broker truth before a second order can exist:
 *
 *   - the broker must advertise `cancel_option_order` (fail closed if the
 *     tool surface cannot be read — the surface is versionless and moves);
 *   - the cancel is verified by re-reading `get_option_orders`;
 *   - only `cancelled`/`canceled`/`rejected` clears a re-place;
 *   - `filled` means the entry already worked — never re-place;
 *   - anything else, including an order missing from the readback, is
 *     AMBIGUOUS: the play is marked `thesis_entry_blocked` with a named
 *     reason and this lane stops touching it. Double exposure is not a
 *     tradeoff we make against a missed entry.
 */
class ThesisExecuteCommand extends Command
{
    use AssemblesHarnessConfig;

    private const CANCEL_TOOL = 'cancel_option_order';

    private const STALE_CLAIM_MINUTES = 5;

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

        $withinWindow = ThesisLaneConfig::withinEntryWindow($cfg);
        $client = RobinhoodMcpClient::fromConfig($cfg, $recorder);
        $cancelCapable = $this->cancelCapable($client);

        $this->recoverStaleClaims();

        $placed = 0;
        $repegged = 0;
        $expired = 0;
        $blocked = 0;

        foreach ($this->workingRows() as $row) {
            $outcome = $withinWindow
                ? $this->workRestingOrder($row, $cfg, $client, $recorder, $cancelCapable)
                : $this->expireRestingOrder($row, $cfg, $client, $recorder, $cancelCapable);

            match ($outcome) {
                'repegged' => $repegged++,
                'expired' => $expired++,
                'blocked' => $blocked++,
                default => null,
            };
        }

        if ($withinWindow) {
            $limit = $this->placementLimit($lane);
            foreach ($this->placeableRows($limit) as $row) {
                $outcome = $this->placeFreshEntry($row, $cfg, $client, $recorder);
                match ($outcome) {
                    'placed' => $placed++,
                    'blocked' => $blocked++,
                    default => null,
                };
            }
        } else {
            foreach ($this->placeableRows(null) as $row) {
                $this->expireUnplaced($row, $recorder);
                $expired++;
            }
        }

        $this->info(sprintf(
            'thesis executor complete: window=%s placed=%d repegged=%d expired=%d blocked=%d',
            $withinWindow ? 'open' : 'closed',
            $placed,
            $repegged,
            $expired,
            $blocked,
        ));

        return self::SUCCESS;
    }

    // ── Fresh entry ──────────────────────────────────────────────────────

    private function placeFreshEntry(RobinhoodTradeJournal $row, array $cfg, RobinhoodMcpClient $client, JsonlRecorder $recorder): string
    {
        if (! $this->claimRow($row)) {
            $this->line("thesis play {$row->id}: skipped (already claimed)");

            return 'skipped';
        }

        $thesis = $this->thesisFor($row);
        if ($thesis instanceof ThesisIntent && ! $thesis->isEnterable()) {
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

    // ── Working (resting) order ──────────────────────────────────────────

    private function workRestingOrder(RobinhoodTradeJournal $row, array $cfg, RobinhoodMcpClient $client, JsonlRecorder $recorder, bool $cancelCapable): string
    {
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

        $thesis = $this->thesisFor($row);
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

        $cancel = $this->cancelAndVerify($client, $brokerOrderId);

        if ($cancel['state'] === 'error') {
            $this->record($recorder, $row, 'repeg_skipped', 'LIVE_POSTED_NOT_FILLED', array_merge($cancel, [
                'reason' => 'repeg_cancel_failed',
                'broker_order_id' => $brokerOrderId,
            ]));

            return 'working';
        }

        if ($cancel['state'] === 'filled') {
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

        return $this->submit($row, $cfg, $laneCfg, $client, $recorder, $resolution, $newLimit, 'repegged', priorDebit: (float) ($row->dollar_amount ?? 0.0))
            ? 'repegged'
            : 'blocked';
    }

    private function expireRestingOrder(RobinhoodTradeJournal $row, array $cfg, RobinhoodMcpClient $client, JsonlRecorder $recorder, bool $cancelCapable): string
    {
        $brokerOrderId = (string) ($row->broker_order_id ?? '');
        if ($brokerOrderId === '') {
            $this->markBlocked($row, $recorder, 'broker_order_id_missing');

            return 'blocked';
        }

        if (! $cancelCapable) {
            $this->markBlocked($row, $recorder, 'cancel_capability_unavailable', [
                'required_tool' => self::CANCEL_TOOL,
                'broker_order_id' => $brokerOrderId,
            ]);

            return 'blocked';
        }

        $cancel = $this->cancelAndVerify($client, $brokerOrderId);

        if ($cancel['state'] === 'filled') {
            $this->recordFillDuringCancel($row, $recorder, $cancel, $brokerOrderId);

            return 'working';
        }

        if ($cancel['state'] === 'error') {
            // Retryable: the order is still live and the next tick inside the
            // scheduled sweep will try again rather than abandoning it.
            $this->record($recorder, $row, 'repeg_skipped', 'LIVE_POSTED_NOT_FILLED', array_merge($cancel, [
                'reason' => 'window_close_cancel_failed',
                'broker_order_id' => $brokerOrderId,
            ]));

            return 'working';
        }

        if ($cancel['state'] !== 'cancelled') {
            $this->markBlocked($row, $recorder, 'cancel_unconfirmed', array_merge($cancel, [
                'broker_order_id' => $brokerOrderId,
            ]));

            return 'blocked';
        }

        $this->markExpired($row, $recorder, 'entry_window_closed', [
            'broker_order_id' => $brokerOrderId,
            'broker_state' => $cancel['broker_state'] ?? null,
        ]);

        return 'expired';
    }

    private function expireUnplaced(RobinhoodTradeJournal $row, JsonlRecorder $recorder): void
    {
        $this->markExpired($row, $recorder, 'entry_window_closed_unplaced');
    }

    // ── Placement spine ──────────────────────────────────────────────────

    /**
     * Place (or re-place) through the shared option execution spine:
     * rails -> persisted ref -> broker review -> rails -> place -> journal.
     *
     * @param  array{contract: array<string,mixed>, quote: array<string,mixed>, blockers: list<string>}  $resolution
     * @param  float  $priorDebit  debit this row already contributes to today's
     *                             journal-derived gross-entry total. On a re-peg the row is
     *                             already `submitted`/`open`, so leaving it in would make
     *                             the play compete against its own budget.
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
    ): bool {
        $contract = $resolution['contract'];

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
            source: "theta:thesis-execute:journal:{$row->id}",
        );

        try {
            $context = OptionBrokerContext::assemble($client, $cfg);
        } catch (\Throwable $e) {
            $this->releaseClaimIfClaimed($row);
            $this->record($recorder, $row, 'place_blocked', 'UNKNOWN_EXECUTION_STATE', [
                'reason' => 'broker_truth_blocker: '.$e->getMessage(),
            ]);

            return false;
        }

        if ($priorDebit > 0.0 && is_numeric($context['options_spent_today_debit'] ?? null)) {
            $context['options_spent_today_debit'] = round(max(0.0, (float) $context['options_spent_today_debit'] - $priorDebit), 2);
        }

        $context['contract'] = $contract;
        $context['quote'] = $resolution['quote'];
        $context['journal_id'] = $row->id;

        $executor = new OptionExecutor($client, new OptionRailPipeline, new OrderRefStore, $recorder, $laneCfg);

        try {
            // requireMarketableBuy: false — a thesis entry is worked inside
            // the live book, not lifted at the ask (see OptionRailPipeline).
            $result = $executor->execute($intent, $context, requireMarketableBuy: false);
        } catch (\Throwable $e) {
            $this->releaseClaimIfClaimed($row);
            $this->record($recorder, $row, 'place_failed', 'UNKNOWN_EXECUTION_STATE', [
                'reason' => 'placement_mechanical_failure: '.$e->getMessage(),
                'limit_price' => $limit,
            ]);
            $this->error("thesis play {$row->id}: placement mechanical failure: {$e->getMessage()}");

            return false;
        }

        if ($result['classification'] !== 'LIVE_POSTED_NOT_FILLED') {
            $this->releaseClaimIfClaimed($row);
            $this->stampLaneLabel($row);
            $this->record($recorder, $row, 'place_blocked', 'LIVE_RISK_ABORTED', [
                'reason' => 'rail_block: '.$result['verdict']->verdict(),
                'block_reasons' => $result['verdict']->blockReasons(),
                'limit_price' => $limit,
            ]);
            $this->warn("thesis play {$row->id}: rail block {$result['verdict']->verdict()}");

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
            'contract' => $intent->contractLabel(),
        ]);
        $this->info(sprintf('thesis play %d: %s %s @ %0.2f', $row->id, $event, $intent->contractLabel(), $limit));

        return true;
    }

    // ── Broker cancel ────────────────────────────────────────────────────

    /**
     * The `detail` key (never `reason`) carries the broker-side explanation so
     * the caller's own named reason always survives the merge into telemetry
     * and the journal's blocked stamp.
     *
     * @return array{state: string, detail?: string, broker_state?: string|null, order?: array<string,mixed>}
     */
    private function cancelAndVerify(RobinhoodMcpClient $client, string $brokerOrderId): array
    {
        try {
            $client->cancelOptionOrder($brokerOrderId);
        } catch (\Throwable $e) {
            return ['state' => 'error', 'detail' => 'cancel_call_failed: '.$e->getMessage()];
        }

        try {
            $orders = PortfolioReader::listFrom($client->getOptionOrders());
        } catch (\Throwable $e) {
            return ['state' => 'ambiguous', 'detail' => 'cancel_readback_failed: '.$e->getMessage()];
        }

        foreach ($orders as $order) {
            if (! is_array($order) || (string) ($order['id'] ?? '') !== $brokerOrderId) {
                continue;
            }

            $state = strtolower((string) ($order['state'] ?? $order['status'] ?? ''));

            if (in_array($state, ThesisLaneConfig::CANCELLED_ORDER_STATES, true)) {
                return ['state' => 'cancelled', 'broker_state' => $state];
            }
            if (in_array($state, ThesisLaneConfig::FILLED_ORDER_STATES, true)) {
                return ['state' => 'filled', 'broker_state' => $state, 'order' => $order];
            }

            return [
                'state' => 'ambiguous',
                'detail' => 'order_still_'.($state !== '' ? $state : 'unknown'),
                'broker_state' => $state !== '' ? $state : null,
            ];
        }

        // Absent from broker truth is UNKNOWN, never "gone". Fail closed.
        return ['state' => 'ambiguous', 'detail' => 'order_absent_from_broker_readback'];
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
    private function workingRows()
    {
        return $this->laneQuery()
            ->whereIn('status', ThesisLaneConfig::WORKING_STATUSES)
            ->oldest('id')
            ->get();
    }

    /** @return Collection<int, RobinhoodTradeJournal> */
    private function placeableRows(?int $limit)
    {
        $query = $this->laneQuery()
            ->whereIn('status', ThesisLaneConfig::PLACEABLE_STATUSES)
            ->oldest('id');

        if ($limit !== null) {
            $query->limit(max(1, $limit));
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
            ->where('position_effect', 'open')
            // A blocked play stays blocked even if broker reconciliation later
            // rewrites its status from broker truth.
            ->whereNull('notes->thesis_entry->blocked');
    }

    private function placementLimit(array $lane): int
    {
        $option = $this->option('limit');
        if (is_numeric($option) && (int) $option > 0) {
            return (int) $option;
        }

        return max(1, (int) ($lane['max_open_positions'] ?? 1));
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

    private function releaseClaim(RobinhoodTradeJournal $row): void
    {
        $row->forceFill([
            'status' => ThesisLaneConfig::STATUS_ASSEMBLED,
            'strategy_label' => ThesisLaneConfig::STRATEGY_LABEL,
        ])->save();
    }

    private function releaseClaimIfClaimed(RobinhoodTradeJournal $row): void
    {
        if ($row->refresh()->status === ThesisLaneConfig::STATUS_CLAIMED) {
            $this->releaseClaim($row);
        }
    }

    private function recoverStaleClaims(): void
    {
        RobinhoodTradeJournal::query()
            ->where('asset_class', 'option')
            ->whereNotNull('thesis_intent_id')
            ->where('status', ThesisLaneConfig::STATUS_CLAIMED)
            ->where('updated_at', '<', now()->subMinutes(self::STALE_CLAIM_MINUTES))
            ->update([
                'status' => ThesisLaneConfig::STATUS_ASSEMBLED,
                'updated_at' => now(),
            ]);
    }

    // ── Journal + telemetry stamps ───────────────────────────────────────

    private function stampPlacement(RobinhoodTradeJournal $row, float $limit, ?string $brokerOrderId, ?string $clientRefId, string $event): void
    {
        $row->refresh();
        $notes = (array) ($row->notes ?? []);
        $entry = (array) ($notes['thesis_entry'] ?? []);

        $entry['placed_at'] = now()->toISOString();
        $entry['working_limit_price'] = $limit;
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

    /** The shared executor stamps its own strategy label; re-assert the lane's. */
    private function stampLaneLabel(RobinhoodTradeJournal $row): void
    {
        $row->refresh();
        if ($row->strategy_label !== ThesisLaneConfig::STRATEGY_LABEL) {
            $row->forceFill(['strategy_label' => ThesisLaneConfig::STRATEGY_LABEL])->save();
        }
    }

    private function markBlocked(RobinhoodTradeJournal $row, JsonlRecorder $recorder, string $reason, array $detail = []): void
    {
        $row->refresh();
        $notes = (array) ($row->notes ?? []);
        $entry = (array) ($notes['thesis_entry'] ?? []);
        $entry['blocked'] = array_merge(['reason' => $reason, 'at' => now()->toISOString()], $detail);
        $notes['thesis_entry'] = $entry;

        $row->forceFill([
            'status' => ThesisLaneConfig::STATUS_ENTRY_BLOCKED,
            'strategy_label' => ThesisLaneConfig::STRATEGY_LABEL,
            'notes' => $notes,
        ])->save();

        $this->record($recorder, $row, 'blocked', 'UNKNOWN_EXECUTION_STATE', array_merge(['reason' => $reason], $detail));
        $this->error("thesis play {$row->id}: blocked — {$reason}");
    }

    private function markExpired(RobinhoodTradeJournal $row, JsonlRecorder $recorder, string $reason, array $detail = []): void
    {
        $row->refresh();
        $notes = (array) ($row->notes ?? []);
        $entry = (array) ($notes['thesis_entry'] ?? []);
        $entry['expired'] = array_merge(['reason' => $reason, 'at' => now()->toISOString()], $detail);
        $notes['thesis_entry'] = $entry;

        $row->forceFill([
            'status' => ThesisLaneConfig::STATUS_EXPIRED_UNFILLED,
            'strategy_label' => ThesisLaneConfig::STRATEGY_LABEL,
            'closed_at' => $row->closed_at ?? now(),
            'notes' => $notes,
        ])->save();

        $this->record($recorder, $row, 'expired', 'LIVE_CANCELLED', array_merge(['reason' => $reason], $detail));
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
        $notes['thesis_entry'] = $entry;
        $row->forceFill(['notes' => $notes, 'strategy_label' => ThesisLaneConfig::STRATEGY_LABEL])->save();

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
