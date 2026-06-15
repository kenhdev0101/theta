<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Cockpit\CockpitSnapshot;
use App\Cockpit\MonitorEvaluator;
use App\Cockpit\MonitorNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CockpitMonitorTest extends TestCase
{
    use RefreshDatabase;

    public function test_nominal_payload_produces_no_alerts(): void
    {
        $report = MonitorEvaluator::evaluate($this->payload(), $this->opts());

        $this->assertTrue($report['ok']);
        $this->assertSame(MonitorEvaluator::NOMINAL, $report['severity']);
        $this->assertSame([], $report['alerts']);
        $this->assertNotEmpty($report['lines']);
    }

    public function test_expiring_grant_warns_and_near_expiry_is_critical(): void
    {
        $warn = $this->payload(['status_bar.oauth' => ['label' => 'Valid · 12h left', 'expires_at' => now()->addHours(12)->toIso8601ZuluString()]]);
        $r1 = MonitorEvaluator::evaluate($warn, $this->opts());
        $this->assertSame(MonitorEvaluator::WARN, $r1['severity']);
        $this->assertStringContainsString('OAuth grant expires', $r1['alerts'][0]['message']);

        $crit = $this->payload(['status_bar.oauth' => ['label' => 'Valid · 1h left', 'expires_at' => now()->addHour()->toIso8601ZuluString()]]);
        $r2 = MonitorEvaluator::evaluate($crit, $this->opts());
        $this->assertSame(MonitorEvaluator::CRITICAL, $r2['severity']);
    }

    public function test_expired_grant_and_conflicts_are_critical(): void
    {
        $expired = $this->payload(['status_bar.oauth' => ['label' => 'Expired']]);
        $this->assertSame(MonitorEvaluator::CRITICAL, MonitorEvaluator::evaluate($expired, $this->opts())['severity']);

        $conflict = $this->payload(['reconciliation' => ['rows' => [], 'conflicts' => 2]]);
        $r = MonitorEvaluator::evaluate($conflict, $this->opts());
        $this->assertSame(MonitorEvaluator::CRITICAL, $r['severity']);
        $this->assertStringContainsString('label conflict', $r['alerts'][0]['message']);
    }

    public function test_snapshot_errors_and_kill_switch_warn(): void
    {
        $p = $this->payload([
            'snapshot_errors' => ['portfolio' => 'timeout'],
            'status_bar.kill_switch' => ['value' => 'engaged', 'label' => 'Engaged'],
        ]);
        $r = MonitorEvaluator::evaluate($p, $this->opts());

        $this->assertSame(MonitorEvaluator::WARN, $r['severity']);
        $messages = implode(' | ', array_column($r['alerts'], 'message'));
        $this->assertStringContainsString('snapshot read failures', $messages);
        $this->assertStringContainsString('Kill switch ENGAGED', $messages);
    }

    public function test_zero_activity_only_alarms_when_market_is_open(): void
    {
        $closed = $this->payload(['status_bar.market' => ['label' => 'Closed · weekend']]);
        $this->assertTrue(MonitorEvaluator::evaluate($closed, $this->opts())['ok']);

        $open = $this->payload(['status_bar.market' => ['label' => 'Open']]);
        $r = MonitorEvaluator::evaluate($open, $this->opts());
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('zero producer activity', $r['alerts'][0]['message']);
    }

    public function test_notifier_posts_slack_shaped_body_and_reports_sent(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        $report = MonitorEvaluator::evaluate($this->payload(['status_bar.market' => ['label' => 'Open']]), $this->opts());
        $result = (new MonitorNotifier)->send($report, [
            'webhook_url' => 'https://hooks.example.test/abc',
            'webhook_format' => 'slack',
        ]);

        $this->assertTrue($result['sent']);
        Http::assertSent(fn ($req) => $req->url() === 'https://hooks.example.test/abc' && isset($req->data()['text']));
    }

    public function test_notifier_no_url_is_a_clean_skip(): void
    {
        $result = (new MonitorNotifier)->send(['severity' => 'nominal', 'summary' => 'x', 'alerts' => [], 'lines' => []], []);
        $this->assertFalse($result['sent']);
        $this->assertStringContainsString('no webhook_url', $result['reason']);
    }

    public function test_command_records_digest_and_pushes_heartbeat(): void
    {
        config()->set('harness.telemetry.dir', storage_path('framework/testing/monitor-'.getmypid()));
        config()->set('harness.monitor', ['enabled' => true, 'webhook_url' => 'https://hooks.example.test/x', 'webhook_format' => 'slack']);
        Cache::put(CockpitSnapshot::CACHE_KEY, $this->snapshot(), now()->addMinutes(15));
        Http::fake(['*' => Http::response('ok', 200)]);

        $this->artisan('theta:cockpit-monitor', ['--heartbeat' => true])->assertExitCode(0);

        Http::assertSent(fn ($req) => str_contains((string) ($req->data()['text'] ?? ''), 'theta monitor'));
    }

    /** @return array<string,mixed> */
    private function opts(): array
    {
        return ['grant_warn_hours' => 24, 'snapshot_stale_seconds' => 180];
    }

    /**
     * A nominal cockpit payload, with optional dot-path overrides.
     *
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function payload(array $overrides = []): array
    {
        $payload = [
            'status_bar' => [
                'oauth' => ['label' => 'Valid · 4d 16h left', 'expires_at' => now()->addDays(4)->toIso8601ZuluString()],
                'snapshot' => ['label' => '3s ago', 'cache' => 'warm', 'age' => 3, 'ok' => true],
                'kill_switch' => ['value' => 'disengaged', 'label' => 'Disengaged'],
                'market' => ['label' => 'Closed · weekend'],
                'mode' => ['label' => 'Auto'],
                'broker_credits' => ['label' => '5 / 45'],
            ],
            'scorecard' => ['candidates' => 0, 'reviewed' => 0, 'placed' => 0, 'filled' => 0, 'blocked' => 0, 'rejected' => 0],
            'account' => ['pnl' => ['realized_today' => 0.0, 'unrealized_open' => null], 'open_positions' => ['option' => 0, 'equity' => 0]],
            'why_not_trading' => [],
            'open_orders' => [],
            'reconciliation' => ['rows' => [], 'conflicts' => 0],
            'mcp_health' => ['errors' => 0, 'total' => 10],
            'snapshot_errors' => [],
        ];

        foreach ($overrides as $path => $value) {
            data_set($payload, $path, $value);
        }

        return $payload;
    }

    /** @return array<string,mixed> */
    private function snapshot(): array
    {
        return [
            'captured_at' => now()->toIso8601ZuluString(),
            'ok' => true, 'errors' => [], 'account' => '872449178', 'capability' => null,
            'market' => ['schedule' => null], 'cash' => 482.47, 'buying_power' => 25.39,
            'settled' => ['settled_cash' => 482.47, 'unsettled_sell_proceeds' => 0.0, 'assumptions' => []],
            'positions' => ['equity' => [], 'option' => []],
            'orders' => ['equity' => [], 'option' => []],
            'reconciliation' => ['equity' => [], 'option' => [], 'conflicts' => []],
            'derived' => ['open_option_positions' => 0, 'open_equity_positions' => 0, 'entered_today_debit' => 0.0, 'positions_market_value' => null],
        ];
    }
}
