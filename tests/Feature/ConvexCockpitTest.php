<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ConvexProbeRun;
use App\Robinhood\Mcp\OAuthTokenStore;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class ConvexCockpitTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-20T18:40:00Z'));
        $this->dir = sys_get_temp_dir().'/theta-convex-ui-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($this->dir.'/storage/harness');
        $this->app->useStoragePath($this->dir.'/storage');
        config()->set([
            'app.key' => 'base64:'.base64_encode(str_repeat('t', 32)),
            'harness.agentic_account' => 'test-pinned-account',
            'harness.kill_switch' => false,
            'harness.convex.enabled' => true,
            'harness.convex.auto_entry_enabled' => true,
            'harness.convex.tickers' => ['SPY', 'XLF', 'IWM'],
            'harness.convex.matrix_path' => $this->dir.'/matrix.csv',
            'harness.convex.tenors_per_ticker' => 2,
            'harness.telemetry.dir' => $this->dir,
            'harness.broker_credits.cache_key' => 'test-convex-ui-credits',
            'harness.broker_credits.max_per_minute' => 45,
            'harness.broker_credits.reserve_for_stewardship' => 9,
            'harness.options.per_play_max_debit' => 75,
            'harness.options.per_day_max_debit' => 250,
            'harness.options.per_underlying_max_debit' => 50,
        ]);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_page_and_polling_are_read_only_and_do_not_expose_credentials(): void
    {
        OAuthTokenStore::fromConfig(config('harness'))->put([
            'access_token' => 'fixture-secret-access', 'refresh_token' => 'fixture-secret-refresh',
            'expires_at' => '2026-10-27T19:43:02Z',
        ]);
        $this->seedRun(['block_reason' => 'fixture-secret-error']);
        $this->event('mcp_calls', ['tool' => 'get_portfolio', 'ok' => false, 'error' => 'credits exhausted Bearer fixture-secret-error']);
        $before = ConvexProbeRun::query()->get()->toJson();
        $queries = [];
        \DB::listen(function (QueryExecuted $q) use (&$queries): void {
            $queries[] = $q->sql;
        });

        $this->get('/cockpit/convex')->assertOk()->assertSee('Convex desk')->assertSee('no broker requests', false);
        $r = $this->getJson('/cockpit/convex/data')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('posture.caps.per_underlying', 50)
            ->assertJsonPath('auth.expires_at', '2026-10-27T19:43:02Z')
            ->assertJsonPath('broker.failure_reasons.Broker request budget exhausted', 1);
        $this->getJson('/cockpit/convex/data')->assertOk();
        Http::assertNothingSent();
        $this->assertFalse(Cache::has('theta:cockpit:snapshot'));
        $this->assertSame($before, ConvexProbeRun::query()->get()->toJson());
        foreach ($queries as $sql) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|replace|alter|create|drop)\b/i', $sql);
        }
        foreach (['fixture-secret', 'access_token', 'refresh_token', 'test-pinned-account', 'broker_payload'] as $secret) {
            $this->assertStringNotContainsString($secret, $r->getContent());
        }
    }

    public function test_signals_keep_staleness_missing_inputs_and_future_timestamps_distinct(): void
    {
        $this->matrix([
            ['SPY', '59', '2026-10-20T18:39:00Z', 'ADMIT', '', '', '0.20'],
            ['SPY', '59', '2026-10-20T18:35:00Z', 'REJECT', 'executable_wing', '', '0.19'],
            ['XLF', '59', '2026-10-19T18:39:00Z', 'ADMIT', '', '', '0.21'],
            ['IWM', '59', '2026-10-20T18:45:00Z', 'ADMIT', '', '', '0.20'],
            ['IWM', '72', '2026-02-30T18:00:00Z', 'ADMIT', '', '', '0.20'],
        ]);
        $this->event('convex_ingest', ['event' => 'block', 'ticker' => 'IWM', 'reason' => 'spot_unresolved', 'detail' => 'broker credits exhausted']);

        $r = $this->getJson('/cockpit/convex/data')->assertOk();
        $r->assertJsonPath('summary.fresh_tickers', 1)
            ->assertJsonPath('signals.0.rows.0.verdict', 'ADMIT')
            ->assertJsonPath('signals.0.fresh', true)
            ->assertJsonPath('signals.1.fresh', false)
            ->assertJsonPath('signals.1.rows.0.verdict', 'ADMIT')
            ->assertJsonPath('signals.2.rows', [])
            ->assertJsonPath('signals.2.last_attempt', 'Broker request budget exhausted');
    }

    public function test_only_pinned_account_runs_and_events_are_visible(): void
    {
        $own = $this->seedRun(['status' => 'closed', 'entry_cancel_reason' => 'resting_bid_outside_thesis_risk']);
        $other = $this->seedRun(['account_number' => 'different-account', 'underlying_symbol' => 'SECRET', 'status' => 'held']);
        $this->event('convex_probe', ['event' => 'entry_cancel_requested', 'thesis_id' => $own->thesis_id,
            'entry_cancel_reason' => 'resting_bid_outside_thesis_risk', 'entry_order_id' => 'private-order-reference']);
        $this->event('convex_probe', ['event' => 'entry_submitted', 'thesis_id' => $other->thesis_id]);

        $r = $this->getJson('/cockpit/convex/data')->assertOk()
            ->assertJsonCount(1, 'runs')->assertJsonCount(1, 'timeline')
            ->assertJsonPath('summary.active_runs', 0)
            ->assertJsonPath('timeline.0.reason', 'Bid moved outside the thesis risk budget');
        $this->assertStringNotContainsString('SECRET', $r->getContent());
        $this->assertStringNotContainsString('private-order-reference', $r->getContent());
    }

    public function test_exposure_uses_remaining_fills_and_realized_pnl_uses_new_york_close_date(): void
    {
        $this->travelTo(Carbon::parse('2026-10-21T02:00:00Z')); // Still October 20 in New York.
        $this->seedRun(['status' => 'held', 'entry_filled_qty' => 2, 'exit_filled_qty' => 1, 'entry_average_price' => 0.4]);
        $this->seedRun(['status' => 'entry_pending', 'entry_limit_price' => 0.5]); // A limit is not a fill.
        $this->seedRun(['status' => 'closed', 'entry_filled_qty' => 1, 'exit_filled_qty' => 1,
            'entry_average_price' => 0.4, 'exit_average_price' => 0.5, 'closed_at' => '2026-10-20T18:00:00Z']);
        $this->seedRun(['status' => 'closed', 'entry_filled_qty' => 1, 'exit_filled_qty' => 1,
            'entry_average_price' => 0.4, 'exit_average_price' => 0.9, 'closed_at' => '2026-10-20T03:00:00Z']);
        $this->event('mcp_calls', ['ok' => true], '2026-10-20T18:00:00Z');

        $this->getJson('/cockpit/convex/data')->assertOk()
            ->assertJsonPath('session_date', '2026-10-20')
            ->assertJsonPath('summary.active_runs', 2)
            ->assertJsonPath('summary.held_contracts', 1)
            ->assertJsonPath('summary.open_premium_at_cost', 40)
            ->assertJsonPath('summary.gross_realized_today', 10)
            ->assertJsonPath('summary.closed_filled_today', 1)
            ->assertJsonPath('broker.last_success_at', '2026-10-20T18:00:00.000000Z');
    }

    public function test_missing_fill_prices_do_not_report_zero_exposure_or_zero_pnl(): void
    {
        $this->seedRun(['status' => 'held', 'entry_filled_qty' => 1]);
        $this->seedRun(['status' => 'closed', 'entry_filled_qty' => 1, 'exit_filled_qty' => 1, 'closed_at' => now()]);
        $this->getJson('/cockpit/convex/data')->assertOk()
            ->assertJsonPath('summary.open_premium_at_cost', null)
            ->assertJsonPath('summary.gross_realized_today', null)
            ->assertJsonPath('summary.pnl_pending_runs', 1);
    }

    public function test_old_active_runs_remain_visible_beyond_recent_record_limit(): void
    {
        $old = $this->seedRun(['status' => 'ambiguous']);
        $submitted = $this->seedRun(['status' => 'closed', 'entry_submitted_at' => now()->subHour()]);
        for ($i = 0; $i < 42; $i++) {
            $this->seedRun(['status' => 'staged']);
        }
        $r = $this->getJson('/cockpit/convex/data')->assertOk()->assertJsonPath('summary.active_runs', 1);
        $this->assertContains($old->id, array_column($r->json('runs'), 'id'));
        $this->assertContains($submitted->id, array_column($r->json('runs'), 'id'));
    }

    public function test_admit_with_pending_gates_or_missing_delta_is_not_presented_as_eligible(): void
    {
        $this->matrix([
            ['SPY', '59', '2026-10-20T18:39:00Z', 'ADMIT', '', 'no_catalyst', '0.20'],
            ['XLF', '59', '2026-10-20T18:39:00Z', 'ADMIT', '', '', 'unknown'],
        ]);
        $this->getJson('/cockpit/convex/data')->assertOk()
            ->assertJsonPath('signals.0.rows.0.admissible', false)
            ->assertJsonPath('signals.1.rows.0.admissible', false);
    }

    public function test_missing_storage_is_unknown_and_malformed_telemetry_is_ignored(): void
    {
        Schema::drop('convex_probe_runs');
        file_put_contents($this->dir.'/convex_ingest-2026-10-20.jsonl', "{bad partial json\n");
        $r = $this->getJson('/cockpit/convex/data')->assertOk()
            ->assertJsonPath('summary.active_runs', null)
            ->assertJsonPath('summary.open_premium_at_cost', null)
            ->assertJsonPath('summary.gross_realized_today', null)
            ->assertJsonPath('summary.last_ingest_at', null);
        $this->assertContains('Run storage is unavailable; exposure and P&L are unknown.', $r->json('warnings'));
        Http::assertNothingSent();
    }

    public function test_bounded_telemetry_ignores_partial_lines_and_counts_only_recent_failures(): void
    {
        file_put_contents($this->dir.'/mcp_calls-2026-10-20.jsonl', str_repeat('x', 600000)."\n");
        $this->event('mcp_calls', ['ok' => false, 'error' => 'HTTP 401 fixture-secret'], '2026-10-20T18:00:00Z');
        $this->event('mcp_calls', ['ok' => false, 'error' => 'HTTP 429 fixture-secret'], '2026-10-20T18:39:00Z');
        $this->event('mcp_calls', ['ok' => true], '2026-10-20T18:39:10Z');
        file_put_contents($this->dir.'/mcp_calls-2026-10-20.jsonl', '{"unfinished":', FILE_APPEND);

        $r = $this->getJson('/cockpit/convex/data')->assertOk()
            ->assertJsonPath('broker.observed_calls_15m', 2)
            ->assertJsonPath('broker.failed_calls_15m', 1)
            ->assertJsonPath('broker.failure_reasons.Broker rate limit', 1);
        $this->assertStringNotContainsString('fixture-secret', $r->getContent());
        $this->assertNotEmpty($r->json('warnings'));
    }

    private function seedRun(array $overrides = []): ConvexProbeRun
    {
        return ConvexProbeRun::query()->create(array_merge([
            'thesis_id' => 'test-'.bin2hex(random_bytes(6)), 'leg_id' => 'call',
            'account_number' => 'test-pinned-account', 'status' => 'staged',
            'spec' => [], 'spec_hash' => str_repeat('a', 64),
            'underlying_symbol' => 'XLF', 'option_type' => 'call',
        ], $overrides));
    }

    private function event(string $stream, array $row, ?string $ts = null): void
    {
        $ts ??= now()->toIso8601ZuluString();
        file_put_contents($this->dir.'/'.$stream.'-'.substr($ts, 0, 10).'.jsonl', json_encode(['ts' => $ts] + $row)."\n", FILE_APPEND);
    }

    private function matrix(array $rows): void
    {
        $h = fopen($this->dir.'/matrix.csv', 'w');
        fputcsv($h, ['ticker', 'dte', 'snapshot_ts', 'verdict', 'failed_signals', 'pending_signals', 'far_delta'], escape: '');
        foreach ($rows as $row) {
            fputcsv($h, $row, escape: '');
        }
        fclose($h);
    }
}
