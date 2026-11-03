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

    public function test_entry_window_is_eastern_not_local(): void
    {
        $cfg = ['thesis' => ['entry' => ['window_close_et' => '10:15']]];

        $this->assertTrue(ThesisLaneConfig::withinEntryWindow($cfg, Carbon::parse('2026-08-17 09:31:00', 'America/New_York')));
        $this->assertTrue(ThesisLaneConfig::withinEntryWindow($cfg, Carbon::parse('2026-08-17 10:14:00', 'America/New_York')));
        $this->assertFalse(ThesisLaneConfig::withinEntryWindow($cfg, Carbon::parse('2026-08-17 10:15:00', 'America/New_York')));
        // Eastern, not local: 07:00 Pacific is 10:00 Eastern.
        $this->assertTrue(ThesisLaneConfig::withinEntryWindow($cfg, Carbon::parse('2026-08-17 07:00:00', 'America/Los_Angeles')));
    }

    public function test_window_close_is_clamped_to_the_scheduled_sweep_bound(): void
    {
        // The cron bound in routes/console.php is NOT env-configurable, so a
        // close at or past it would leave a resting gfd order with zero cancel
        // ticks after it — resting to 16:00 on a play the no-chase rule
        // expired. Out-of-range and unparseable values clamp to the default
        // and name why, rather than either running long or silently disabling
        // the lane while the scheduler keeps ticking.
        $cases = [
            '10:30' => 'window_close_past_scheduled_sweep_bound',
            '10:20' => 'window_close_past_scheduled_sweep_bound',
            '09:15' => 'window_close_at_or_before_session_open',
            'ten-fifteen' => 'window_close_unparseable',
            '99:99' => 'window_close_unparseable',
            '' => 'window_close_unconfigured',
        ];

        foreach ($cases as $configured => $reason) {
            $resolved = ThesisLaneConfig::resolveWindowClose(['thesis' => ['entry' => ['window_close_et' => (string) $configured]]]);

            $this->assertTrue($resolved['clamped'], "'{$configured}' should clamp");
            $this->assertSame(ThesisLaneConfig::DEFAULT_WINDOW_CLOSE_ET, $resolved['value']);
            $this->assertSame($reason, $resolved['reason']);
        }

        $inRange = ThesisLaneConfig::resolveWindowClose(['thesis' => ['entry' => ['window_close_et' => '10:00']]]);
        $this->assertFalse($inRange['clamped']);
        $this->assertSame('10:00', $inRange['value']);

        // The invariant the clamp exists to hold, asserted directly.
        $sweepBound = (int) ThesisLaneConfig::minutesFromEtTime(ThesisLaneConfig::SCHEDULE_SWEEP_CLOSE_ET);
        foreach (array_merge(array_keys($cases), ['10:00', '09:45']) as $configured) {
            $resolved = ThesisLaneConfig::resolveWindowClose(['thesis' => ['entry' => ['window_close_et' => (string) $configured]]]);
            $this->assertLessThanOrEqual(
                $sweepBound - ThesisLaneConfig::SWEEP_MARGIN_MINUTES,
                $resolved['minutes'],
                "'{$configured}' resolved past the scheduled sweep bound",
            );
        }
    }

    public function test_zero_max_open_positions_is_a_hard_stop(): void
    {
        $this->assertSame(0, ThesisLaneConfig::maxOpenPositions(['thesis' => ['max_open_positions' => 0]]));
        $this->assertSame(0, ThesisLaneConfig::maxOpenPositions(['thesis' => []]));
        $this->assertSame(0, ThesisLaneConfig::maxOpenPositions(['thesis' => ['max_open_positions' => -4]]));
        $this->assertSame(3, ThesisLaneConfig::maxOpenPositions(['thesis' => ['max_open_positions' => 3]]));
    }

    public function test_dte_band_is_clamped_to_the_lane_rails(): void
    {
        $cfg = ['thesis' => ['dte_floor' => 14, 'dte_ceiling' => 40]];

        $clamped = ThesisLaneConfig::clampDteBand($cfg, 30, 70);
        $this->assertSame(30, $clamped['dte_min']);
        $this->assertSame(40, $clamped['dte_max']);
        $this->assertTrue($clamped['clamped']);
        $this->assertFalse($clamped['empty']);

        $raised = ThesisLaneConfig::clampDteBand($cfg, 3, 20);
        $this->assertSame(14, $raised['dte_min']);
        $this->assertSame(20, $raised['dte_max']);

        $untouched = ThesisLaneConfig::clampDteBand($cfg, 20, 35);
        $this->assertFalse($untouched['clamped']);

        $this->assertTrue(ThesisLaneConfig::clampDteBand(['thesis' => ['dte_ceiling' => 10]], 30, 70)['empty']);
    }

    public function test_supervision_is_strictly_wider_than_the_repeg_claim_set(): void
    {
        // Everything a re-peg may claim must also be supervised, and blocked
        // plays must be supervised without being claimable.
        foreach (ThesisLaneConfig::WORKING_STATUSES as $status) {
            $this->assertContains($status, ThesisLaneConfig::SUPERVISED_STATUSES);
        }

        $this->assertContains(ThesisLaneConfig::STATUS_ENTRY_BLOCKED, ThesisLaneConfig::SUPERVISED_STATUSES);
        $this->assertContains(ThesisLaneConfig::STATUS_REPEGGING, ThesisLaneConfig::SUPERVISED_STATUSES);
        $this->assertNotContains(ThesisLaneConfig::STATUS_ENTRY_BLOCKED, ThesisLaneConfig::WORKING_STATUSES);
        $this->assertNotContains(ThesisLaneConfig::STATUS_REPEGGING, ThesisLaneConfig::WORKING_STATUSES);
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
