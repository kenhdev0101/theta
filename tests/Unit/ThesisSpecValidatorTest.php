<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Robinhood\Thesis\ThesisSpec;
use App\Robinhood\Thesis\ThesisSpecValidator;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ThesisSpecValidatorTest extends TestCase
{
    private function futureDate(int $days = 30): string
    {
        return Carbon::today()->addDays($days)->format('Y-m-d');
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @param  array<string,mixed>  $legOverrides
     */
    private function coherentSpec(array $overrides = [], array $legOverrides = []): ThesisSpec
    {
        $leg = array_merge([
            'leg_id' => 'a',
            'asset_class' => 'equity',
            'symbol' => 'QQQ',
            'direction' => 'long',
            'sizing' => ['mode' => 'notional', 'target_usd' => 5.0, 'max_usd' => 6.0],
            'entry' => ['type' => 'marketable_limit', 'offset_bps' => 10, 'max_chase_bps' => 50],
            'invalidation' => ['price_stop' => 690.0, 'time_stop' => $this->futureDate(), 'target_pct' => 15.0],
        ], $legOverrides);

        return ThesisSpec::fromArray(array_merge([
            'spec_version' => '1',
            'thesis_id' => 'test-thesis',
            'thesis' => 'a coherent reason to hold',
            'legs' => [$leg],
        ], $overrides));
    }

    private function blockers(ThesisSpec $spec): array
    {
        return (new ThesisSpecValidator)->structuralBlockers($spec);
    }

    public function test_coherent_spec_has_no_structural_blockers(): void
    {
        $this->assertSame([], $this->blockers($this->coherentSpec()));
    }

    public function test_spec_version_must_be_supported(): void
    {
        $this->assertContains('spec_version_unsupported', $this->blockers($this->coherentSpec(['spec_version' => '2'])));
        $this->assertContains('spec_version_unsupported', $this->blockers($this->coherentSpec(['spec_version' => ''])));
    }

    public function test_thesis_id_required_and_slug_safe(): void
    {
        $this->assertContains('thesis_id_missing', $this->blockers($this->coherentSpec(['thesis_id' => ''])));
        $this->assertContains('thesis_id_malformed', $this->blockers($this->coherentSpec(['thesis_id' => 'Bad ID!'])));
        $this->assertContains('thesis_id_malformed', $this->blockers($this->coherentSpec(['thesis_id' => '-leading'])));
    }

    public function test_thesis_text_required(): void
    {
        $this->assertContains('thesis_text_missing', $this->blockers($this->coherentSpec(['thesis' => '   '])));
    }

    public function test_at_least_one_leg_required(): void
    {
        $this->assertContains('no_legs', $this->blockers($this->coherentSpec(['legs' => []])));
    }

    public function test_short_equity_is_blocked_on_a_cash_account(): void
    {
        $blockers = $this->blockers($this->coherentSpec(legOverrides: ['direction' => 'short']));
        $this->assertContains('leg[a]:short_equity_not_permitted_cash_account', $blockers);
    }

    public function test_asset_class_and_direction_must_be_supported(): void
    {
        $this->assertContains('leg[a]:asset_class_unsupported', $this->blockers($this->coherentSpec(legOverrides: ['asset_class' => 'crypto'])));
        $this->assertContains('leg[a]:direction_unsupported', $this->blockers($this->coherentSpec(legOverrides: ['direction' => 'sideways'])));
    }

    public function test_sizing_mode_and_amounts(): void
    {
        $this->assertContains('leg[a]:sizing_mode_unsupported', $this->blockers($this->coherentSpec(legOverrides: ['sizing' => ['mode' => 'martingale']])));
        $this->assertContains('leg[a]:sizing_target_usd_nonpositive', $this->blockers($this->coherentSpec(legOverrides: ['sizing' => ['mode' => 'notional', 'target_usd' => 0]])));
        $this->assertContains('leg[a]:sizing_max_usd_below_target', $this->blockers($this->coherentSpec(legOverrides: ['sizing' => ['mode' => 'notional', 'target_usd' => 5.0, 'max_usd' => 3.0]])));
        $this->assertContains('leg[a]:sizing_shares_nonpositive', $this->blockers($this->coherentSpec(legOverrides: ['sizing' => ['mode' => 'shares', 'shares' => 0]])));
    }

    public function test_balance_fraction_must_be_in_range(): void
    {
        $this->assertContains('leg[a]:sizing_balance_fraction_out_of_range', $this->blockers($this->coherentSpec(legOverrides: ['sizing' => ['mode' => 'balance_fraction', 'fraction' => 1.5, 'max_usd' => 6.0]])));
        $this->assertSame([], $this->blockers($this->coherentSpec(legOverrides: ['sizing' => ['mode' => 'balance_fraction', 'fraction' => 0.3, 'max_usd' => 6.0]])));
    }

    public function test_at_least_one_invalidation_trigger_is_required(): void
    {
        $blockers = $this->blockers($this->coherentSpec(legOverrides: ['invalidation' => []]));
        $this->assertContains('leg[a]:no_invalidation_trigger', $blockers);
    }

    public function test_time_stop_must_be_a_valid_future_date(): void
    {
        $this->assertContains('leg[a]:time_stop_malformed', $this->blockers($this->coherentSpec(legOverrides: ['invalidation' => ['time_stop' => '2026-13-40']])));
        $this->assertContains('leg[a]:time_stop_in_past', $this->blockers($this->coherentSpec(legOverrides: ['invalidation' => ['time_stop' => Carbon::today()->subDay()->format('Y-m-d')]])));
    }

    public function test_option_leg_requires_structure_and_dte_band(): void
    {
        $spec = $this->coherentSpec(legOverrides: [
            'asset_class' => 'option',
            'structure' => '',
            'dte' => ['min' => 0, 'max' => 0],
            'invalidation' => ['target_pct' => 50.0],
        ]);
        $blockers = $this->blockers($spec);
        $this->assertContains('leg[a]:option_structure_unsupported', $blockers);
        $this->assertContains('leg[a]:option_dte_band_invalid', $blockers);

        $good = $this->coherentSpec(legOverrides: [
            'asset_class' => 'option',
            'structure' => 'single_leg_long_put',
            'dte' => ['min' => 30, 'max' => 70],
            'invalidation' => ['target_pct' => 50.0, 'time_stop' => $this->futureDate()],
        ]);
        $this->assertSame([], $this->blockers($good));
    }

    public function test_duplicate_leg_ids_are_blocked(): void
    {
        $leg = [
            'leg_id' => 'dup', 'asset_class' => 'equity', 'symbol' => 'QQQ', 'direction' => 'long',
            'sizing' => ['mode' => 'notional', 'target_usd' => 5.0],
            'invalidation' => ['price_stop' => 690.0],
        ];
        $spec = ThesisSpec::fromArray([
            'spec_version' => '1', 'thesis_id' => 'dup-test', 'thesis' => 'x',
            'legs' => [$leg, $leg],
        ]);
        $this->assertContains('leg[dup]:duplicate_leg_id', $this->blockers($spec));
    }

    public function test_cap_blockers_flag_oversize_notional(): void
    {
        $validator = new ThesisSpecValidator;
        $spec = $this->coherentSpec(legOverrides: ['sizing' => ['mode' => 'notional', 'target_usd' => 5.0, 'max_usd' => 6.0]]);

        $capBlockers = $validator->capBlockers($spec, ['per_play_max_usd' => 3.0, 'per_day_max_usd' => 3.0]);
        $this->assertContains('leg[a]:sizing_exceeds_per_play_cap', $capBlockers);
        $this->assertContains('notional_total_exceeds_per_day_cap', $capBlockers);
    }

    public function test_cap_blockers_flag_too_many_legs(): void
    {
        $legA = [
            'leg_id' => 'a', 'asset_class' => 'equity', 'symbol' => 'QQQ', 'direction' => 'long',
            'sizing' => ['mode' => 'notional', 'target_usd' => 1.0], 'invalidation' => ['price_stop' => 690.0],
        ];
        $legB = array_merge($legA, ['leg_id' => 'b', 'symbol' => 'SMH']);
        $spec = ThesisSpec::fromArray([
            'spec_version' => '1', 'thesis_id' => 'two-legs', 'thesis' => 'x', 'legs' => [$legA, $legB],
        ]);

        $this->assertContains('legs_exceed_max_open', (new ThesisSpecValidator)->capBlockers($spec, ['max_legs' => 1]));
        $this->assertNotContains('legs_exceed_max_open', (new ThesisSpecValidator)->capBlockers($spec, ['max_legs' => 2]));
    }

    public function test_warnings_flag_uncapped_balance_fraction_and_discretionary_only_exit(): void
    {
        $validator = new ThesisSpecValidator;

        $uncapped = $this->coherentSpec(legOverrides: ['sizing' => ['mode' => 'balance_fraction', 'fraction' => 0.3]]);
        $this->assertContains('leg[a]:balance_fraction_without_max_usd_ceiling', $validator->warnings($uncapped));

        $discretionary = $this->coherentSpec(legOverrides: ['invalidation' => ['thesis_stop' => 'a gut feeling']]);
        $this->assertContains('leg[a]:only_discretionary_thesis_stop', $validator->warnings($discretionary));
    }
}
