<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\ThesisIntent;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ThesisIntentTest extends TestCase
{
    private function coherentIntent(array $overrides = []): ThesisIntent
    {
        return new ThesisIntent(array_merge([
            'underlying_symbol' => 'C',
            'direction' => ThesisIntent::DIRECTION_BULLISH,
            'structure' => ThesisIntent::STRUCTURE_LONG_CALL,
            'dte_min' => 30,
            'dte_max' => 70,
            'max_debit' => 350.00,
            'thesis' => 'NIM expansion under a steepening curve.',
            'price_stop' => 125.0,
            'time_stop_date' => Carbon::today()->addDays(45),
            'profit_target_bps' => 6000.0,
        ], $overrides));
    }

    public function test_coherent_intent_has_no_activation_blockers(): void
    {
        $this->assertSame([], $this->coherentIntent()->activationBlockers());
    }

    public function test_every_kill_trigger_is_required(): void
    {
        $this->assertContains('price_stop_missing', $this->coherentIntent(['price_stop' => null])->activationBlockers());
        $this->assertContains('time_stop_missing', $this->coherentIntent(['time_stop_date' => null])->activationBlockers());
        $this->assertContains('profit_target_missing', $this->coherentIntent(['profit_target_bps' => null])->activationBlockers());
        $this->assertContains('thesis_text_missing', $this->coherentIntent(['thesis' => '  '])->activationBlockers());
    }

    public function test_past_time_stop_blocks_activation(): void
    {
        $blockers = $this->coherentIntent(['time_stop_date' => Carbon::today()->subDay()])->activationBlockers();

        $this->assertContains('time_stop_in_past', $blockers);
    }

    public function test_dte_band_must_be_coherent_and_inside_lane_rails(): void
    {
        $this->assertContains('dte_band_invalid', $this->coherentIntent(['dte_min' => 40, 'dte_max' => 20])->activationBlockers());
        $this->assertContains('dte_band_invalid', $this->coherentIntent(['dte_min' => 0])->activationBlockers());

        $laneCfg = ['dte_floor' => 14, 'dte_ceiling' => 120];
        $this->assertContains('dte_below_lane_floor', $this->coherentIntent(['dte_min' => 7])->activationBlockers($laneCfg));
        $this->assertContains('dte_above_lane_ceiling', $this->coherentIntent(['dte_max' => 200])->activationBlockers($laneCfg));
        $this->assertSame([], $this->coherentIntent()->activationBlockers($laneCfg));
    }

    public function test_structure_must_match_direction(): void
    {
        $mismatched = $this->coherentIntent([
            'direction' => ThesisIntent::DIRECTION_BEARISH,
            'structure' => ThesisIntent::STRUCTURE_LONG_CALL,
        ]);

        $this->assertContains('structure_direction_mismatch', $mismatched->activationBlockers());
        $this->assertSame(ThesisIntent::STRUCTURE_LONG_PUT, ThesisIntent::structureForDirection(ThesisIntent::DIRECTION_BEARISH));
        $this->assertSame(ThesisIntent::STRUCTURE_LONG_CALL, ThesisIntent::structureForDirection(ThesisIntent::DIRECTION_BULLISH));
    }

    public function test_enterable_requires_active_status_inside_time_stop(): void
    {
        $intent = $this->coherentIntent();
        $intent->status = ThesisIntent::STATUS_ACTIVE;
        $this->assertTrue($intent->isEnterable());

        $intent->status = ThesisIntent::STATUS_DRAFT;
        $this->assertFalse($intent->isEnterable());

        $expired = $this->coherentIntent(['time_stop_date' => Carbon::today()->subDay()]);
        $expired->status = ThesisIntent::STATUS_ACTIVE;
        $this->assertFalse($expired->isEnterable());

        $lastDay = $this->coherentIntent(['time_stop_date' => Carbon::today()]);
        $lastDay->status = ThesisIntent::STATUS_ACTIVE;
        $this->assertTrue($lastDay->isEnterable());
    }
}
