<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Robinhood\Rails\OrderIntent;
use App\Robinhood\Rails\RailPipeline;
use PHPUnit\Framework\TestCase;

class RailPipelineTest extends TestCase
{
    private function cfg(array $overrides = []): array
    {
        return array_replace_recursive([
            'kill_switch' => false,
            'allocation' => [
                'per_day_max_debit' => 200.0,
                'per_play_max_debit' => 50.0,
                'per_symbol_max_debit' => 50.0,
                'max_open_positions' => 8,
            ],
        ], $overrides);
    }

    private function context(array $overrides = []): array
    {
        return array_merge([
            'session_open' => true,
            'tradability' => 'tradable',
            'settled_cash' => 100.0,
            'open_order_reserved_debit' => 0.0,
            'spent_today_debit' => 0.0,
            'symbol_open_debit' => [],
            'open_orders' => [],
            'open_positions' => [],
            'review' => ['ok' => true, 'alerts' => []],
        ], $overrides);
    }

    private function buyIntent(float $dollars = 25.0): OrderIntent
    {
        return new OrderIntent(symbol: 'XYZ', side: 'buy', orderType: 'limit', dollarAmount: $dollars);
    }

    public function test_allows_bounded_buy_when_every_gate_is_green(): void
    {
        $verdict = (new RailPipeline)->evaluate($this->buyIntent(), $this->cfg(), $this->context());

        $this->assertTrue($verdict->allowed(), implode(', ', $verdict->blockReasons()));
        $this->assertCount(7, $verdict->results, 'full gating ledger must report every gate');
    }

    public function test_kill_switch_blocks_with_named_reason(): void
    {
        $verdict = (new RailPipeline)->evaluate(
            $this->buyIntent(),
            $this->cfg(['kill_switch' => true]),
            $this->context(),
        );

        $this->assertFalse($verdict->allowed());
        $this->assertContains('kill_switch:kill_switch_engaged', $verdict->blockReasons());
    }

    public function test_missing_kill_switch_config_blocks_not_allows(): void
    {
        $cfg = $this->cfg();
        unset($cfg['kill_switch']);

        $verdict = (new RailPipeline)->evaluate($this->buyIntent(), $cfg, $this->context());

        $this->assertContains('kill_switch:kill_switch_engaged', $verdict->blockReasons());
    }

    public function test_unverified_settled_cash_blocks(): void
    {
        $verdict = (new RailPipeline)->evaluate(
            $this->buyIntent(),
            $this->cfg(),
            $this->context(['settled_cash' => null]),
        );

        $this->assertContains('settled_cash:settled_cash_unverified', $verdict->blockReasons());
    }

    public function test_insufficient_settled_cash_blocks(): void
    {
        $verdict = (new RailPipeline)->evaluate(
            $this->buyIntent(25.0),
            $this->cfg(),
            $this->context(['settled_cash' => 10.0]),
        );

        $this->assertContains('settled_cash:settled_cash_insufficient', $verdict->blockReasons());
    }

    public function test_open_order_reservation_reduces_available_settled_cash(): void
    {
        $verdict = (new RailPipeline)->evaluate(
            $this->buyIntent(25.0),
            $this->cfg(),
            $this->context(['settled_cash' => 30.0, 'open_order_reserved_debit' => 10.0]),
        );

        $this->assertContains('settled_cash:settled_cash_insufficient', $verdict->blockReasons());
    }

    public function test_per_play_cap_blocks(): void
    {
        $verdict = (new RailPipeline)->evaluate(
            $this->buyIntent(75.0),
            $this->cfg(),
            $this->context(['settled_cash' => 1000.0]),
        );

        $this->assertContains('sizing:per_play_cap_exceeded', $verdict->blockReasons());
    }

    public function test_per_day_cap_blocks(): void
    {
        $verdict = (new RailPipeline)->evaluate(
            $this->buyIntent(25.0),
            $this->cfg(),
            $this->context(['spent_today_debit' => 190.0]),
        );

        $this->assertContains('allocation:per_day_cap_exceeded', $verdict->blockReasons());
    }

    public function test_unverified_spent_today_blocks(): void
    {
        $verdict = (new RailPipeline)->evaluate(
            $this->buyIntent(),
            $this->cfg(),
            $this->context(['spent_today_debit' => null]),
        );

        $this->assertContains('allocation:spent_today_unverified', $verdict->blockReasons());
    }

    public function test_per_symbol_cap_blocks(): void
    {
        $verdict = (new RailPipeline)->evaluate(
            $this->buyIntent(30.0),
            $this->cfg(),
            $this->context(['symbol_open_debit' => ['XYZ' => 40.0]]),
        );

        $this->assertContains('allocation:per_symbol_cap_exceeded', $verdict->blockReasons());
    }

    public function test_unverified_symbol_open_debit_blocks(): void
    {
        $verdict = (new RailPipeline)->evaluate(
            $this->buyIntent(),
            $this->cfg(),
            $this->context(['symbol_open_debit' => null]),
        );

        $this->assertContains('allocation:symbol_open_debit_unverified', $verdict->blockReasons());
    }

    public function test_failed_ref_blocks_until_reconciled(): void
    {
        $verdict = (new RailPipeline)->evaluate(
            $this->buyIntent(),
            $this->cfg(),
            $this->context(['existing_ref' => [
                'client_ref_id' => 'uuid-1',
                'status' => 'failed',
                'broker_order_id' => null,
            ]]),
        );

        $this->assertContains('idempotency:ref_attempted_requires_reconcile', $verdict->blockReasons());
    }

    public function test_accepted_broker_state_counts_as_duplicate_open_order(): void
    {
        $verdict = (new RailPipeline)->evaluate(
            $this->buyIntent(),
            $this->cfg(),
            $this->context(['open_orders' => [
                ['symbol' => 'XYZ', 'side' => 'buy', 'state' => 'accepted', 'id' => 'abc-2'],
            ]]),
        );

        $this->assertContains('idempotency:duplicate_open_order', $verdict->blockReasons());
    }

    public function test_duplicate_open_order_blocks(): void
    {
        $verdict = (new RailPipeline)->evaluate(
            $this->buyIntent(),
            $this->cfg(),
            $this->context(['open_orders' => [
                ['symbol' => 'XYZ', 'side' => 'buy', 'state' => 'queued', 'id' => 'abc-1'],
            ]]),
        );

        $this->assertContains('idempotency:duplicate_open_order', $verdict->blockReasons());
    }

    public function test_already_placed_ref_blocks(): void
    {
        $verdict = (new RailPipeline)->evaluate(
            $this->buyIntent(),
            $this->cfg(),
            $this->context(['existing_ref' => [
                'client_ref_id' => 'uuid-1',
                'status' => 'placed',
                'broker_order_id' => 'b-1',
            ]]),
        );

        $this->assertContains('idempotency:ref_already_placed', $verdict->blockReasons());
    }

    public function test_closed_session_blocks(): void
    {
        $verdict = (new RailPipeline)->evaluate(
            $this->buyIntent(),
            $this->cfg(),
            $this->context(['session_open' => false]),
        );

        $this->assertContains('session:session_closed', $verdict->blockReasons());
    }

    public function test_unknown_session_state_blocks(): void
    {
        $verdict = (new RailPipeline)->evaluate(
            $this->buyIntent(),
            $this->cfg(),
            $this->context(['session_open' => null]),
        );

        $this->assertContains('session:session_state_unverified', $verdict->blockReasons());
    }

    public function test_missing_review_blocks(): void
    {
        $verdict = (new RailPipeline)->evaluate(
            $this->buyIntent(),
            $this->cfg(),
            $this->context(['review' => null]),
        );

        $this->assertContains('review:review_missing', $verdict->blockReasons());
    }

    public function test_review_buying_power_alert_blocks(): void
    {
        $verdict = (new RailPipeline)->evaluate(
            $this->buyIntent(),
            $this->cfg(),
            $this->context(['review' => [
                'ok' => true,
                'alerts' => [['severity' => 'warning', 'message' => 'Order exceeds available buying power']],
            ]]),
        );

        $this->assertContains('review:review_alert_blocking', $verdict->blockReasons());
    }

    public function test_unbounded_market_buy_blocks_on_sizing(): void
    {
        $intent = new OrderIntent(symbol: 'XYZ', side: 'buy', orderType: 'market');

        $verdict = (new RailPipeline)->evaluate($intent, $this->cfg(), $this->context());

        $this->assertContains('sizing:debit_unbounded', $verdict->blockReasons());
    }

    public function test_dollar_order_outside_regular_hours_blocks(): void
    {
        $intent = new OrderIntent(symbol: 'XYZ', side: 'buy', dollarAmount: 25.0, marketHours: 'extended_hours');

        $verdict = (new RailPipeline)->evaluate($intent, $this->cfg(), $this->context());

        $this->assertContains('session:dollar_order_outside_regular_hours', $verdict->blockReasons());
    }

    public function test_sell_passes_cash_gates_but_still_respects_kill_switch(): void
    {
        $sell = new OrderIntent(symbol: 'XYZ', side: 'sell', orderType: 'limit', quantity: 1.0, limitPrice: 10.0);

        $allowed = (new RailPipeline)->evaluate($sell, $this->cfg(), $this->context(['settled_cash' => null, 'spent_today_debit' => null]));
        $this->assertTrue($allowed->allowed(), implode(', ', $allowed->blockReasons()));

        $killed = (new RailPipeline)->evaluate($sell, $this->cfg(['kill_switch' => true]), $this->context());
        $this->assertFalse($killed->allowed());
    }

    public function test_blocked_verdict_still_reports_full_ledger(): void
    {
        $verdict = (new RailPipeline)->evaluate(
            $this->buyIntent(),
            $this->cfg(['kill_switch' => true]),
            $this->context(['settled_cash' => null]),
        );

        $this->assertCount(7, $verdict->results);
        $this->assertGreaterThanOrEqual(2, count($verdict->blockReasons()));
    }
}
