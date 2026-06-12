<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\AlpacaSmokeTestCommand;
use App\Console\Commands\BrokerReadbackCommand;
use App\Console\Commands\LiveEquityProbeCommand;
use App\Console\Commands\ReconcileCommand;
use App\Robinhood\Rails\OrderIntent;
use App\Robinhood\Rails\RailPipeline;
use Tests\TestCase;

/**
 * The firm's inert-patch guard: a config key that exists and reads true in
 * isolation must reach the service path through the command $cfg assembly.
 * These tests exercise the assembly itself, not a hand-built array.
 */
class ConfigPropagationTest extends TestCase
{
    private const COMMANDS = [
        AlpacaSmokeTestCommand::class,
        BrokerReadbackCommand::class,
        LiveEquityProbeCommand::class,
        ReconcileCommand::class,
    ];

    public function test_every_command_propagates_the_full_harness_block(): void
    {
        config()->set('harness.kill_switch', true);
        config()->set('harness.mode', 'approval');
        config()->set('harness.allocation.per_play_max_debit', 7.5);
        config()->set('harness.allocation.per_day_max_debit', 33.0);
        config()->set('harness.alpaca.feed', 'sip');

        foreach (self::COMMANDS as $class) {
            $cfg = (new $class)->assembleConfig();

            $this->assertTrue($cfg['kill_switch'], "$class dropped kill_switch");
            $this->assertSame('approval', $cfg['mode'], "$class dropped mode");
            $this->assertSame(7.5, $cfg['allocation']['per_play_max_debit'], "$class dropped per-play cap");
            $this->assertSame(33.0, $cfg['allocation']['per_day_max_debit'], "$class dropped per-day cap");

            foreach ([
                'mcp_endpoint',
                'agentic_account',
                'mode',
                'kill_switch',
                'allocation',
                'equity',
                'greeks',
                'alpaca',
                'telemetry',
            ] as $key) {
                $this->assertArrayHasKey($key, $cfg, "$class cfg assembly missing '$key'");
            }

            foreach (['per_day_max_debit', 'per_play_max_debit', 'per_symbol_max_debit', 'max_open_positions'] as $key) {
                $this->assertArrayHasKey($key, $cfg['allocation'], "$class allocation block missing '$key'");
            }

            foreach (['key_id', 'secret_key', 'feed', 'base_url', 'stream_url', 'staleness_seconds'] as $key) {
                $this->assertArrayHasKey($key, $cfg['alpaca'], "$class alpaca block missing '$key'");
            }
        }
    }

    public function test_live_kill_switch_value_reaches_the_rail_pipeline_through_command_cfg(): void
    {
        config()->set('harness.kill_switch', true);

        $cfg = (new LiveEquityProbeCommand)->assembleConfig();

        $verdict = (new RailPipeline)->evaluate(
            new OrderIntent(symbol: 'XYZ', side: 'buy', dollarAmount: 5.0),
            $cfg,
            [
                'session_open' => true,
                'tradability' => 'tradable',
                'settled_cash' => 100.0,
                'spent_today_debit' => 0.0,
                'open_orders' => [],
                'open_positions' => [],
                'review' => ['ok' => true, 'alerts' => []],
            ],
        );

        $this->assertFalse($verdict->allowed(), 'armed kill switch must reach the pipeline through the command cfg assembly');
        $this->assertContains('kill_switch:kill_switch_engaged', $verdict->blockReasons());
    }

    public function test_pinned_account_default_is_the_agentic_cash_account(): void
    {
        $cfg = (new BrokerReadbackCommand)->assembleConfig();

        $this->assertNotSame('', (string) $cfg['agentic_account'], 'agentic account pin must never be empty');
    }
}
