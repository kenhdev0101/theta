<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\RobinhoodTradeJournal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OptionReconcileCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_reconcile_applies_broker_terminal_and_posted_states_to_option_journal(): void
    {
        $this->travelTo(Carbon::parse('2026-06-12 11:15:00', 'America/Los_Angeles'));
        config()->set('harness.oauth_token', 'fixture-token');
        config()->set('harness.broker_credits.enabled', false);
        config()->set('harness.telemetry.dir', storage_path('framework/testing/harness'));

        $posted = $this->journalRow('ref-posted', 'broker-posted', 'submitted');
        $cancelled = $this->journalRow('ref-cancelled', 'broker-cancelled', 'submitted');
        $rejected = $this->journalRow('ref-rejected', 'broker-rejected', 'autopilot_claimed');
        $filled = $this->journalRow('ref-filled', 'broker-filled', 'submitted');

        $this->fakeMcp([
            $this->brokerOrder('ref-posted', 'broker-posted', 'queued'),
            $this->brokerOrder('ref-cancelled', 'broker-cancelled', 'cancelled'),
            $this->brokerOrder('ref-rejected', 'broker-rejected', 'rejected'),
            $this->brokerOrder('ref-filled', 'broker-filled', 'filled', processedQuantity: '1', averagePrice: '0.420000'),
        ]);

        $this->artisan('theta:option-reconcile')->assertExitCode(0);

        $this->assertSame('open', $posted->refresh()->status);
        $this->assertNull($posted->closed_at);

        $this->assertSame('cancelled', $cancelled->refresh()->status);
        $this->assertNotNull($cancelled->closed_at);

        $this->assertSame('rejected', $rejected->refresh()->status);
        $this->assertNotNull($rejected->closed_at);

        $this->assertSame('filled', $filled->refresh()->status);
        $this->assertSame('1.00000000', (string) $filled->filled_quantity);
        $this->assertSame('0.42000000', (string) $filled->average_fill_price);
        $this->assertNotNull($filled->filled_at);
    }

    public function test_reconcile_books_realized_pnl_on_filled_sell_to_close(): void
    {
        $this->travelTo(Carbon::parse('2026-06-12 11:20:00', 'America/Los_Angeles'));
        config()->set('harness.oauth_token', 'fixture-token');
        config()->set('harness.broker_credits.enabled', false);
        config()->set('harness.telemetry.dir', storage_path('framework/testing/harness'));

        $this->journalRow('ref-entry', 'broker-entry', 'filled', [
            'side' => 'buy',
            'position_effect' => 'open',
            'intent' => 'long_call',
            'filled_quantity' => 1,
            'average_fill_price' => 0.42,
            'filled_at' => now()->subMinutes(5),
        ]);
        $close = $this->journalRow('ref-close', 'broker-close', 'submitted', [
            'side' => 'sell',
            'position_effect' => 'close',
            'intent' => 'close_long_call',
            'limit_price' => 0.55,
            'dollar_amount' => 0.00,
        ]);

        $this->fakeMcp([
            $this->brokerOrder('ref-close', 'broker-close', 'filled', processedQuantity: '1', averagePrice: '0.550000', side: 'sell', positionEffect: 'close'),
        ]);

        $this->artisan('theta:option-reconcile')->assertExitCode(0);

        $this->assertSame('filled', $close->refresh()->status);
        $this->assertSame('13.00', (string) $close->realized_pnl);
        $this->assertNotNull($close->closed_at);
    }

    public function test_one_pass_books_realized_pnl_when_broker_lists_close_before_open(): void
    {
        $this->travelTo(Carbon::parse('2026-10-07 11:59:00', 'America/Los_Angeles'));
        config()->set('harness.oauth_token', 'fixture-token');
        config()->set('harness.broker_credits.enabled', false);
        config()->set('harness.telemetry.dir', storage_path('framework/testing/harness'));

        $entry = $this->journalRow('ref-entry', 'broker-entry', 'submitted', [
            'side' => 'buy', 'position_effect' => 'open', 'limit_price' => 0.22,
        ]);
        $this->travelTo(Carbon::parse('2026-10-07 12:02:00', 'America/Los_Angeles'));
        $close = $this->journalRow('ref-close', 'broker-close', 'submitted', [
            'side' => 'sell', 'position_effect' => 'close', 'limit_price' => 0.20,
        ]);
        $this->travelTo(Carbon::parse('2026-10-07 12:03:00', 'America/Los_Angeles'));
        $this->fakeMcp([
            $this->brokerOrder('ref-close', 'broker-close', 'filled', processedQuantity: '1', averagePrice: '0.200000', side: 'sell', positionEffect: 'close'),
            $this->brokerOrder('ref-entry', 'broker-entry', 'filled', processedQuantity: '1', averagePrice: '0.220000'),
        ]);

        $this->artisan('theta:option-reconcile')->assertExitCode(0);

        $this->assertSame('filled', $entry->refresh()->status);
        $this->assertSame('filled', $close->refresh()->status);
        $this->assertSame('-2.00', (string) $close->realized_pnl);
    }

    private function journalRow(string $refId, string $brokerOrderId, string $status, array $overrides = []): RobinhoodTradeJournal
    {
        return RobinhoodTradeJournal::query()->create(array_merge([
            'account_number' => config('harness.agentic_account'),
            'asset_class' => 'option',
            'symbol' => 'SPY',
            'underlying_symbol' => 'SPY',
            'option_id' => 'opt-spy',
            'option_type' => 'call',
            'expiration_date' => '2026-06-15',
            'strike_price' => 500.0,
            'status' => $status,
            'intent' => 'long_call',
            'side' => 'buy',
            'position_effect' => 'open',
            'order_type' => 'limit',
            'time_in_force' => 'gfd',
            'market_hours' => 'regular_hours',
            'client_ref_id' => $refId,
            'broker_order_id' => $brokerOrderId,
            'quantity' => 1,
            'limit_price' => 0.50,
            'dollar_amount' => 50.00,
            'submitted_at' => now()->subMinute(),
        ], $overrides));
    }

    private function brokerOrder(
        string $refId,
        string $brokerOrderId,
        string $state,
        string $processedQuantity = '0',
        ?string $averagePrice = null,
        string $side = 'buy',
        string $positionEffect = 'open',
    ): array {
        return [
            'id' => $brokerOrderId,
            'ref_id' => $refId,
            'chain_symbol' => 'SPY',
            'state' => $state,
            'quantity' => '1',
            'processed_quantity' => $processedQuantity,
            'average_price' => $averagePrice,
            'legs' => [['option_id' => 'opt-spy', 'side' => $side, 'position_effect' => $positionEffect]],
        ];
    }

    /** @param list<array> $orders */
    private function fakeMcp(array $orders): void
    {
        Http::fake(function (Request $request) use ($orders) {
            $payload = $request->data();
            $method = $payload['method'] ?? '';

            if ($method === 'initialize') {
                return Http::response(['jsonrpc' => '2.0', 'id' => $payload['id'], 'result' => []]);
            }

            if ($method === 'notifications/initialized') {
                return Http::response('', 202);
            }

            if ($method !== 'tools/call') {
                return Http::response(['jsonrpc' => '2.0', 'id' => $payload['id'] ?? null, 'result' => []]);
            }

            return match ($payload['params']['name'] ?? '') {
                'get_option_orders' => $this->toolResponse($payload['id'], ['data' => ['orders' => $orders]]),
                'get_option_positions' => $this->toolResponse($payload['id'], ['data' => ['positions' => []]]),
                default => $this->toolResponse($payload['id'], ['ok' => true]),
            };
        });
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
