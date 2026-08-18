<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\RobinhoodTradeJournal;
use App\Models\ThesisIntent;
use App\Robinhood\Thesis\ThesisLaneConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The scalp lane must not be able to touch a thesis-position play, and the
 * boundary has to be code rather than the current env-flag configuration.
 *
 * Two reachable paths existed:
 *
 *   1. `produceExitIntents` enumerates raw broker option positions with no
 *      journal join, and `exit_max_hold_minutes` defaults to 1.0 — a filled
 *      45-DTE thesis position would be sold at the bid ~60 seconds after it
 *      filled, the exact churn the thesis lane exists to stop.
 *   2. `theta:option-autopilot --status=thesis_assembled` would claim and
 *      place thesis plays with none of the entry-window, chase-cap or
 *      verified-cancel discipline.
 */
class ThesisLaneBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private int $reviewCalls = 0;

    private int $placeCalls = 0;

    private array $optionPositions = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-08-17 10:00:00', 'America/New_York'));
        config()->set('harness.oauth_token', 'fixture-token');
        config()->set('harness.kill_switch', false);
        config()->set('harness.mode', 'auto');
        config()->set('harness.options.enabled', true);
        config()->set('harness.options.producer.enabled', true);
        config()->set('harness.options.producer.symbols', ['SPY']);
        config()->set('harness.options.producer.exit_max_hold_minutes', 1.0);
        config()->set('harness.alpaca.key_id', 'alpaca-key');
        config()->set('harness.alpaca.secret_key', 'alpaca-secret');
    }

    public function test_the_scalp_exit_engine_will_not_liquidate_a_thesis_position(): void
    {
        $this->thesisPosition(status: 'filled');
        $this->optionPositions = [[
            'option_id' => 'opt-thesis',
            'chain_id' => 'chain-c',
            'underlying_symbol' => 'C',
            'underlying_type' => 'equity',
            'type' => 'call',
            'expiration_date' => '2026-10-16',
            'strike_price' => '100.0000',
            'quantity' => '1',
            'average_price' => '210.0000',
            'tradability' => 'tradable',
            'state' => 'active',
            // Held 45 minutes: far past the scalp lane's 1-minute max hold.
            'opened_at' => now()->subMinutes(45)->toIso8601String(),
        ]];
        $this->fakeDataAndBroker();

        $this->artisan('theta:option-intent-producer --symbols=SPY --limit=1')
            ->expectsOutputToContain('thesis-lane position, not scalp-owned')
            ->assertExitCode(0);

        $this->assertSame(0, RobinhoodTradeJournal::query()
            ->where('side', 'sell')
            ->where('position_effect', 'close')
            ->count(), 'the scalp exit engine must never write a close intent for a thesis position');
    }

    public function test_the_scalp_autopilot_cannot_claim_a_thesis_play_even_when_pointed_at_it(): void
    {
        $row = $this->thesisPosition(status: ThesisLaneConfig::STATUS_ASSEMBLED);
        $this->fakeDataAndBroker();

        $this->artisan('theta:option-autopilot --limit=4 --status='.ThesisLaneConfig::STATUS_ASSEMBLED)
            ->expectsOutput('no eligible option intents')
            ->assertExitCode(0);

        $this->assertSame(0, $this->reviewCalls);
        $this->assertSame(0, $this->placeCalls);
        $this->assertSame(ThesisLaneConfig::STATUS_ASSEMBLED, $row->refresh()->status);
    }

    public function test_the_scalp_autopilot_still_claims_its_own_reviewed_rows(): void
    {
        // The boundary must not disarm the scalp lane's own work.
        RobinhoodTradeJournal::query()->create([
            'account_number' => (string) config('harness.agentic_account'),
            'asset_class' => 'option',
            'symbol' => 'SPY',
            'underlying_symbol' => 'SPY',
            'option_type' => 'call',
            'expiration_date' => '2026-08-21',
            'strike_price' => 100.0,
            'status' => 'reviewed',
            'intent' => 'long_call',
            'side' => 'buy',
            'position_effect' => 'open',
            'order_type' => 'limit',
            'time_in_force' => 'gfd',
            'market_hours' => 'regular_hours',
            'quantity' => 1,
            'limit_price' => 0.50,
            'dollar_amount' => 50.00,
            'strategy_label' => 'theta_option_single_leg',
        ]);
        $this->fakeDataAndBroker();

        $this->artisan('theta:option-autopilot --limit=1')->assertExitCode(0);

        $this->assertSame(1, $this->placeCalls, 'the scalp lane must still place its own intents');
    }

    private function thesisPosition(string $status): RobinhoodTradeJournal
    {
        $thesis = ThesisIntent::query()->create([
            'underlying_symbol' => 'C',
            'direction' => ThesisIntent::DIRECTION_BULLISH,
            'structure' => ThesisIntent::STRUCTURE_LONG_CALL,
            'dte_min' => 30,
            'dte_max' => 70,
            'max_debit' => 500.0,
            'entry_limit_mode' => 'mid',
            'entry_limit_offset_bps' => 0.0,
            'thesis' => 'Rate-cut path supports the money-centre banks.',
            'price_stop' => 88.0,
            'time_stop_date' => Carbon::parse('2026-10-01'),
            'profit_target_bps' => 6000.0,
            'status' => ThesisIntent::STATUS_ACTIVE,
            'activated_at' => now(),
        ]);

        return RobinhoodTradeJournal::query()->create([
            'account_number' => (string) config('harness.agentic_account'),
            'asset_class' => 'option',
            'symbol' => 'C',
            'underlying_symbol' => 'C',
            'option_id' => 'opt-thesis',
            'chain_id' => 'chain-c',
            'option_type' => 'call',
            'expiration_date' => '2026-10-16',
            'strike_price' => 100.0,
            'status' => $status,
            'intent' => 'long_call',
            'side' => 'buy',
            'position_effect' => 'open',
            'order_type' => 'limit',
            'time_in_force' => 'gfd',
            'market_hours' => 'regular_hours',
            'quantity' => 1,
            'limit_price' => 2.10,
            'dollar_amount' => 210.00,
            'filled_quantity' => $status === 'filled' ? 1 : null,
            'average_fill_price' => $status === 'filled' ? 2.10 : null,
            'filled_at' => $status === 'filled' ? now()->subMinutes(45) : null,
            'strategy_label' => ThesisLaneConfig::STRATEGY_LABEL,
            'structure_class' => 'single_leg_long',
            'thesis_intent_id' => $thesis->id,
        ]);
    }

    private function fakeDataAndBroker(): void
    {
        Http::fake(function (Request $request) {
            $url = (string) $request->url();
            if (preg_match('#/v2/stocks/([^/]+)/quotes/latest#', $url) === 1) {
                return Http::response(['quote' => [
                    'bp' => 100.00, 'bs' => 10, 'ap' => 100.02, 'as' => 12,
                    't' => now()->subSecond()->toIso8601ZuluString('microsecond'),
                ]]);
            }
            if (preg_match('#/v2/stocks/([^/]+)/bars#', $url) === 1) {
                // Flat tape: no momentum, so the producer's ENTRY path stays
                // quiet and the test isolates the exit path.
                $bars = [];
                for ($i = 0; $i < 10; $i++) {
                    $bars[] = [
                        'o' => 100.0, 'h' => 100.0, 'l' => 100.0, 'c' => 100.0, 'v' => 1000,
                        't' => now()->subMinutes(10 - $i)->toIso8601ZuluString('microsecond'),
                    ];
                }

                return Http::response(str_contains($url, '/bars/latest')
                    ? ['bar' => $bars[array_key_last($bars)]]
                    : ['bars' => $bars]);
            }

            $payload = $request->data();
            $method = $payload['method'] ?? '';

            if ($method === 'initialize') {
                return Http::response(['jsonrpc' => '2.0', 'id' => $payload['id'], 'result' => []]);
            }
            if ($method === 'notifications/initialized') {
                return Http::response('', 202);
            }
            if ($method === 'tools/list') {
                return Http::response([
                    'jsonrpc' => '2.0',
                    'id' => $payload['id'],
                    'result' => ['tools' => array_map(
                        fn (string $name): array => ['name' => $name],
                        (array) config('harness.options.required_tools'),
                    )],
                ]);
            }
            if ($method !== 'tools/call') {
                return Http::response(['jsonrpc' => '2.0', 'id' => $payload['id'] ?? null, 'result' => []]);
            }

            $tool = $payload['params']['name'] ?? '';
            $arguments = $payload['params']['arguments'] ?? [];

            return match ($tool) {
                'get_option_chains' => $this->toolResponse($payload['id'], ['data' => ['chains' => [[
                    'id' => 'chain-spy',
                    'symbol' => 'SPY',
                    'expiration_dates' => ['2026-08-21'],
                    'trade_value_multiplier' => '100.0000',
                ]]]]),
                'get_option_instruments' => $this->toolResponse($payload['id'], ['data' => ['instruments' => [[
                    'id' => 'opt-'.($arguments['chain_symbol'] ?? 'SPY'),
                    'chain_id' => 'chain-spy',
                    'chain_symbol' => $arguments['chain_symbol'] ?? 'SPY',
                    'underlying_type' => 'equity',
                    'expiration_date' => $arguments['expiration_dates'] ?? '2026-08-21',
                    'strike_price' => $arguments['strike_price'] ?? '100.0000',
                    'type' => $arguments['type'] ?? 'call',
                    'state' => 'active',
                    'tradability' => 'tradable',
                ]]]]),
                'get_option_quotes' => $this->toolResponse($payload['id'], ['data' => ['results' => array_map(
                    fn (string $id): array => ['quote' => [
                        'instrument_id' => $id,
                        'bid_price' => '0.480000',
                        'ask_price' => '0.500000',
                        'mark_price' => '0.490000',
                        'updated_at' => now()->subSeconds(2)->toIso8601ZuluString('microsecond'),
                    ]],
                    array_values((array) ($arguments['instrument_ids'] ?? ['opt-SPY'])),
                )]]),
                'get_portfolio' => $this->toolResponse($payload['id'], ['data' => [
                    'cash' => '1000.00',
                    'buying_power' => ['buying_power' => '1000.00'],
                ]]),
                'get_equity_orders' => $this->toolResponse($payload['id'], ['data' => ['orders' => []]]),
                'get_option_orders' => $this->toolResponse($payload['id'], ['data' => ['orders' => []]]),
                'get_option_positions' => $this->toolResponse($payload['id'], ['data' => ['positions' => $this->optionPositions]]),
                'get_accounts' => $this->toolResponse($payload['id'], ['data' => ['accounts' => [[
                    'account_number' => config('harness.agentic_account'),
                    'type' => 'cash',
                    'option_level' => 'option_level_2',
                    'agentic_allowed' => true,
                ]]]]),
                'review_option_order' => $this->review($payload['id']),
                'place_option_order' => $this->place($payload['id']),
                default => $this->toolResponse($payload['id'], ['ok' => true]),
            };
        });
    }

    private function review(mixed $id): mixed
    {
        $this->reviewCalls++;

        return $this->toolResponse($id, ['ok' => true, 'alerts' => []]);
    }

    private function place(mixed $id): mixed
    {
        $this->placeCalls++;

        return $this->toolResponse($id, ['order_id' => 'broker-'.$this->placeCalls, 'state' => 'queued']);
    }

    private function toolResponse(mixed $id, array $body): mixed
    {
        return Http::response([
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => ['isError' => false, 'content' => [['type' => 'text', 'text' => json_encode($body)]]],
        ]);
    }
}
