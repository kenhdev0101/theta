<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\AlpacaSmokeTestCommand;
use App\Console\Commands\BrokerReadbackCommand;
use App\Console\Commands\ConvexAutoCommand;
use App\Console\Commands\ConvexIngestCommand;
use App\Console\Commands\ConvexProbeCommand;
use App\Console\Commands\LiveEquityProbeCommand;
use App\Console\Commands\OptionAutopilotCommand;
use App\Console\Commands\OptionIntentProducerCommand;
use App\Console\Commands\OptionLivePlaceCommand;
use App\Console\Commands\OptionReconcileCommand;
use App\Console\Commands\OptionReviewProbeCommand;
use App\Console\Commands\OptionsCapabilityReadbackCommand;
use App\Console\Commands\ReconcileCommand;
use App\Console\Commands\ThesisAddCommand;
use App\Console\Commands\ThesisAssembleCommand;
use App\Console\Commands\ThesisExecuteCommand;
use App\Console\Commands\ThesisListCommand;
use App\Console\Commands\ThesisSetCommand;
use App\Console\Commands\ThesisValidateCommand;
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
        ConvexAutoCommand::class,
        ConvexIngestCommand::class,
        ConvexProbeCommand::class,
        AlpacaSmokeTestCommand::class,
        BrokerReadbackCommand::class,
        LiveEquityProbeCommand::class,
        ReconcileCommand::class,
        OptionsCapabilityReadbackCommand::class,
        OptionReviewProbeCommand::class,
        OptionReconcileCommand::class,
        OptionIntentProducerCommand::class,
        OptionLivePlaceCommand::class,
        OptionAutopilotCommand::class,
        ThesisAddCommand::class,
        ThesisListCommand::class,
        ThesisSetCommand::class,
        ThesisAssembleCommand::class,
        ThesisExecuteCommand::class,
        ThesisValidateCommand::class,
    ];

    public function test_every_command_propagates_the_full_harness_block(): void
    {
        config()->set('harness.kill_switch', true);
        config()->set('harness.mode', 'approval');
        config()->set('harness.allocation.per_play_max_debit', 7.5);
        config()->set('harness.allocation.per_day_max_debit', 33.0);
        config()->set('harness.alpaca.feed', 'sip');
        config()->set('harness.convex.auto_entry_enabled', false);
        config()->set('harness.convex.min_quote_fresh_seconds', 123);

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
                'broker_credits',
                'allocation',
                'equity',
                'greeks',
                'alpaca',
                'telemetry',
                'thesis',
                'convex',
            ] as $key) {
                $this->assertArrayHasKey($key, $cfg, "$class cfg assembly missing '$key'");
            }

            $this->assertSame('convex-wrapper-napkin-v1', $cfg['convex']['policy_version']);
            $this->assertContains('SPY', $cfg['convex']['tickers']);
            $this->assertFalse($cfg['convex']['auto_entry_enabled']);
            $this->assertSame(123, $cfg['convex']['min_quote_fresh_seconds']);

            foreach ([
                'enabled', 'max_open_positions', 'per_play_max_debit', 'per_day_max_debit',
                'dte_floor', 'dte_ceiling', 'approval', 'entry',
            ] as $key) {
                $this->assertArrayHasKey($key, $cfg['thesis'] ?? [], "$class thesis block missing '$key'");
            }

            foreach (['window_close_et', 'reprice_seconds', 'max_chase_bps'] as $key) {
                $this->assertArrayHasKey($key, $cfg['thesis']['entry'] ?? [], "$class thesis.entry block missing '$key'");
            }

            foreach (['per_day_max_debit', 'per_play_max_debit', 'per_symbol_max_debit', 'max_open_positions'] as $key) {
                $this->assertArrayHasKey($key, $cfg['allocation'], "$class allocation block missing '$key'");
            }

            foreach (['enabled', 'max_per_minute', 'mode', 'max_wait_seconds', 'cache_key'] as $key) {
                $this->assertArrayHasKey($key, $cfg['broker_credits'], "$class broker_credits block missing '$key'");
            }

            foreach (['key_id', 'secret_key', 'feed', 'base_url', 'stream_url', 'staleness_seconds'] as $key) {
                $this->assertArrayHasKey($key, $cfg['alpaca'], "$class alpaca block missing '$key'");
            }

            foreach ([
                'enabled', 'max_spread_bps', 'max_contract_ask', 'per_play_max_debit',
                'per_day_max_debit', 'daily_gross_entry_debit', 'per_underlying_max_debit', 'max_open_positions',
                'min_quote_fresh_seconds', 'metadata_cache_seconds', 'required_tools',
                'producer',
            ] as $key) {
                $this->assertArrayHasKey($key, $cfg['options'] ?? [], "$class options block missing '$key'");
            }

            foreach ([
                'enabled', 'universe_source', 'symbols', 'dynamic_max_symbols',
                'option_type', 'expiration_min_days', 'max_chain_pages',
                'quote_chunk_limit', 'block_cooldown_minutes', 'momentum_timeframe', 'momentum_lookback_bars',
                'momentum_bar_fresh_seconds', 'momentum_min_return_bps', 'momentum_min_volume_expansion',
                'underlying_max_spread_bps', 'exit_enabled', 'exit_profit_target_bps',
                'exit_stop_loss_bps', 'exit_max_hold_minutes',
            ] as $key) {
                $this->assertArrayHasKey($key, $cfg['options']['producer'] ?? [], "$class options.producer block missing '$key'");
            }
        }
    }

    public function test_live_options_cap_values_reach_the_option_pipeline_through_command_cfg(): void
    {
        config()->set('harness.options.per_play_max_debit', 10.0);

        $cfg = (new OptionLivePlaceCommand)->assembleConfig();

        $this->assertSame(10.0, $cfg['options']['per_play_max_debit'], 'live options cap must reach the command cfg assembly');
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

    public function test_thesis_entry_window_values_reach_both_thesis_lane_commands(): void
    {
        config()->set('harness.thesis.entry.window_close_et', '10:05');
        config()->set('harness.thesis.entry.reprice_seconds', 30);
        config()->set('harness.thesis.entry.max_chase_bps', 90.0);

        foreach ([ThesisAssembleCommand::class, ThesisExecuteCommand::class] as $class) {
            $cfg = (new $class)->assembleConfig();

            $this->assertSame('10:05', $cfg['thesis']['entry']['window_close_et'], "$class dropped window_close_et");
            $this->assertSame(30, $cfg['thesis']['entry']['reprice_seconds'], "$class dropped reprice_seconds");
            $this->assertSame(90.0, $cfg['thesis']['entry']['max_chase_bps'], "$class dropped max_chase_bps");
        }
    }

    public function test_thesis_entry_defaults_are_the_no_chase_window(): void
    {
        $cfg = (new ThesisExecuteCommand)->assembleConfig();

        $this->assertSame('10:15', $cfg['thesis']['entry']['window_close_et']);
        $this->assertSame(45, $cfg['thesis']['entry']['reprice_seconds']);
        $this->assertSame(150.0, $cfg['thesis']['entry']['max_chase_bps']);
    }

    public function test_options_producer_default_universe_is_dynamic_and_broad(): void
    {
        $cfg = (new OptionIntentProducerCommand)->assembleConfig();

        $this->assertSame('dynamic', $cfg['options']['producer']['universe_source']);
        $this->assertSame(6, $cfg['options']['producer']['dynamic_max_symbols']);
        $this->assertContains('SPY', $cfg['options']['producer']['symbols']);
        $this->assertContains('QQQ', $cfg['options']['producer']['symbols']);
        $this->assertContains('NVDA', $cfg['options']['producer']['symbols']);
        $this->assertGreaterThan(10, count($cfg['options']['producer']['symbols']));
    }
}
