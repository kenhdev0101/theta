<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\RobinhoodTradeJournal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OptionAutopilotCommandTest extends TestCase
{
    use RefreshDatabase;

    private int $reviewCalls = 0;

    private int $placeCalls = 0;

    private string $quoteAsk = '0.500000';

    private string $quoteBid = '0.480000';

    private array $lastPlaceArguments = [];

    public function test_same_run_context_decrements_settled_cash_after_submitted_buy(): void
    {
        $this->travelTo(Carbon::parse('2026-06-12 10:00:00', 'America/Los_Angeles'));
        config()->set('harness.oauth_token', 'fixture-token');
        config()->set('harness.kill_switch', false);
        config()->set('harness.mode', 'auto');
        config()->set('harness.options.per_play_max_debit', 75.0);
        config()->set('harness.options.per_day_max_debit', 200.0);
        config()->set('harness.options.per_underlying_max_debit', 100.0);
        config()->set('harness.options.max_open_positions', 4);

        $first = $this->reviewedRow('AAPL');
        $second = $this->reviewedRow('MSFT');

        $this->fakeMcp(settledCash: 75.0);

        $this->artisan('theta:option-autopilot --limit=4')->assertExitCode(0);

        $this->assertSame(1, $this->reviewCalls, 'second order should block before broker review');
        $this->assertSame(1, $this->placeCalls, 'second order should not place after same-run cash decrement');
        $this->assertSame('submitted', $first->refresh()->status);
        $this->assertSame('risk_aborted', $second->refresh()->status);
        $this->assertSame('rail_block: BLOCK_CAP', $second->notes['autopilot_block']['reason'] ?? null);
    }

    public function test_autopilot_claimed_rows_are_not_selected_again(): void
    {
        $this->travelTo(Carbon::parse('2026-06-12 10:00:00', 'America/Los_Angeles'));
        config()->set('harness.oauth_token', 'fixture-token');
        config()->set('harness.kill_switch', false);
        config()->set('harness.mode', 'auto');

        $this->reviewedRow('AAPL')->forceFill(['status' => 'autopilot_claimed'])->save();
        $this->fakeMcp(settledCash: 75.0);

        $this->artisan('theta:option-autopilot --limit=4')
            ->expectsOutput('no eligible option intents')
            ->assertExitCode(0);

        $this->assertSame(0, $this->reviewCalls);
        $this->assertSame(0, $this->placeCalls);
    }

    public function test_autopilot_reprices_reviewed_buy_to_fresh_ask_before_place(): void
    {
        $this->travelTo(Carbon::parse('2026-06-12 10:00:00', 'America/Los_Angeles'));
        config()->set('harness.oauth_token', 'fixture-token');
        config()->set('harness.kill_switch', false);
        config()->set('harness.mode', 'auto');
        config()->set('harness.options.per_play_max_debit', 75.0);
        config()->set('harness.options.per_underlying_max_debit', 100.0);

        $row = $this->reviewedRow('SPY')->forceFill([
            'limit_price' => 0.50,
            'dollar_amount' => 50.00,
        ]);
        $row->save();
        $this->quoteAsk = '0.610000';
        $this->quoteBid = '0.600000';
        $this->fakeMcp(settledCash: 100.0);

        $this->artisan('theta:option-autopilot --limit=1')->assertExitCode(0);

        $this->assertSame(1, $this->placeCalls);
        $this->assertSame('0.61', $this->lastPlaceArguments['price']);
        $this->assertSame('submitted', $row->refresh()->status);
        $this->assertSame('0.61000000', (string) $row->limit_price);
        $this->assertSame(0.50, $row->notes['autopilot_reprice']['from']);
        $this->assertSame(0.61, $row->notes['autopilot_reprice']['to']);
    }

    public function test_autopilot_preserves_producer_scanner_payload_when_submitting(): void
    {
        $this->travelTo(Carbon::parse('2026-06-12 10:00:00', 'America/Los_Angeles'));
        config()->set('harness.oauth_token', 'fixture-token');
        config()->set('harness.kill_switch', false);
        config()->set('harness.mode', 'auto');
        config()->set('harness.options.per_play_max_debit', 75.0);

        $row = $this->reviewedRow('TSLA')->forceFill([
            'scanner_payload' => [
                'intent_source' => 'theta:option-intent-producer',
                'selection' => [
                    'allocation' => [
                        'method' => 'capital_velocity_v1',
                        'expected_gain' => 1.23,
                    ],
                ],
            ],
        ]);
        $row->save();
        $this->fakeMcp(settledCash: 100.0);

        $this->artisan('theta:option-autopilot --limit=1')->assertExitCode(0);

        $row->refresh();
        $this->assertSame('submitted', $row->status);
        $this->assertSame('theta:option-intent-producer', $row->scanner_payload['intent_source']);
        $this->assertSame('theta:option-autopilot:journal:'.$row->id, $row->scanner_payload['execution_intent_source']);
        $this->assertSame('capital_velocity_v1', $row->scanner_payload['selection']['allocation']['method']);
        $this->assertSame(1.23, $row->scanner_payload['selection']['allocation']['expected_gain']);
    }


    private function reviewedRow(string $symbol): RobinhoodTradeJournal
    {
        return RobinhoodTradeJournal::query()->create([
            'account_number' => config('harness.agentic_account'),
            'asset_class' => 'option',
            'symbol' => $symbol,
            'underlying_symbol' => $symbol,
            'option_type' => 'call',
            'expiration_date' => '2026-06-15',
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
            'structure_class' => 'single_leg_long',
            'reviewed_at' => now(),
        ]);
    }

    private function fakeMcp(float $settledCash): void
    {
        Http::fake(function (Request $request) use ($settledCash) {
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
                'get_option_instruments' => $this->toolResponse($payload['id'], [
                    'data' => ['instruments' => [[
                        'id' => 'opt-'.$arguments['chain_symbol'],
                        'chain_id' => 'chain-'.$arguments['chain_symbol'],
                        'chain_symbol' => $arguments['chain_symbol'],
                        'underlying_type' => 'equity',
                        'expiration_date' => $arguments['expiration_dates'],
                        'strike_price' => $arguments['strike_price'],
                        'type' => $arguments['type'],
                        'state' => 'active',
                        'tradability' => 'tradable',
                    ]]],
                ]),
                'get_option_quotes' => $this->toolResponse($payload['id'], [
                    'data' => ['results' => [['quote' => [
                        'instrument_id' => $arguments['instrument_ids'][0],
                        'ask_price' => $this->quoteAsk,
                        'bid_price' => $this->quoteBid,
                        'mark_price' => '0.490000',
                        'updated_at' => now()->subSeconds(2)->toIso8601ZuluString('microsecond'),
                    ]]]],
                ]),
                'get_portfolio' => $this->toolResponse($payload['id'], ['data' => [
                    'cash' => (string) $settledCash,
                    'buying_power' => ['buying_power' => (string) $settledCash],
                ]]),
                'get_equity_orders' => $this->toolResponse($payload['id'], ['data' => ['orders' => []]]),
                'get_option_orders' => $this->toolResponse($payload['id'], ['data' => ['orders' => []]]),
                'get_option_positions' => $this->toolResponse($payload['id'], ['data' => ['positions' => []]]),
                'get_accounts' => $this->toolResponse($payload['id'], ['data' => ['accounts' => [[
                    'account_number' => config('harness.agentic_account'),
                    'type' => 'cash',
                    'option_level' => 'option_level_2',
                    'agentic_allowed' => true,
                ]]]]),
                'review_option_order' => $this->review($payload['id']),
                'place_option_order' => $this->place($payload['id'], $arguments),
                default => $this->toolResponse($payload['id'], ['ok' => true]),
            };
        });
    }

    private function review(mixed $id)
    {
        $this->reviewCalls++;

        return $this->toolResponse($id, ['ok' => true, 'alerts' => []]);
    }

    private function place(mixed $id, array $arguments)
    {
        $this->placeCalls++;
        $this->lastPlaceArguments = $arguments;

        return $this->toolResponse($id, [
            'order_id' => 'broker-'.$this->placeCalls,
            'state' => 'queued',
            'ref_id' => $arguments['ref_id'] ?? null,
        ]);
    }

    private function toolResponse(mixed $id, array $body)
    {
        return Http::response([
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => ['isError' => false, 'content' => [['type' => 'text', 'text' => json_encode($body)]]],
        ]);
    }
}
