<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Cockpit\CockpitSnapshot;
use App\Models\RobinhoodTradeJournal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CockpitDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('harness.agentic_account', '872449178');
        config()->set('harness.mode', 'auto');
        config()->set('harness.kill_switch', false);
        config()->set('harness.options.enabled', true);
        config()->set('harness.telemetry.dir', storage_path('framework/testing/cockpit-'.getmypid()));
    }

    public function test_dashboard_html_renders(): void
    {
        $this->seedSnapshot();

        $this->get('/cockpit')
            ->assertOk()
            ->assertSee('theta')
            ->assertSee('Why it isn');
    }

    public function test_root_redirects_to_cockpit(): void
    {
        $this->get('/')->assertRedirect('/cockpit');
    }

    public function test_data_endpoint_decodes_broker_truth_and_decisions(): void
    {
        $this->seedSnapshot();

        // A producer block (the "why not trading" path) ...
        RobinhoodTradeJournal::query()->create([
            'asset_class' => 'option',
            'symbol' => 'NVDA',
            'underlying_symbol' => 'NVDA',
            'status' => 'risk_aborted',
            'intent' => 'producer_scan',
            'notes' => ['producer_block' => ['reason' => 'no_marketable_contract: spread_above_max']],
        ]);

        // ... a reviewed candidate carrying structure context ...
        RobinhoodTradeJournal::query()->create([
            'asset_class' => 'option',
            'symbol' => 'SPY',
            'underlying_symbol' => 'SPY',
            'option_type' => 'call',
            'strike_price' => 540,
            'status' => 'reviewed',
            'intent' => 'long_call',
            'structure_class' => 'coil_expansion',
            'relative_strength_state' => 'outperforming',
            'limit_price' => 1.25,
        ]);

        $res = $this->getJson('/cockpit/data')->assertOk();

        // Status bar decodes the safety-critical kill switch and mode.
        $res->assertJsonPath('status_bar.kill_switch.label', 'Disengaged')
            ->assertJsonPath('status_bar.kill_switch.tone', 'good')
            ->assertJsonPath('status_bar.mode.label', 'Auto')
            ->assertJsonPath('status_bar.account', '872449178');

        // Broker truth flowed from the seeded snapshot.
        $res->assertJsonPath('account.cash.value', 1000.25)
            ->assertJsonPath('account.buying_power.value', 950.5);

        // Why-not-trading rollup decodes the producer block reason (no raw enum).
        $res->assertJsonPath('why_not_trading.0.reason.label', 'No marketable contract')
            ->assertJsonPath('why_not_trading.0.count', 1);

        // Scorecard counts the whole day.
        $res->assertJsonPath('scorecard.blocked', 1)
            ->assertJsonPath('scorecard.reviewed', 1);

        // Decisions carry decoded status + structure chips, never raw enums.
        $payload = $res->json();
        $labels = array_map(fn ($d) => $d['status']['label'], $payload['decisions']);
        $this->assertContains('Reviewed', $labels);
        $this->assertContains('Risk-aborted', $labels);

        $spy = collect($payload['decisions'])->firstWhere('symbol', 'SPY');
        $this->assertSame('Long call', $spy['intent']['label']);
        $structureLabels = array_map(fn ($s) => $s['term']['label'], $spy['structure']);
        $this->assertContains('Coil expansion', $structureLabels);
        $this->assertContains('Outperforming', $structureLabels);
    }

    public function test_flat_account_reports_account_value_as_cash_not_unverified(): void
    {
        // Regression: a flat account (no positions) has a positions value of $0,
        // so account value must equal cash and read verified — not "unverified".
        $snapshot = $this->snapshotArray();
        $snapshot['positions'] = ['equity' => [], 'option' => []];
        $snapshot['derived']['positions_market_value'] = 0.0;
        $snapshot['derived']['open_option_positions'] = 0;
        $snapshot['cash'] = 482.47;
        Cache::put(CockpitSnapshot::CACHE_KEY, $snapshot, now()->addMinutes(15));

        $this->getJson('/cockpit/data')
            ->assertOk()
            ->assertJsonPath('account.account_value.value', 482.47)
            ->assertJsonPath('account.account_value.verified', true)
            ->assertJsonPath('account.positions_value.value', 0)
            ->assertJsonPath('account.positions_value.verified', true)
            // Flat account: open unrealized P&L is a verified $0, not unverified.
            ->assertJsonPath('account.pnl.unrealized_open', 0)
            ->assertJsonPath('account.pnl.realized_today', 0);
    }

    public function test_decision_age_reflects_elapsed_time_not_zero(): void
    {
        // Regression: Carbon 3 diffs are signed, so the old max(0, …) clamped
        // every elapsed age to 0 ("time since decision" always said zero).
        $this->seedSnapshot();
        $row = RobinhoodTradeJournal::query()->create([
            'asset_class' => 'option',
            'symbol' => 'SPY',
            'underlying_symbol' => 'SPY',
            'status' => 'reviewed',
            'intent' => 'long_call',
        ]);
        RobinhoodTradeJournal::query()->where('id', $row->id)->update(['updated_at' => now()->subSeconds(150)]);

        $decisions = $this->getJson('/cockpit/data')->assertOk()->json('decisions');
        $decision = collect($decisions)->firstWhere('id', $row->id);

        $this->assertNotNull($decision);
        $this->assertGreaterThanOrEqual(120, $decision['age'], 'age should reflect elapsed seconds, not 0');
    }

    public function test_data_endpoint_surfaces_snapshot_read_failures(): void
    {
        $snapshot = $this->snapshotArray();
        $snapshot['ok'] = false;
        $snapshot['errors'] = ['portfolio' => 'broker timeout'];
        Cache::put(CockpitSnapshot::CACHE_KEY, $snapshot, now()->addMinutes(15));

        $this->getJson('/cockpit/data')
            ->assertOk()
            ->assertJsonPath('snapshot_errors.portfolio', 'broker timeout')
            ->assertJsonPath('status_bar.snapshot.tone', 'warn');
    }

    private function seedSnapshot(): void
    {
        Cache::put(CockpitSnapshot::CACHE_KEY, $this->snapshotArray(), now()->addMinutes(15));
    }

    /** @return array<string,mixed> */
    private function snapshotArray(): array
    {
        return [
            'captured_at' => now()->toIso8601ZuluString(),
            'ok' => true,
            'errors' => [],
            'account' => '872449178',
            'capability' => ['option_level' => 'option_level_2', 'agentic_allowed' => true],
            'market' => ['is_open' => true, 'opens_at' => null, 'closes_at' => null],
            'cash' => 1000.25,
            'buying_power' => 950.5,
            'settled' => ['settled_cash' => 900.0, 'unsettled_sell_proceeds' => 100.0, 'assumptions' => ['source' => 'estimated (T+1)', 'unsettled_rows' => []]],
            'positions' => [
                'equity' => [],
                'option' => [[
                    'symbol' => 'AAPL', 'option_type' => 'call', 'strike' => 220.0, 'expiration' => '2026-06-19',
                    'quantity' => 1.0, 'average_price' => 1.10, 'current_price' => 1.40,
                    'cost_basis' => 110.0, 'market_value' => 140.0, 'unrealized_pnl' => 30.0,
                ]],
            ],
            'orders' => ['equity' => [], 'option' => []],
            'reconciliation' => [
                'equity' => [],
                'option' => ['LIVE_FILLED' => 1],
                'conflicts' => [],
            ],
            'derived' => [
                'open_option_positions' => 1,
                'open_equity_positions' => 0,
                'entered_today_debit' => 110.0,
                'positions_market_value' => 140.0,
            ],
        ];
    }
}
