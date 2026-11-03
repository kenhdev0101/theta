<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Robinhood\Basket\BasketRebalancePlanner;
use App\Robinhood\Basket\BasketWeights;
use Tests\TestCase;

/**
 * Deterministic diff / water-fill / fractionability-gate proof (thesis
 * §11.4/§11.6). Pure arrays in → plan out; no broker, no DB.
 */
class BasketRebalancePlannerTest extends TestCase
{
    /** @var list<string> */
    private array $tmp = [];

    protected function tearDown(): void
    {
        foreach ($this->tmp as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    /** 3-name basket: AAA 50, BBB 30, CCC 20 (normalized to 0.5/0.3/0.2). */
    private function weights(): BasketWeights
    {
        $path = tempnam(sys_get_temp_dir(), 'basket_w_').'.csv';
        file_put_contents($path, implode("\n", [
            'rank,ticker,name,lane,capex_beta,handle,score,weight_pct',
            '1,AAA,Alpha,lane,0,public,5,50',
            '2,BBB,Beta,lane,0,public,5,30',
            '3,CCC,Gamma,lane,0,public,5,20',
        ]));
        $this->tmp[] = $path;

        return BasketWeights::fromCsv($path);
    }

    private function baseParams(array $overrides = []): array
    {
        return array_replace([
            'settled_cash' => 100.0,
            'positions' => [],
            'tradability' => ['AAA' => true, 'BBB' => true, 'CCC' => true],
            'per_play_max_debit' => 25000.0,
            'per_day_max_debit' => 50000.0,
            'buy_threshold_usd' => 1.0,
            'min_leg_notional_usd' => 1.0,
            'logical_order_prefix' => 'basket:',
            'trigger' => 'manual',
        ], $overrides);
    }

    private function legs(array $plan): array
    {
        $byTicker = [];
        foreach ($plan['legs'] as $leg) {
            $byTicker[$leg['ticker']] = $leg;
        }

        return $byTicker;
    }

    public function test_weights_normalize_to_a_simplex_and_hash_is_reproducible(): void
    {
        $a = $this->weights();
        $b = $this->weights();

        $this->assertEqualsWithDelta(1.0, array_sum(array_map(static fn ($r) => $r['weight'], $a->rows)), 1e-9);
        $this->assertSame($a->payloadHash, $b->payloadHash, 'same CSV content → same weights_payload_hash');
        $this->assertSame(3, $a->count());
    }

    public function test_diff_math_targets_are_weight_times_total_equity(): void
    {
        $plan = (new BasketRebalancePlanner)->plan($this->weights(), $this->baseParams());

        $this->assertSame('planned', $plan['status']);
        $this->assertEqualsWithDelta(100.0, $plan['total_equity'], 0.001);

        $legs = $this->legs($plan);
        $this->assertEqualsWithDelta(50.0, $legs['AAA']['notional_target'], 0.01);
        $this->assertEqualsWithDelta(30.0, $legs['BBB']['notional_target'], 0.01);
        $this->assertEqualsWithDelta(20.0, $legs['CCC']['notional_target'], 0.01);
        $this->assertEqualsWithDelta(50.0, $legs['AAA']['drift_usd'], 0.01);

        // All-cash, all underweight → Σ buys == settled cash (full deployment).
        $this->assertEqualsWithDelta(100.0, $plan['gross_buys_usd'], 0.01);
        $this->assertEqualsWithDelta(0.0, $plan['settled_cash_after_planned'], 0.01);
        $this->assertSame('new_admit', $legs['AAA']['reason']);
    }

    public function test_existing_position_tops_up_underweight_names_by_weight(): void
    {
        // AAA already holds $5 MV; total_equity = 10 settled + 5 = 15.
        $plan = (new BasketRebalancePlanner)->plan($this->weights(), $this->baseParams([
            'settled_cash' => 10.0,
            'positions' => ['AAA' => ['shares' => 1.0, 'market_value' => 5.0]],
        ]));

        $legs = $this->legs($plan);
        $this->assertEqualsWithDelta(7.5, $legs['AAA']['notional_target'], 0.01);
        $this->assertEqualsWithDelta(2.5, $legs['AAA']['drift_usd'], 0.01);  // 7.5 - 5
        $this->assertEqualsWithDelta(4.5, $legs['BBB']['drift_usd'], 0.01);  // 4.5 - 0
        $this->assertEqualsWithDelta(3.0, $legs['CCC']['drift_usd'], 0.01);  // 3.0 - 0

        // Σ drift == settled cash → exactly deploys the deposit, AAA tops up least.
        $this->assertEqualsWithDelta(10.0, $plan['gross_buys_usd'], 0.01);
        $this->assertSame('deposit_fill', $legs['AAA']['reason']);
        $this->assertSame('new_admit', $legs['BBB']['reason']);
    }

    public function test_water_fill_scales_buys_down_to_available_settled_cash(): void
    {
        // per_day cap (6) < settled (10): available = 6, Σdesired = 10 → scale 0.6.
        $plan = (new BasketRebalancePlanner)->plan($this->weights(), $this->baseParams([
            'settled_cash' => 10.0,
            'per_day_max_debit' => 6.0,
        ]));

        $legs = $this->legs($plan);
        $this->assertEqualsWithDelta(3.0, $legs['AAA']['planned_buy_usd'], 0.01); // 5 * 0.6
        $this->assertEqualsWithDelta(1.8, $legs['BBB']['planned_buy_usd'], 0.01); // 3 * 0.6
        $this->assertEqualsWithDelta(1.2, $legs['CCC']['planned_buy_usd'], 0.01); // 2 * 0.6
        $this->assertEqualsWithDelta(6.0, $plan['gross_buys_usd'], 0.01);
        $this->assertLessThanOrEqual(10.0, $plan['gross_buys_usd'], 'never deploys more than settled cash');
    }

    public function test_buy_only_holds_overweight_names_and_flags_drift_trim(): void
    {
        // AAA holds $14 MV; total_equity = 10 + 14 = 24, AAA target 12 → overweight -2.
        $plan = (new BasketRebalancePlanner)->plan($this->weights(), $this->baseParams([
            'settled_cash' => 10.0,
            'positions' => ['AAA' => ['shares' => 2.0, 'market_value' => 14.0]],
        ]));

        $legs = $this->legs($plan);
        $this->assertSame('hold', $legs['AAA']['action'], 'V1 never sells');
        $this->assertSame('drift_trim', $legs['AAA']['reason']);
        $this->assertEqualsWithDelta(0.0, $legs['AAA']['planned_buy_usd'], 0.001);
        $this->assertEqualsWithDelta(0.0, $plan['gross_sells_usd'], 0.001);

        // The settled cash still deploys into the underweight names only.
        $this->assertSame('buy', $legs['BBB']['action']);
        $this->assertSame('buy', $legs['CCC']['action']);
        $this->assertEqualsWithDelta(10.0, $plan['gross_buys_usd'], 0.01);
    }

    public function test_fractionability_gate_drops_untradable_and_never_silently_admits_unverified(): void
    {
        $plan = (new BasketRebalancePlanner)->plan($this->weights(), $this->baseParams([
            'tradability' => ['AAA' => true, 'BBB' => false, 'CCC' => null],
        ]));

        $legs = $this->legs($plan);
        $this->assertSame('buy', $legs['AAA']['action']);
        $this->assertSame('drop', $legs['BBB']['action']);
        $this->assertSame('dropped_untradable', $legs['BBB']['reason']);
        $this->assertFalse($legs['BBB']['fractionable']);

        // Unverified fractionability is held (fail-closed), never bought.
        $this->assertSame('hold', $legs['CCC']['action']);
        $this->assertNull($legs['CCC']['fractionable']);
        $this->assertSame('fractionability_unverified', $legs['CCC']['plan_notes']['blocked'] ?? null);
        $this->assertEqualsWithDelta(0.0, $legs['CCC']['planned_buy_usd'], 0.001);
    }

    public function test_sub_minimum_buys_are_demoted_not_placed_as_dust(): void
    {
        $plan = (new BasketRebalancePlanner)->plan($this->weights(), $this->baseParams([
            'settled_cash' => 10.0,
            'min_leg_notional_usd' => 5.0,
        ]));

        $legs = $this->legs($plan);
        $this->assertSame('buy', $legs['AAA']['action']);        // 5.0 >= 5.0
        $this->assertSame('hold', $legs['BBB']['action']);        // 3.0 < 5.0 → demoted
        $this->assertSame('below_min_notional', $legs['BBB']['plan_notes']['demoted'] ?? null);
        $this->assertSame('hold', $legs['CCC']['action']);        // 2.0 < 5.0 → demoted
        $this->assertEqualsWithDelta(5.0, $plan['gross_buys_usd'], 0.01);
    }

    public function test_deposit_detected_from_prior_pass_settled_cash_after(): void
    {
        $plan = (new BasketRebalancePlanner)->plan($this->weights(), $this->baseParams([
            'settled_cash' => 10.0,
            'prior_settled_cash_after' => 2.0,
        ]));

        $this->assertTrue($plan['deposit_detected']);
        $this->assertEqualsWithDelta(8.0, $plan['deposit_amount'], 0.01);
    }

    public function test_unverified_settled_cash_aborts_the_pass_with_no_buys(): void
    {
        $plan = (new BasketRebalancePlanner)->plan($this->weights(), $this->baseParams([
            'settled_cash' => null,
        ]));

        $this->assertSame('aborted', $plan['status']);
        $this->assertSame('settled_cash_unverified', $plan['abort_reason']);
        $this->assertNull($plan['total_equity']);
        $this->assertEqualsWithDelta(0.0, $plan['gross_buys_usd'], 0.001);
        foreach ($plan['legs'] as $leg) {
            $this->assertSame(0.0, $leg['planned_buy_usd']);
            $this->assertNotSame('buy', $leg['action']);
        }
    }
}
