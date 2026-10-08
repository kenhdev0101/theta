<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Robinhood\Options\OptionOrderIntent;
use App\Robinhood\Options\OptionRailPipeline;
use App\Robinhood\Options\OptionRailVerdict;
use PHPUnit\Framework\TestCase;

class OptionRailPipelineTest extends TestCase
{
    private OptionRailPipeline $pipeline;

    protected function setUp(): void
    {
        $this->pipeline = new OptionRailPipeline;
    }

    private function cfg(array $overrides = []): array
    {
        return array_replace_recursive([
            'kill_switch' => false,
            'agentic_account' => '872449178',
            'options' => [
                'enabled' => true,
                'max_spread_bps' => 1500.0,
                'max_contract_ask' => 5.00,
                'per_play_max_debit' => 75.00,
                'per_day_max_debit' => 250.00,
                'daily_gross_entry_debit' => 250.00,
                'per_underlying_max_debit' => 100.00,
                'max_open_positions' => 4,
                'min_quote_fresh_seconds' => 10,
                'required_tools' => ['get_option_chains', 'review_option_order', 'place_option_order'],
            ],
        ], $overrides);
    }

    private function context(array $overrides = []): array
    {
        return array_merge([
            'account' => [
                'account_number' => '872449178',
                'option_level' => 'option_level_2',
                'agentic_allowed' => true,
            ],
            'capability_tools' => ['get_option_chains', 'review_option_order', 'place_option_order'],
            'contract' => [
                'option_id' => 'opt-uuid-1',
                'chain_id' => 'chain-uuid-1',
                'tradability' => 'tradable',
                'state' => 'active',
            ],
            'quote' => [
                'bid' => 0.48,
                'ask' => 0.52,
                'mid' => 0.50,
                'spread_bps' => 800.0,
                'age_seconds' => 2.0,
                'stale' => false,
            ],
            'session_open' => true,
            'settled_cash' => 245.83,
            'buying_power' => 245.83,
            'options_spent_today_debit' => 0.0,
            'options_open_debit' => 0.0,
            'underlying_open_debit' => [],
            'open_option_positions' => [],
            'open_option_orders' => [],
            'review' => ['ok' => true, 'alerts' => []],
        ], $overrides);
    }

    private function intent(array $overrides = []): OptionOrderIntent
    {
        $defaults = [
            'underlyingSymbol' => 'SPY',
            'optionType' => 'call',
            'expirationDate' => '2026-06-15',
            'strikePrice' => 740.0,
            'side' => 'buy',
            'positionEffect' => 'open',
            'quantity' => 1,
            'limitPrice' => 0.52,
            'optionId' => 'opt-uuid-1',
            'chainId' => 'chain-uuid-1',
            'source' => 'test',
        ];
        $args = array_merge($defaults, $overrides);

        return new OptionOrderIntent(...$args);
    }

    private function evaluate(?OptionOrderIntent $intent = null, array $cfg = [], array $ctx = []): OptionRailVerdict
    {
        return $this->pipeline->evaluate($intent ?? $this->intent(), $this->cfg($cfg), $this->context($ctx));
    }

    public function test_green_path_allows_long_call_bto(): void
    {
        $verdict = $this->evaluate();

        $this->assertSame(OptionRailVerdict::ALLOW, $verdict->verdict(), implode(', ', $verdict->blockReasons()));
        $this->assertTrue($verdict->allowed());
    }

    public function test_include_caps_false_skips_the_capital_gate(): void
    {
        // Capital-short context: debit $52 exceeds $10 settled/buying power.
        $capitalShort = $this->context(['settled_cash' => 10.0, 'buying_power' => 10.0]);

        $blocked = $this->pipeline->evaluate($this->intent(), $this->cfg(), $capitalShort, includeReview: false, includeCaps: true);
        $this->assertSame(OptionRailPipeline::BLOCK_CAP, $blocked->verdict());

        // Capital gate excluded: the same intent is structurally allowed, so
        // the decision layer can evaluate it independent of account capital.
        $allowed = $this->pipeline->evaluate($this->intent(), $this->cfg(), $capitalShort, includeReview: false, includeCaps: false);
        $this->assertSame(OptionRailVerdict::ALLOW, $allowed->verdict());
    }

    public function test_option_level_2_passes_and_level_1_blocks_permission(): void
    {
        $this->assertSame(OptionRailVerdict::ALLOW, $this->evaluate()->verdict());

        $blocked = $this->evaluate(ctx: ['account' => [
            'account_number' => '872449178', 'option_level' => 'option_level_1', 'agentic_allowed' => true,
        ]]);
        $this->assertSame(OptionRailPipeline::BLOCK_PERMISSION, $blocked->verdict());
        $this->assertContains('permission:option_level_insufficient', $blocked->blockReasons());
    }

    public function test_non_agentic_account_blocks_permission(): void
    {
        $verdict = $this->evaluate(ctx: ['account' => [
            'account_number' => '872449178', 'option_level' => 'option_level_3', 'agentic_allowed' => false,
        ]]);

        $this->assertSame(OptionRailPipeline::BLOCK_PERMISSION, $verdict->verdict());
        $this->assertContains('permission:account_not_agentic_allowed', $verdict->blockReasons());
    }

    public function test_account_pin_mismatch_blocks_permission(): void
    {
        $verdict = $this->evaluate(ctx: ['account' => [
            'account_number' => '717293732', 'option_level' => 'option_level_3', 'agentic_allowed' => true,
        ]]);

        $this->assertSame(OptionRailPipeline::BLOCK_PERMISSION, $verdict->verdict());
        $this->assertContains('permission:account_pin_mismatch', $verdict->blockReasons());
    }

    public function test_unverified_account_capability_blocks_permission(): void
    {
        $verdict = $this->evaluate(ctx: ['account' => null]);

        $this->assertSame(OptionRailPipeline::BLOCK_PERMISSION, $verdict->verdict());
        $this->assertContains('permission:account_capability_unverified', $verdict->blockReasons());
    }

    public function test_short_open_is_not_a_level_2_strategy(): void
    {
        $verdict = $this->evaluate($this->intent(['side' => 'sell', 'positionEffect' => 'open']));

        $this->assertContains('strategy:strategy_not_allowed_short_open', $verdict->blockReasons());
    }

    public function test_buy_to_close_is_out_of_scope(): void
    {
        $verdict = $this->evaluate($this->intent(['side' => 'buy', 'positionEffect' => 'close']));

        $this->assertContains('strategy:strategy_not_allowed_buy_close', $verdict->blockReasons());
    }

    public function test_sell_to_close_passes_strategy_gate(): void
    {
        $verdict = $this->evaluate($this->intent(['side' => 'sell', 'positionEffect' => 'close', 'limitPrice' => 0.48]));

        $this->assertSame(OptionRailVerdict::ALLOW, $verdict->verdict(), implode(', ', $verdict->blockReasons()));
    }

    public function test_market_closed_blocks(): void
    {
        $verdict = $this->evaluate(ctx: ['session_open' => false]);

        $this->assertSame(OptionRailPipeline::BLOCK_MARKET_CLOSED, $verdict->verdict());
    }

    public function test_stale_quote_blocks(): void
    {
        $verdict = $this->evaluate(ctx: ['quote' => [
            'bid' => 0.48, 'ask' => 0.52, 'spread_bps' => 800.0, 'age_seconds' => 120.0, 'stale' => true,
        ]]);

        $this->assertSame(OptionRailPipeline::BLOCK_STALE_QUOTE, $verdict->verdict());
        $this->assertContains('quote_freshness:quote_stale', $verdict->blockReasons());
    }

    public function test_missing_bid_or_ask_blocks_as_stale_quote(): void
    {
        $verdict = $this->evaluate(ctx: ['quote' => [
            'bid' => null, 'ask' => 0.52, 'spread_bps' => null, 'age_seconds' => 1.0, 'stale' => false,
        ]]);

        $this->assertSame(OptionRailPipeline::BLOCK_STALE_QUOTE, $verdict->verdict());
        $this->assertContains('quote_freshness:bid_or_ask_missing', $verdict->blockReasons());
    }

    public function test_wide_spread_blocks(): void
    {
        $verdict = $this->evaluate(ctx: ['quote' => [
            'bid' => 0.10, 'ask' => 0.50, 'mid' => 0.30, 'spread_bps' => 13333.0, 'age_seconds' => 1.0, 'stale' => false,
        ]]);

        $this->assertSame(OptionRailPipeline::BLOCK_WIDE_SPREAD, $verdict->verdict());
        $this->assertContains('spread:spread_above_max', $verdict->blockReasons());
    }

    public function test_convex_resting_entry_uses_thesis_exit_risk_instead_of_fixed_spread_cap(): void
    {
        $intent = $this->intent([
            'source' => 'theta:convex-probe', 'strategyLabel' => 'convex_thesis_probe', 'limitPrice' => 0.44,
            'metadata' => ['entry_mode' => 'convex_bounded_resting', 'hard_stop_pct' => 30.0],
        ]);
        $quote = ['bid' => 0.35, 'ask' => 0.48, 'mid' => 0.415, 'spread_bps' => 3132.53,
            'age_seconds' => 1.0, 'stale' => false];
        $verdict = $this->evaluate($intent, ['options' => ['max_spread_bps' => 500],
            'convex' => ['entry_repeg_cap_frac' => 0.5]], ['quote' => $quote]);

        $this->assertSame(OptionRailVerdict::ALLOW, $verdict->verdict(), implode(', ', $verdict->blockReasons()));
        $spread = collect($verdict->toArray()['ledger'])->firstWhere('gate', 'spread');
        $this->assertSame(20.45, $spread['detail']['immediate_exit_loss_pct']);

        $crossing = $this->evaluate($this->intent([
            'source' => 'theta:convex-probe', 'strategyLabel' => 'convex_thesis_probe', 'limitPrice' => 0.48,
            'metadata' => ['entry_mode' => 'convex_bounded_resting', 'hard_stop_pct' => 30.0],
        ]), ['options' => ['max_spread_bps' => 500]], ['quote' => $quote]);
        $this->assertContains('spread:convex_entry_exceeds_exit_risk_or_reprice_cap', $crossing->blockReasons());

        $smallRisk = $this->evaluate($this->intent([
            'source' => 'theta:convex-probe', 'strategyLabel' => 'convex_thesis_probe', 'limitPrice' => 0.42,
            'metadata' => ['entry_mode' => 'convex_bounded_resting', 'hard_stop_pct' => 15.0],
        ]), ['options' => ['max_spread_bps' => 500]], ['quote' => $quote]);
        $this->assertContains('spread:convex_entry_exceeds_exit_risk_or_reprice_cap', $smallRisk->blockReasons());
    }

    public function test_wide_spread_does_not_block_sell_to_close_at_live_bid(): void
    {
        $verdict = $this->evaluate(
            $this->intent(['side' => 'sell', 'positionEffect' => 'close', 'limitPrice' => 0.06]),
            ctx: ['quote' => [
                'bid' => 0.06, 'ask' => 0.08, 'mid' => 0.07, 'spread_bps' => 2857.14, 'age_seconds' => 1.0, 'stale' => false,
            ]],
        );

        $this->assertSame(OptionRailVerdict::ALLOW, $verdict->verdict(), implode(', ', $verdict->blockReasons()));
        $spread = collect($verdict->toArray()['ledger'])->firstWhere('gate', 'spread');
        $this->assertTrue($spread['detail']['liquidation_bypass'] ?? false);
    }

    public function test_ask_above_max_contract_ask_blocks(): void
    {
        $verdict = $this->evaluate(
            $this->intent(['limitPrice' => 0.5]),
            ctx: ['quote' => ['bid' => 6.40, 'ask' => 6.50, 'mid' => 6.45, 'spread_bps' => 155.0, 'age_seconds' => 1.0, 'stale' => false]],
        );

        $this->assertSame(OptionRailPipeline::BLOCK_WIDE_SPREAD, $verdict->verdict());
        $this->assertContains('spread:ask_above_max', $verdict->blockReasons());
    }

    public function test_buy_limit_below_live_ask_blocks_as_unmarketable(): void
    {
        $verdict = $this->evaluate(
            $this->intent(['limitPrice' => 0.05]),
            ctx: ['quote' => ['bid' => 4.01, 'ask' => 4.04, 'mid' => 4.025, 'spread_bps' => 75.66, 'age_seconds' => 1.0, 'stale' => false]],
        );

        $this->assertSame(OptionRailPipeline::BLOCK_PRICE, $verdict->verdict());
        $this->assertContains('marketability:buy_limit_below_live_ask', $verdict->blockReasons());
    }

    public function test_sell_limit_above_live_bid_blocks_as_unmarketable(): void
    {
        $verdict = $this->evaluate(
            $this->intent(['side' => 'sell', 'positionEffect' => 'close', 'limitPrice' => 0.60]),
            ctx: ['quote' => ['bid' => 0.48, 'ask' => 0.52, 'mid' => 0.50, 'spread_bps' => 800.0, 'age_seconds' => 1.0, 'stale' => false]],
        );

        $this->assertSame(OptionRailPipeline::BLOCK_PRICE, $verdict->verdict());
        $this->assertContains('marketability:sell_limit_above_live_bid', $verdict->blockReasons());
    }

    public function test_oversize_debit_blocks_cap(): void
    {
        // 2 contracts x 0.60 x 100 = 120 > 75 per-play.
        $verdict = $this->evaluate($this->intent(['quantity' => 2, 'limitPrice' => 0.60]));

        $this->assertSame(OptionRailPipeline::BLOCK_CAP, $verdict->verdict());
        $this->assertContains('caps:per_play_cap_exceeded', $verdict->blockReasons());
    }

    public function test_missing_limit_price_blocks_cap_as_unbounded(): void
    {
        $verdict = $this->evaluate($this->intent(['limitPrice' => null]));

        $this->assertSame(OptionRailPipeline::BLOCK_PRICE, $verdict->verdict());
        $this->assertContains('marketability:limit_price_missing', $verdict->blockReasons());
        $this->assertContains('caps:debit_unbounded_limit_price_required', $verdict->blockReasons());
    }

    public function test_per_day_cap_blocks(): void
    {
        $verdict = $this->evaluate(ctx: ['options_open_debit' => 240.0]);

        $this->assertContains('caps:per_day_cap_exceeded', $verdict->blockReasons());
    }

    public function test_closed_intraday_turnover_does_not_consume_open_exposure_cap(): void
    {
        $verdict = $this->evaluate(ctx: [
            'options_spent_today_debit' => 240.0,
            'options_open_debit' => 0.0,
            'underlying_open_debit' => [],
        ], cfg: ['options' => ['daily_gross_entry_debit' => 500.0]]);

        $this->assertSame(OptionRailVerdict::ALLOW, $verdict->verdict(), implode(', ', $verdict->blockReasons()));
    }

    public function test_daily_gross_entry_debit_blocks_recycling_after_top_off(): void
    {
        $verdict = $this->evaluate(ctx: [
            'options_spent_today_debit' => 240.0,
            'options_open_debit' => 0.0,
            'settled_cash' => 1000.0,
            'buying_power' => 1000.0,
        ]);

        $this->assertSame(OptionRailPipeline::BLOCK_CAP, $verdict->verdict());
        $this->assertContains('caps:daily_gross_entry_debit_exceeded', $verdict->blockReasons());
    }

    public function test_per_underlying_cap_blocks(): void
    {
        $verdict = $this->evaluate(ctx: ['underlying_open_debit' => ['SPY' => 80.0]]);

        $this->assertContains('caps:per_underlying_cap_exceeded', $verdict->blockReasons());
    }

    public function test_max_open_positions_blocks_new_opens(): void
    {
        $verdict = $this->evaluate(ctx: ['open_option_positions' => [1, 2, 3, 4]]);

        $this->assertContains('caps:max_open_positions_reached', $verdict->blockReasons());
    }

    public function test_unverified_settled_cash_blocks_cap(): void
    {
        $verdict = $this->evaluate(ctx: ['settled_cash' => null]);

        $this->assertSame(OptionRailPipeline::BLOCK_CAP, $verdict->verdict());
        $this->assertContains('caps:settled_cash_unverified', $verdict->blockReasons());
    }

    public function test_insufficient_buying_power_blocks_cap(): void
    {
        $verdict = $this->evaluate(ctx: ['buying_power' => 20.0]);

        $this->assertSame(OptionRailPipeline::BLOCK_CAP, $verdict->verdict());
        $this->assertContains('caps:buying_power_insufficient', $verdict->blockReasons());
        $this->assertSame([
            'buying_power' => 20.0,
            'debit' => 52.0,
        ], $verdict->results[8]->detail);
    }

    public function test_duplicate_open_order_for_same_option_blocks(): void
    {
        $verdict = $this->evaluate(ctx: ['open_option_orders' => [[
            'id' => 'b-1',
            'state' => 'queued',
            'legs' => [['option_id' => 'opt-uuid-1', 'side' => 'buy', 'position_effect' => 'open']],
        ]]]);

        $this->assertSame(OptionRailPipeline::BLOCK_DUPLICATE, $verdict->verdict());
        $this->assertContains('duplicate:duplicate_open_order_same_option', $verdict->blockReasons());
    }

    public function test_attempted_ref_blocks_until_reconciled(): void
    {
        $verdict = $this->evaluate(ctx: ['existing_ref' => ['client_ref_id' => 'r-1', 'status' => 'failed']]);

        $this->assertSame(OptionRailPipeline::BLOCK_DUPLICATE, $verdict->verdict());
        $this->assertContains('duplicate:ref_attempted_requires_reconcile', $verdict->blockReasons());
    }

    public function test_review_alert_blocks_placement(): void
    {
        $verdict = $this->evaluate(ctx: ['review' => [
            'ok' => true,
            'alerts' => [['severity' => 'warning', 'message' => 'Insufficient buying power for this order']],
        ]]);

        $this->assertSame(OptionRailPipeline::BLOCK_REVIEW_ALERT, $verdict->verdict());
    }

    public function test_kill_switch_blocks(): void
    {
        $verdict = $this->evaluate(cfg: ['kill_switch' => true]);

        $this->assertSame(OptionRailPipeline::BLOCK_KILL_SWITCH, $verdict->verdict());
    }

    public function test_missing_kill_switch_config_blocks_not_allows(): void
    {
        $cfg = $this->cfg();
        unset($cfg['kill_switch']);

        $verdict = $this->pipeline->evaluate($this->intent(), $cfg, $this->context());

        $this->assertSame(OptionRailPipeline::BLOCK_KILL_SWITCH, $verdict->verdict(), 'unknown is never disarmed');
    }

    public function test_unconfigured_caps_block_fail_closed(): void
    {
        $zeroPlay = $this->evaluate(cfg: ['options' => ['per_play_max_debit' => 0]]);
        $this->assertSame(OptionRailPipeline::BLOCK_CAP, $zeroPlay->verdict());
        $this->assertContains('caps:per_play_cap_unconfigured', $zeroPlay->blockReasons());

        $zeroDay = $this->evaluate(cfg: ['options' => ['per_day_max_debit' => 0]]);
        $this->assertContains('caps:per_day_cap_unconfigured', $zeroDay->blockReasons());

        $zeroGross = $this->evaluate(cfg: ['options' => ['daily_gross_entry_debit' => 0]]);
        $this->assertContains('caps:daily_gross_entry_debit_unconfigured', $zeroGross->blockReasons());

        $zeroUnderlying = $this->evaluate(cfg: ['options' => ['per_underlying_max_debit' => 0]]);
        $this->assertContains('caps:per_underlying_cap_unconfigured', $zeroUnderlying->blockReasons());

        $zeroPositions = $this->evaluate(cfg: ['options' => ['max_open_positions' => 0]]);
        $this->assertContains('caps:max_open_positions_unconfigured', $zeroPositions->blockReasons());
    }

    public function test_closes_skip_open_position_count_but_not_other_caps(): void
    {
        // A sell-to-close at max positions must still be allowed (it reduces).
        $verdict = $this->evaluate(
            $this->intent(['side' => 'sell', 'positionEffect' => 'close', 'limitPrice' => 0.48]),
            ctx: ['open_option_positions' => [1, 2, 3, 4]],
        );

        $this->assertSame(OptionRailVerdict::ALLOW, $verdict->verdict(), implode(', ', $verdict->blockReasons()));
    }

    public function test_inactive_contract_state_blocks_capability(): void
    {
        $verdict = $this->evaluate(ctx: ['contract' => [
            'option_id' => 'opt-uuid-1', 'tradability' => 'tradable', 'state' => 'inactive',
        ]]);

        $this->assertSame(OptionRailPipeline::BLOCK_CAPABILITY, $verdict->verdict());
        $this->assertContains('capability:contract_not_active', $verdict->blockReasons());
    }

    public function test_non_regular_market_hours_blocks_in_v0(): void
    {
        $verdict = $this->evaluate($this->intent(['marketHours' => 'regular_curb_hours']));

        $this->assertSame(OptionRailPipeline::BLOCK_MARKET_CLOSED, $verdict->verdict());
        $this->assertContains('session:non_regular_hours_not_supported_v0', $verdict->blockReasons());
    }

    public function test_partially_filled_open_order_blocks_duplicate(): void
    {
        $verdict = $this->evaluate(ctx: ['open_option_orders' => [[
            'id' => 'b-2',
            'state' => 'partially_filled',
            'legs' => [['option_id' => 'opt-uuid-1', 'side' => 'buy', 'position_effect' => 'open']],
        ]]]);

        $this->assertSame(OptionRailPipeline::BLOCK_DUPLICATE, $verdict->verdict());
    }

    public function test_options_disabled_blocks_capability(): void
    {
        $verdict = $this->evaluate(cfg: ['options' => ['enabled' => false]]);

        $this->assertSame(OptionRailPipeline::BLOCK_CAPABILITY, $verdict->verdict());
    }

    public function test_untradable_contract_blocks_capability(): void
    {
        $verdict = $this->evaluate(ctx: ['contract' => [
            'option_id' => 'opt-uuid-1', 'tradability' => 'untradable', 'state' => 'active',
        ]]);

        $this->assertSame(OptionRailPipeline::BLOCK_CAPABILITY, $verdict->verdict());
        $this->assertContains('capability:contract_untradable', $verdict->blockReasons());
    }

    public function test_missing_broker_tool_blocks_capability(): void
    {
        $verdict = $this->evaluate(ctx: ['capability_tools' => ['get_option_chains']]);

        $this->assertSame(OptionRailPipeline::BLOCK_CAPABILITY, $verdict->verdict());
        $this->assertContains('capability:broker_tools_missing', $verdict->blockReasons());
    }

    public function test_pre_review_phase_skips_review_gate_but_full_phase_requires_it(): void
    {
        $pre = $this->pipeline->evaluate($this->intent(), $this->cfg(), $this->context(['review' => null]), includeReview: false);
        $this->assertSame(OptionRailVerdict::ALLOW, $pre->verdict());

        $full = $this->pipeline->evaluate($this->intent(), $this->cfg(), $this->context(['review' => null]));
        $this->assertSame(OptionRailPipeline::BLOCK_REVIEW_ALERT, $full->verdict());
        $this->assertContains('review:review_missing', $full->blockReasons());
    }

    public function test_blocked_verdict_carries_full_ledger(): void
    {
        $verdict = $this->evaluate(cfg: ['kill_switch' => true], ctx: ['settled_cash' => null]);

        $this->assertCount(11, $verdict->results);
        $this->assertGreaterThanOrEqual(2, count($verdict->blockReasons()));
        $this->assertSame(OptionRailPipeline::BLOCK_KILL_SWITCH, $verdict->verdict(), 'verdict is the FIRST block category');
    }
}
