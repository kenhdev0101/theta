<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Robinhood\Thesis\ThesisEntryPricer;
use App\Robinhood\Thesis\ThesisLaneConfig;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * The thesis lane's deterministic price and cap primitives. Nothing here
 * touches the broker; these are the numbers the executor is not allowed to
 * get wrong.
 */
class ThesisEntryPricingTest extends TestCase
{
    private function quote(float $bid, float $ask): array
    {
        return ['bid' => $bid, 'ask' => $ask, 'mid' => round(($bid + $ask) / 2, 4)];
    }

    public function test_zero_offset_prices_the_mid(): void
    {
        $priced = ThesisEntryPricer::derive($this->quote(2.00, 2.20), 0.0);

        $this->assertSame(2.10, $priced['limit']);
        $this->assertSame(2.10, $priced['mid']);
        $this->assertNull($priced['reason']);
    }

    public function test_positive_offset_walks_toward_the_ask_but_never_past_it(): void
    {
        // +200 bps on a 2.10 mid = 2.142 -> 2.14, still inside the book.
        $this->assertSame(2.14, ThesisEntryPricer::derive($this->quote(2.00, 2.20), 200.0)['limit']);

        // +5000 bps would be 3.15; the ask is the hard ceiling.
        $this->assertSame(2.20, ThesisEntryPricer::derive($this->quote(2.00, 2.20), 5000.0)['limit']);
    }

    public function test_negative_offset_never_prices_below_the_live_bid(): void
    {
        $this->assertSame(2.00, ThesisEntryPricer::derive($this->quote(2.00, 2.20), -5000.0)['limit']);
    }

    public function test_chase_ceiling_caps_the_repeg_ladder(): void
    {
        // Anchor 2.10 + 150 bps = 2.1315 -> the ladder tops out at 2.13 even
        // though the live mid has walked up to 2.15.
        $ceiling = ThesisEntryPricer::chaseCeiling(2.10, 150.0);
        $priced = ThesisEntryPricer::derive($this->quote(2.05, 2.25), 0.0, $ceiling);

        $this->assertSame(2.13, $priced['limit']);
        $this->assertSame($ceiling, $priced['chase_ceiling']);
    }

    public function test_a_market_that_gapped_past_the_ceiling_refuses_to_price(): void
    {
        // The whole book is above the ceiling: no chase, named reason.
        $priced = ThesisEntryPricer::derive($this->quote(3.00, 3.20), 0.0, ThesisEntryPricer::chaseCeiling(2.10, 150.0));

        $this->assertNull($priced['limit']);
        $this->assertSame('entry_chase_cap_exceeded', $priced['reason']);
    }

    public function test_missing_or_crossed_quotes_refuse_to_price(): void
    {
        $this->assertSame('bid_or_ask_missing', ThesisEntryPricer::derive(['bid' => null, 'ask' => 2.20], 0.0)['reason']);
        $this->assertSame('bid_or_ask_missing', ThesisEntryPricer::derive(['bid' => 2.00, 'ask' => 0.0], 0.0)['reason']);
        $this->assertSame('quote_crossed', ThesisEntryPricer::derive($this->quote(2.30, 2.20), 0.0)['reason']);
    }

    public function test_entry_window_is_eastern_and_fails_closed_on_a_malformed_time(): void
    {
        $cfg = ['thesis' => ['entry' => ['window_close_et' => '10:15']]];

        $this->assertTrue(ThesisLaneConfig::withinEntryWindow($cfg, Carbon::parse('2026-08-17 09:31:00', 'America/New_York')));
        $this->assertTrue(ThesisLaneConfig::withinEntryWindow($cfg, Carbon::parse('2026-08-17 10:14:00', 'America/New_York')));
        $this->assertFalse(ThesisLaneConfig::withinEntryWindow($cfg, Carbon::parse('2026-08-17 10:15:00', 'America/New_York')));
        // Eastern, not local: 07:00 Pacific is 10:00 Eastern.
        $this->assertTrue(ThesisLaneConfig::withinEntryWindow($cfg, Carbon::parse('2026-08-17 07:00:00', 'America/Los_Angeles')));

        $malformed = ['thesis' => ['entry' => ['window_close_et' => 'ten-fifteen']]];
        $this->assertFalse(
            ThesisLaneConfig::withinEntryWindow($malformed, Carbon::parse('2026-08-17 09:31:00', 'America/New_York')),
            'a malformed window must fail closed, never work an entry all session',
        );
    }

    public function test_per_play_cap_is_the_tighter_of_thesis_and_lane(): void
    {
        $cfg = ['thesis' => ['per_play_max_debit' => 500.0]];

        $this->assertSame(150.0, ThesisLaneConfig::perPlayMaxDebit($cfg, 150.0));
        $this->assertSame(500.0, ThesisLaneConfig::perPlayMaxDebit($cfg, 900.0));
        $this->assertSame(500.0, ThesisLaneConfig::perPlayMaxDebit($cfg, 0.0));
    }

    public function test_rails_config_swaps_scalp_caps_for_the_lane_precommitments(): void
    {
        $cfg = [
            'agentic_account' => '872449178',
            'thesis' => ['max_open_positions' => 3, 'per_day_max_debit' => 1000.0, 'per_play_max_debit' => 500.0],
            'options' => [
                'enabled' => true,
                'contract_multiplier' => 100.0,
                'max_spread_bps' => 1500.0,
                'max_contract_ask' => 5.00,
                'min_quote_fresh_seconds' => 10,
                'per_play_max_debit' => 75.0,
                'per_day_max_debit' => 250.0,
                'daily_gross_entry_debit' => 500.0,
                'per_underlying_max_debit' => 50.0,
                'max_open_positions' => 4,
            ],
        ];

        $lane = ThesisLaneConfig::railsConfig($cfg, 300.0);

        $this->assertSame(300.0, $lane['options']['per_play_max_debit']);
        $this->assertSame(300.0, $lane['options']['per_underlying_max_debit']);
        $this->assertSame(1000.0, $lane['options']['per_day_max_debit']);
        $this->assertSame(1000.0, $lane['options']['daily_gross_entry_debit']);
        $this->assertSame(3, $lane['options']['max_open_positions']);
        // The contract-ask gate and the debit cap must describe one number.
        $this->assertSame(3.0, $lane['options']['max_contract_ask']);

        // Everything the overlay does not name is left exactly as configured.
        $this->assertSame(1500.0, $lane['options']['max_spread_bps']);
        $this->assertSame(10, $lane['options']['min_quote_fresh_seconds']);
        $this->assertSame('872449178', $lane['agentic_account']);
    }

    public function test_thesis_statuses_are_disjoint_from_the_scalp_autopilot_claim_set(): void
    {
        // The scalp autopilot claims `reviewed` rows every minute all session.
        // If the thesis lane ever writes one, the wrong lane places the play.
        $this->assertNotContains('reviewed', ThesisLaneConfig::PLACEABLE_STATUSES);
        $this->assertNotContains('reviewed', ThesisLaneConfig::NON_TERMINAL_PLAY_STATUSES);
        $this->assertNotContains('autopilot_claimed', ThesisLaneConfig::NON_TERMINAL_PLAY_STATUSES);
    }
}
