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
    }
}
