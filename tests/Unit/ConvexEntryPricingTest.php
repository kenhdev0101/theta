<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Robinhood\Convex\ConvexEntryPricing;
use PHPUnit\Framework\TestCase;

final class ConvexEntryPricingTest extends TestCase
{
    public function test_wide_live_quote_posts_inside_model_envelope_and_thesis_exit_risk(): void
    {
        $priced = ConvexEntryPricing::quote(0.35, 0.48, 30, 0.5);

        $this->assertSame(0.44, $priced['limit']);
        $this->assertSame(20.45, $priced['immediate_exit_loss_pct']);
        $this->assertTrue(ConvexEntryPricing::limitAllowed(0.35, 0.48, 0.44, 30, 0.5));
        $this->assertFalse(ConvexEntryPricing::limitAllowed(0.35, 0.48, 0.48, 30, 0.5));
    }

    public function test_spread_that_exceeds_thesis_risk_even_at_mid_blocks(): void
    {
        $this->assertSame('convex_exit_spread_consumes_thesis_risk',
            ConvexEntryPricing::quote(0.35, 0.48, 15, 0.5)['blocker']);
        $this->assertFalse(ConvexEntryPricing::limitAllowed(0.35, 0.48, 0.42, 15, 0.5));
        $this->assertSame('convex_exit_spread_consumes_thesis_risk',
            ConvexEntryPricing::quote(0.35, 0.65, 30, 0.5)['blocker']);
        $this->assertFalse(ConvexEntryPricing::limitAllowed(0.35, 0.65, 0.50, 30, 0.5));
    }

    public function test_round_trip_cost_is_bounded_by_profit_and_stop_budget(): void
    {
        $wide = ConvexEntryPricing::quote(0.35, 0.48, 30, 0.5, 1.30, 0.5);
        $this->assertSame('convex_round_trip_cost_exceeds_budget', $wide['blocker']);
        $this->assertEqualsWithDelta(1500, $wide['round_trip_budget_bps'], 0.01);
        $this->assertFalse(ConvexEntryPricing::limitAllowed(0.35, 0.48, 0.44, 30, 0.5, 1.30, 0.5));

        $narrow = ConvexEntryPricing::quote(0.43, 0.48, 30, 0.5, 1.30, 0.5);
        $this->assertSame(0.46, $narrow['limit']);
        $this->assertEqualsWithDelta(652.17, $narrow['round_trip_cost_bps'], 0.01);
        $this->assertEqualsWithDelta(1500, $narrow['round_trip_budget_bps'], 0.01);
        $this->assertTrue(ConvexEntryPricing::limitAllowed(0.43, 0.48, 0.46, 30, 0.5, 1.30, 0.5));
    }
}
