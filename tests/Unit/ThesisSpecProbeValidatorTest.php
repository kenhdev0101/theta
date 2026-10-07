<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Robinhood\Thesis\ThesisSpec;
use App\Robinhood\Thesis\ThesisSpecValidator;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class ThesisSpecProbeValidatorTest extends TestCase
{
    public function test_probe_scouts_a_named_core_sibling_and_requires_fast_exit(): void
    {
        $probe = [
            'leg_id' => 'probe', 'role' => 'probe', 'scouts' => 'core',
            'asset_class' => 'option', 'symbol' => 'XLF', 'direction' => 'long',
            'structure' => 'single_leg_long_call', 'dte' => ['min' => 7, 'max' => 21],
            'moneyness' => ['mode' => 'delta', 'target' => 0.20],
            'sizing' => ['mode' => 'notional', 'target_usd' => 25],
            'invalidation' => ['time_stop' => Carbon::today()->addDays(3)->toDateString()],
            'fast_exit' => ['max_hold_minutes' => 10],
        ];
        $core = [
            'leg_id' => 'core', 'role' => 'core', 'asset_class' => 'option',
            'symbol' => 'XLF', 'direction' => 'long', 'structure' => 'single_leg_long_call',
            'dte' => ['min' => 30, 'max' => 70], 'sizing' => ['mode' => 'notional', 'target_usd' => 100],
            'invalidation' => ['time_stop' => Carbon::today()->addDays(15)->toDateString()],
        ];
        $raw = ['spec_version' => '1', 'thesis_id' => 'scout-test', 'thesis' => 'test', 'legs' => [$probe, $core]];
        $validator = new ThesisSpecValidator;

        $this->assertSame([], $validator->structuralBlockers(ThesisSpec::fromArray($raw)));

        unset($raw['legs'][0]['fast_exit']);
        $this->assertContains('leg[probe]:fringe_requires_fast_exit_max_hold', $validator->structuralBlockers(ThesisSpec::fromArray($raw)));

        $raw['legs'][0]['fast_exit'] = ['max_hold_minutes' => 10];
        $raw['legs'][0]['scouts'] = 'missing';
        $this->assertContains('leg[probe]:scouts_requires_core_sibling', $validator->structuralBlockers(ThesisSpec::fromArray($raw)));
    }
}
