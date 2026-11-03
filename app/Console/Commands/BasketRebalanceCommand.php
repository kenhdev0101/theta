<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\AssemblesHarnessConfig;
use App\Models\RebalanceEvent;
use App\Models\RebalanceLeg;
use App\Models\RobinhoodTradeJournal;
use App\Robinhood\Basket\BasketBrokerTruth;
use App\Robinhood\Basket\BasketBuyingPowerGate;
use App\Robinhood\Basket\BasketRebalancePlanner;
use App\Robinhood\Basket\BasketWeights;
use App\Robinhood\Convex\ConvexLoopLock;
use App\Robinhood\Execution\EquityExecutor;
use App\Robinhood\Execution\OrderRefStore;
use App\Robinhood\Mcp\BrokerCreditExhausted;
use App\Robinhood\Mcp\RobinhoodMcpClient;
use App\Robinhood\Rails\OrderIntent;
use App\Robinhood\Rails\RailPipeline;
use App\Robinhood\Rails\SessionClock;
use App\Robinhood\Telemetry\JsonlRecorder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** V1 executes only an explicitly selected, immutable preview event. No model, sells, stops, or automatic top-ups. */
class BasketRebalanceCommand extends Command
{
    use AssemblesHarnessConfig;

    protected $signature = 'theta:basket-rebalance
        {--dry-run : Persist a preview; always overrides --arm}
        {--arm : Resume only harness.basket.active_event_id using fresh broker truth}
        {--trigger=manual : deposit|drift|score_change|manual|scheduled}
        {--fixture= : Preview-only broker truth JSON}
        {--weights= : Preview-only weights CSV}';

    protected $description = 'Preview or resume the approved equity basket, with durable references and broker fill readback';

    public function handle(): int
    {
        $release = $this->acquireAccountLock();
        if ($release === null) {
            $this->line('basket deferred: account execution lock busy; retry next tick');

            return self::SUCCESS;
        }
        try {
            return $this->runPass();
        } catch (BrokerCreditExhausted) {
            $this->warn('basket deferred: broker API credit budget; retry next tick with the same event and references');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            // Broker exception messages can contain credentials or payloads.
            $this->error('basket blocked: '.class_basename($e));

            return self::FAILURE;
        } finally {
            $release();
        }
    }

    private function acquireAccountLock(): ?\Closure
    {
        $release = ConvexLoopLock::acquire();
        if ($release !== null || DB::connection()->getDriverName() !== 'mysql') {
            return $release;
        }
        // Convex is scheduled first on the minute. Briefly queue behind that
        // tick instead of losing every basket invocation to the same race.
        $result = DB::selectOne('SELECT GET_LOCK(?, 5) AS acquired', ['theta:convex-auto-loop']);
        if ((int) ($result->acquired ?? 0) !== 1) {
            return null;
        }

        return static function (): void {
            DB::selectOne('SELECT RELEASE_LOCK(?) AS released', ['theta:convex-auto-loop']);
        };
    }

    private function runPass(): int
    {
        $cfg = $this->assembleConfig();
        $basket = (array) ($cfg['basket'] ?? []);
        if (isset($basket['per_day_max_debit'])) {
            $cfg['allocation']['per_day_max_debit'] = (float) $basket['per_day_max_debit'];
        }
        $fundingSource = (string) ($basket['funding_source'] ?? 'settled_cash');
        $arm = (bool) $this->option('arm') && ! $this->option('dry-run');
        if ($arm && ($this->option('fixture') !== null || $this->option('weights') !== null)) {
            $this->error('ARM refused: fixture and weights overrides are preview-only');

            return self::FAILURE;
        }
        if ($arm && (! (bool) ($basket['enabled'] ?? false) || ! (bool) ($basket['armed'] ?? false) || (bool) ($cfg['kill_switch'] ?? true))) {
            $this->warn('ARM refused: basket enabled/armed gates or kill switch');

            return self::SUCCESS;
        }
        $trigger = (string) $this->option('trigger');
        if (! in_array($trigger, RebalanceEvent::TRIGGERS, true)) {
            return self::INVALID;
        }
        $weights = BasketWeights::fromCsv((string) ($this->option('weights') ?: ($basket['weights_path'] ?? base_path('docs/weighting-basket.csv'))));
        $event = $arm ? RebalanceEvent::query()->find((int) ($basket['active_event_id'] ?? 0)) : null;
        if ($arm && ($event === null || $event->weights_payload_hash !== $weights->payloadHash || data_get($event->notes, 'truth_source') !== 'live' || data_get($event->notes, 'funding_source', 'settled_cash') !== $fundingSource)) {
            $this->error('ARM refused: select a live preview event matching the configured weights');

            return self::FAILURE;
        }
        $cfg['broker_credits']['mode'] = 'fail';
        $recorder = JsonlRecorder::fromConfig($cfg);
        $client = RobinhoodMcpClient::fromConfig($cfg, $recorder);
        $fixture = $this->option('fixture');
        if ($fixture !== null) {
            $raw = json_decode((string) file_get_contents((string) $fixture), true, 512, JSON_THROW_ON_ERROR);
            $truth = ['settled_cash' => $raw['settled_cash'] ?? null, 'positions' => BasketRebalancePlanner::normalizePositions($raw['equity_positions'] ?? []), 'tradability' => $raw['tradability'] ?? [], 'orders' => [], 'positions_raw' => [], 'source' => 'fixture'];
        } else {
            $truth = (new BasketBrokerTruth)->read($client, $weights->tickers());
        }
        if (! $arm) {
            $allocation = (array) ($cfg['allocation'] ?? []);
            $plan = (new BasketRebalancePlanner)->plan($weights, [
                'settled_cash' => $truth['settled_cash'],
                'funding_source' => $fundingSource,
                'available_funds' => $fundingSource === 'broker_buying_power'
                    ? (is_numeric($truth['broker_buying_power'] ?? null) && is_numeric($truth['unleveraged_buying_power'] ?? null) ? min($truth['broker_buying_power'], $truth['unleveraged_buying_power']) : null)
                    : $truth['settled_cash'],
                'positions' => $truth['positions'], 'tradability' => $truth['tradability'],
                'trigger' => $trigger,
                'per_play_max_debit' => (float) ($allocation['per_play_max_debit'] ?? 0),
                'per_day_max_debit' => max(0.0, (float) ($allocation['per_day_max_debit'] ?? 0) - $this->spentTodayDebit()),
                'buy_threshold_usd' => (float) ($basket['buy_threshold_usd'] ?? 1),
                'min_leg_notional_usd' => (float) ($basket['min_leg_notional_usd'] ?? 1),
            ]);
            $event = $this->persist($plan, $basket, $trigger, true, false, (bool) ($cfg['kill_switch'] ?? true), null);
            $event->forceFill(['notes' => array_merge((array) $event->notes, ['truth_source' => $truth['source'], 'funding_source' => $fundingSource, 'broker_buying_power_before' => $truth['broker_buying_power'] ?? null, 'unleveraged_buying_power_before' => $truth['unleveraged_buying_power'] ?? null, 'funds_after_planned' => $plan['funds_after_planned']])])->save();
            foreach ($event->legs()->get() as $leg) {
                $leg->forceFill(['logical_order_id' => 'basket:event:'.$event->id.':'.$leg->ticker])->save();
            }
            $this->renderPlan($event, $plan);
            $this->info('Preview only. Select event '.$event->id.' in harness.basket.active_event_id to authorize its fixed plan.');

            return self::SUCCESS;
        }

        return $this->executeEvent($event, $cfg, $truth, $client, $recorder);
    }

    private function executeEvent(RebalanceEvent $event, array $cfg, array $truth, RobinhoodMcpClient $client, JsonlRecorder $recorder): int
    {
        $this->reconcileEvent($event, $truth);
        // An old or completed batch is never a fresh admission. Scheduler may
        // continue readback after close, but new fills require a new reviewed event.
        if ($event->status === RebalanceEvent::STATUS_RECONCILED || $event->as_of->copy()->timezone('America/New_York')->toDateString() !== now()->timezone('America/New_York')->toDateString()) {
            $this->info('event #'.$event->id.' readback only: completed or admission date expired');

            return self::SUCCESS;
        }
        $cfg['allocation']['max_open_positions'] = (int) data_get($cfg, 'basket.max_open_positions', 8);
        $refs = new OrderRefStore;
        $fundingGate = data_get($cfg, 'basket.funding_source') === 'broker_buying_power' ? new BasketBuyingPowerGate : null;
        $executor = new EquityExecutor($client, new RailPipeline(fundingGate: $fundingGate), $refs, $recorder, $cfg);
        $event->forceFill(['dry_run' => false, 'armed' => true, 'kill_switch' => false])->save();
        $spent = $this->spentTodayDebit();
        $reserved = 0.0;
        $placed = 0;
        $blocked = [];
        $positionSymbols = array_fill_keys(array_keys($truth['positions']), true);
        $symbolDebits = array_map(static fn ($p): float => (float) $p['market_value'], $truth['positions']);
        foreach ($truth['orders'] as $order) {
            $state = strtolower((string) ($order['state'] ?? $order['status'] ?? ''));
            if (! in_array($state, ['filled', 'cancelled', 'canceled', 'rejected', 'failed', 'expired'], true)) {
                // Ambiguous or working account orders reserve cash outside this
                // snapshot. Reconcile first, then retry; never assume zero.
                $blocked[] = 'working_or_unknown_account_order';
                break;
            }
        }
        foreach ($event->legs()->where('action', 'buy')->get() as $leg) {
            if ($blocked !== [] || $placed >= (int) data_get($cfg, 'basket.max_orders_per_tick', 5)) {
                break;
            }
            if (! (new SessionClock)->isRegularHoursOpen()) {
                $blocked[] = 'regular_session_closed';
                break;
            }
            $intent = new OrderIntent(symbol: $leg->ticker, side: 'buy', orderType: 'market', dollarAmount: (float) $leg->planned_buy_usd,
                source: 'theta:basket-rebalance:event:'.$event->id, logicalOrderId: $leg->logical_order_id,
                strategyLabel: 'theta_basket_etf', metadata: ['rebalance_event_id' => $event->id, 'rebalance_leg_id' => $leg->id]);
            $ref = $refs->peek($intent);
            if ($ref !== null && $ref->status !== 'reserved') {
                $leg->forceFill(['client_ref_id' => $ref->client_ref_id, 'broker_order_id' => $ref->broker_order_id])->save();
                $known = collect($truth['orders'])->contains(static fn ($order): bool => (string) ($order['ref_id'] ?? $order['client_ref_id'] ?? '') === $ref->client_ref_id
                    || ($ref->broker_order_id !== null && (string) ($order['id'] ?? $order['order_id'] ?? '') === $ref->broker_order_id));
                if ($ref->status !== 'placed' || ! $known) {
                    $blocked[] = 'unresolved_placement';
                    break;
                }

                continue;
            }
            // No re-use of fixtures, stale tradability, or unknown cash.
            if (($truth['tradability'][$leg->ticker] ?? null) !== true) {
                $leg->forceFill(['plan_notes' => array_merge((array) $leg->plan_notes, ['blocked' => 'fractionability_unverified_or_untradable'])])->save();

                continue;
            }
            $context = ['broker_buying_power' => $truth['broker_buying_power'] ?? null, 'unleveraged_buying_power' => $truth['unleveraged_buying_power'] ?? null, 'session_open' => true, 'tradability' => 'tradable', 'settled_cash' => $truth['settled_cash'],
                'open_order_reserved_debit' => $reserved, 'spent_today_debit' => $spent,
                'symbol_open_debit' => $symbolDebits, 'open_positions' => array_keys($positionSymbols), 'open_orders' => $truth['orders']];
            try {
                $result = $executor->execute($intent, $context);
            } catch (BrokerCreditExhausted) {
                $blocked[] = 'broker_credit_deferred';
                break;
            } catch (\Throwable $e) {
                $leg->forceFill(['plan_notes' => array_merge((array) $leg->plan_notes, ['blocked' => 'placement_ambiguous', 'error_class' => class_basename($e)])])->save();
                $blocked[] = 'placement_ambiguous';
                break;
            }
            $leg->forceFill(['client_ref_id' => $result['ref']->client_ref_id, 'broker_order_id' => $result['ref']->broker_order_id,
                'plan_notes' => array_merge((array) $leg->plan_notes, ['rail_reasons' => $result['verdict']->blockReasons()])])->save();
            if ($result['classification'] === 'LIVE_POSTED_NOT_FILLED') {
                $placed++;
                $reserved += (float) $leg->planned_buy_usd;
                $spent += (float) $leg->planned_buy_usd;
                $positionSymbols[$leg->ticker] = true;
                $symbolDebits[$leg->ticker] = ($symbolDebits[$leg->ticker] ?? 0) + (float) $leg->planned_buy_usd;
            } else {
                $blocked = $result['verdict']->blockReasons();
            }
        }
        $event->forceFill(['status' => $placed > 0 ? RebalanceEvent::STATUS_PLACED : $event->status,
            'notes' => array_merge((array) $event->notes, ['last_tick_at' => now()->toISOString(), 'last_trigger' => $this->option('trigger'), 'placed_this_tick' => $placed, 'blocked' => $blocked])])->save();
        $this->line(json_encode(['event_id' => $event->id, 'placed_this_tick' => $placed, 'filled_debit' => (float) $event->cash_deployed, 'status' => $event->status, 'blocked' => $blocked]));

        return self::SUCCESS;
    }

    private function reconcileEvent(RebalanceEvent $event, array $truth): void
    {
        $filledDebit = 0.0;
        $complete = true;
        foreach ($event->legs()->where('action', 'buy')->get() as $leg) {
            $matched = null;
            foreach ($truth['orders'] as $order) {
                if (($leg->broker_order_id !== null && (string) ($order['id'] ?? $order['order_id'] ?? '') === $leg->broker_order_id)
                    || ($leg->client_ref_id !== null && (string) ($order['ref_id'] ?? $order['client_ref_id'] ?? '') === $leg->client_ref_id)) {
                    $matched = $order;
                    break;
                }
            }
            if ($matched === null) {
                $complete = false;

                continue;
            }
            $state = strtolower((string) ($matched['state'] ?? $matched['status'] ?? 'unknown'));
            $qty = $matched['cumulative_quantity'] ?? $matched['filled_quantity'] ?? null;
            $price = $matched['average_price'] ?? $matched['average_fill_price'] ?? null;
            $verifiedFill = is_numeric($qty) && is_numeric($price) && (float) $qty > 0 && (float) $price > 0;
            if ($verifiedFill) {
                $filledDebit += (float) $qty * (float) $price + (float) ($matched['fees'] ?? 0);
            }
            $complete = $complete && $state === 'filled' && $verifiedFill;
            $journal = RobinhoodTradeJournal::query()->where('client_ref_id', $leg->client_ref_id)->where('strategy_label', 'theta_basket_etf')->first();
            if ($journal !== null) {
                $journal->forceFill(['status' => $state, 'filled_quantity' => is_numeric($qty) ? $qty : null,
                    'average_fill_price' => is_numeric($price) ? $price : null,
                    'filled_at' => $state === 'filled' && $verifiedFill ? ($journal->filled_at ?? now()) : $journal->filled_at])->save();
            }
        }
        $event->forceFill(['cash_deployed' => round($filledDebit, 2), 'settled_cash_after' => $truth['settled_cash'],
            'status' => $complete ? RebalanceEvent::STATUS_RECONCILED : $event->status,
            'notes' => array_merge((array) $event->notes, ['buying_power_after' => $truth['broker_buying_power'] ?? null, 'last_readback_at' => now()->toISOString(), 'last_trigger' => $this->option('trigger')])])->save();
    }

    private function spentTodayDebit(): float
    {
        $rows = RobinhoodTradeJournal::query()->where('side', 'buy')->whereIn('status', ['submitted', 'open', 'queued', 'confirmed', 'partially_filled', 'filled', 'closed'])
            ->whereBetween('created_at', [now()->timezone('America/New_York')->startOfDay()->utc(), now()->timezone('America/New_York')->endOfDay()->utc()])->get();
        $total = 0.0;
        foreach ($rows as $row) {
            $total += $row->dollar_amount !== null ? (float) $row->dollar_amount
                : (float) ($row->quantity ?? 0) * (float) ($row->limit_price ?? $row->average_fill_price ?? 0) * ($row->asset_class === 'option' ? 100 : 1);
        }

        return round($total, 2);
    }

    private function persist(array $plan, array $basket, string $trigger, bool $dryRun, bool $armed, bool $killSwitch, ?string $armRefusal): RebalanceEvent
    {
        return DB::transaction(function () use ($plan, $basket, $trigger, $dryRun, $armed, $killSwitch, $armRefusal): RebalanceEvent {
            $event = RebalanceEvent::query()->create([
                'owner' => (string) ($basket['owner'] ?? 'lead developer / desk'),
                'agent_id' => (string) ($basket['agent_id'] ?? 'sidecar-plumbing-agent'),
                'trigger' => $trigger,
                'as_of' => now(),
                'settled_cash_before' => $plan['settled_cash_before'],
                'basket_mv_before' => $plan['basket_mv_before'],
                'book_before' => $plan['book_before'],
                'deposit_detected' => (bool) $plan['deposit_detected'],
                'deposit_amount' => $plan['deposit_amount'],
                'thesis_spec_id' => null,
                'spec_version' => $plan['spec_version'],
                'weights_source' => $plan['weights_source'],
                'weights_payload_hash' => $plan['weights_payload_hash'],
                'total_equity' => $plan['total_equity'],
                'n_legs' => $plan['n_legs'],
                'gross_buys_usd' => $plan['gross_buys_usd'],
                'gross_sells_usd' => 0.0,
                'cash_deployed' => 0.0, // set by the arm pass; reconciled from fills
                'settled_cash_after' => null,
                'status' => $plan['status'],
                'dry_run' => $dryRun,
                'kill_switch' => $killSwitch,
                'armed' => $armed,
                'notes' => array_filter([
                    'strategy_label' => (string) ($basket['strategy_label'] ?? 'theta_basket_etf'),
                    'available_funds_for_pass' => $plan['available_funds_for_pass'],
                    'waterfill_scale' => $plan['waterfill_scale'],
                    'settled_cash_after_planned' => $plan['settled_cash_after_planned'],
                    'arm_refusal' => $armRefusal,
                ], static fn ($v) => $v !== null),
            ]);

            foreach ($plan['legs'] as $leg) {
                RebalanceLeg::query()->create([
                    'rebalance_event_id' => $event->id,
                    'ticker' => $leg['ticker'],
                    'logical_order_id' => $leg['logical_order_id'],
                    'weight_target' => $leg['weight_target'],
                    'notional_target' => $leg['notional_target'],
                    'position_before_shares' => $leg['position_before_shares'],
                    'position_before_mv' => $leg['position_before_mv'],
                    'drift_usd' => $leg['drift_usd'],
                    'planned_buy_usd' => $leg['planned_buy_usd'],
                    'action' => $leg['action'],
                    'reason' => $leg['reason'],
                    'fractionable' => $leg['fractionable'],
                    'client_ref_id' => null,
                    'broker_order_id' => null,
                    'plan_notes' => $leg['plan_notes'] ?: null,
                ]);
            }

            return $event;
        });
    }

    // ── Readback ───────────────────────────────────────────────────────────

    private function renderPlan(RebalanceEvent $event, array $plan): void
    {
        $this->newLine();
        $this->line("<info>rebalance event #{$event->id}</info>  owner={$event->owner}  agent_id={$event->agent_id}");
        $this->line(sprintf(
            'trigger=%s  deposit_detected=%s%s  weights=%s @ %s',
            $plan['trigger'],
            $plan['deposit_detected'] ? 'yes' : 'no',
            $plan['deposit_amount'] !== null ? sprintf(' (+$%.2f)', $plan['deposit_amount']) : '',
            $plan['weights_source'],
            substr($plan['weights_payload_hash'], 0, 12),
        ));
        $this->line(sprintf(
            'settled_cash=%s  basket_mv=%s  book_mv=%s  total_equity=%s',
            $this->usd($plan['settled_cash_before']),
            $this->usd($plan['basket_mv_before']),
            $this->usd($plan['book_before']),
            $this->usd($plan['total_equity']),
        ));
        $this->line(sprintf(
            'n_legs=%d  gross_buys=%s  planned_debit=%s  funds_after(planned)=%s',
            $plan['n_legs'],
            $this->usd($plan['gross_buys_usd']),
            $this->usd($plan['cash_deployed']),
            $this->usd($plan['funds_after_planned']),
        ));

        $this->line('funding_source='.$plan['funding_source'].'  funds_before='.$this->usd($plan['funds_before']));
        $byReason = [];
        foreach ($plan['legs'] as $leg) {
            $key = $leg['action'].'/'.$leg['reason'];
            $byReason[$key] = ($byReason[$key] ?? 0) + 1;
        }
        ksort($byReason);
        $this->line('legs by action/reason: '.implode('  ', array_map(
            static fn ($k, $v) => "{$k}={$v}",
            array_keys($byReason),
            array_values($byReason),
        )));

        $buys = array_values(array_filter($plan['legs'], static fn ($l) => $l['action'] === 'buy'));
        usort($buys, static fn ($a, $b) => $b['planned_buy_usd'] <=> $a['planned_buy_usd']);
        if ($buys !== []) {
            $this->table(
                ['ticker', 'weight%', 'target$', 'pos_mv$', 'drift$', 'buy$', 'reason', 'fract'],
                array_map(fn ($l) => [
                    $l['ticker'],
                    sprintf('%.2f', $l['weight_target'] * 100),
                    sprintf('%.2f', $l['notional_target']),
                    sprintf('%.2f', $l['position_before_mv']),
                    sprintf('%.2f', $l['drift_usd']),
                    sprintf('%.2f', $l['planned_buy_usd']),
                    $l['reason'],
                    $l['fractionable'] === null ? '?' : ($l['fractionable'] ? 'y' : 'n'),
                ], array_slice($buys, 0, 40)),
            );
        }

        $dropped = array_values(array_filter($plan['legs'], static fn ($l) => $l['action'] === 'drop'));
        if ($dropped !== []) {
            $this->warn('dropped_untradable (no fractional notional): '.implode(', ', array_map(static fn ($l) => $l['ticker'], $dropped)));
        }
    }

    private function usd(mixed $v): string
    {
        return $v === null ? 'UNVERIFIED' : sprintf('$%.2f', (float) $v);
    }
}
