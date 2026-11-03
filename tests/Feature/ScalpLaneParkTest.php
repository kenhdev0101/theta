<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The scalp-lane park: intraday option scalping is retired as a strategy
 * (June live run bled −35% in four sessions through spread-crossing churn and
 * T+1 settlement lock). The safe state must be the DEFAULT — arming the old
 * producer requires an explicit env decision, and the thesis lane ships
 * disabled until its own arm checklist clears.
 */
class ScalpLaneParkTest extends TestCase
{
    public function test_scalp_producer_defaults_off(): void
    {
        $this->assertFalse(
            (bool) config('harness.options.producer.enabled'),
            'The scalp producer must be parked by default; arming requires HARNESS_OPTIONS_PRODUCER_ENABLED=true explicitly.'
        );
    }

    public function test_producer_command_disarms_under_default_config_even_in_auto_mode(): void
    {
        config()->set('harness.mode', 'auto');
        config()->set('harness.kill_switch', false);
        config()->set('harness.options.enabled', true);
        config()->set('harness.options.producer.enabled', false);

        $this->artisan('theta:option-intent-producer')
            ->expectsOutputToContain('producer disarmed: options producer disabled')
            ->assertSuccessful();
    }

    public function test_thesis_lane_ships_disabled_with_approval_first_config(): void
    {
        $this->assertFalse((bool) config('harness.thesis.enabled'));
        $this->assertSame(3, (int) config('harness.thesis.max_open_positions'));
        $this->assertNotEmpty(config('harness.thesis.approval.approval_expires_et'));
    }
}
