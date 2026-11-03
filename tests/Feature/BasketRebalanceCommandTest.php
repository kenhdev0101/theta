<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\HarnessOrderRef;
use App\Models\RebalanceEvent;
use App\Models\RobinhoodTradeJournal;
use App\Robinhood\Convex\ConvexLoopLock;
use App\Robinhood\Mcp\BrokerCreditExhausted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BasketRebalanceCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $weights;

    private array $placed = [];

    private float $cash = 100;

    private ?float $buyingPower = null;

    private ?float $unleveraged = null;

    private bool $fills = false;

    private ?string $placeError = null;

    private array $positions = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-03 20:00:00', 'UTC'));
        $this->weights = tempnam(sys_get_temp_dir(), 'basket_test_');
        file_put_contents($this->weights, "ticker,weight_pct\nAAA,50\nBBB,30\nCCC,20\n");
        config()->set('harness.basket', ['enabled' => true, 'armed' => true, 'weights_path' => $this->weights, 'max_open_positions' => 3, 'max_orders_per_tick' => 5]);
        config()->set('harness.allocation', ['per_play_max_debit' => 50, 'per_day_max_debit' => 200, 'per_symbol_max_debit' => 50, 'max_open_positions' => 8]);
        config()->set('harness.kill_switch', false);
        config()->set('harness.oauth_token', 'fixture-token');
        config()->set('harness.broker_credits.enabled', false);
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            $payload = $request->data();
            if (($payload['method'] ?? '') === 'notifications/initialized') {
                return Http::response('', 202);
            }
            if (($payload['method'] ?? '') !== 'tools/call') {
                return Http::response(['jsonrpc' => '2.0', 'id' => $payload['id'] ?? null, 'result' => ['protocolVersion' => '2025-03-26', 'serverInfo' => ['name' => 'fixture']]]);
            }
            $tool = $payload['params']['name'];
            $args = $payload['params']['arguments'];
            $body = match ($tool) {
                'get_portfolio' => ['settled_cash' => $this->cash, 'buying_power' => ['buying_power' => $this->buyingPower ?? $this->cash, 'unleveraged_buying_power' => $this->unleveraged ?? $this->buyingPower ?? $this->cash]],
                'get_equity_positions' => ['positions' => $this->positions],
                'get_equity_orders' => ['orders' => array_map(fn ($row) => ['id' => $row['order_id'], 'ref_id' => $row['ref_id'], 'symbol' => $row['symbol'], 'side' => 'buy', 'state' => $this->fills ? 'filled' : 'queued', 'cumulative_quantity' => $this->fills ? (float) $row['dollar_amount'] / 10 : 0, 'average_price' => $this->fills ? 10 : null], $this->placed)],
                'get_option_orders' => ['orders' => []],
                'get_equity_tradability' => ['results' => array_map(fn ($symbol) => ['symbol' => $symbol, 'state' => 'active', 'tradeable' => true, 'fractional_tradability' => 'tradable'], $args['symbols'])],
                'get_equity_quotes' => ['results' => []],
                default => ['ok' => true, 'alerts' => []],
            };
            if ($tool === 'place_equity_order') {
                if ($this->placeError === 'credit') {
                    throw new BrokerCreditExhausted('test deferral');
                }
                if ($this->placeError === 'unknown') {
                    throw new \RuntimeException('unknown transport outcome');
                }
                $args['order_id'] = 'broker-'.count($this->placed);
                $this->placed[] = $args;
                $body = ['order_id' => $args['order_id'], 'state' => 'queued'];
            }

            return Http::response(['jsonrpc' => '2.0', 'id' => $payload['id'], 'result' => ['isError' => false, 'content' => [['type' => 'text', 'text' => json_encode(['data' => $body])]]]]);
        });
    }

    protected function tearDown(): void
    {
        @unlink($this->weights);
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function preview(): RebalanceEvent
    {
        $this->artisan('theta:basket-rebalance', ['--dry-run' => true])->assertSuccessful();
        $event = RebalanceEvent::latest('id')->firstOrFail();
        config()->set('harness.basket.active_event_id', $event->id);

        return $event;
    }

    public function test_preview_writes_ledger_without_reserving_or_placing(): void
    {
        $event = $this->preview();
        $this->assertEquals(100, $event->gross_buys_usd);
        $this->assertCount(3, $event->legs);
        $this->assertTrue($event->dry_run);
        $this->assertSame('live', $event->notes['truth_source']);
        $this->assertSame(0, HarnessOrderRef::count());
        $this->assertSame(0, RobinhoodTradeJournal::count());
        $this->assertCount(0, $this->placed);
    }

    public function test_dry_run_dominates_arm(): void
    {
        $this->preview();
        $this->artisan('theta:basket-rebalance', ['--arm' => true, '--dry-run' => true])->assertSuccessful();
        $this->assertCount(0, $this->placed);
        $this->assertSame(0, HarnessOrderRef::count());
    }

    public function test_fixture_can_never_arm(): void
    {
        $this->artisan('theta:basket-rebalance', ['--arm' => true, '--fixture' => '/does/not/matter'])->assertFailed();
        $this->assertCount(0, $this->placed);
        Http::assertNothingSent();
    }

    public function test_no_selected_event_or_changed_weights_refuses_arm(): void
    {
        $this->artisan('theta:basket-rebalance', ['--arm' => true])->assertFailed();
        $this->preview();
        file_put_contents($this->weights, "ticker,weight_pct\nAAA,100\n");
        $this->artisan('theta:basket-rebalance', ['--arm' => true])->assertFailed();
        $this->assertCount(0, $this->placed);
    }

    public function test_live_batch_preserves_refs_and_only_records_actual_fills(): void
    {
        $event = $this->preview();
        $this->artisan('theta:basket-rebalance', ['--arm' => true])->assertSuccessful();
        $this->assertCount(3, $this->placed);
        $this->assertEquals(0, $event->refresh()->cash_deployed, 'posted dollars are not filled dollars');
        $this->artisan('theta:basket-rebalance', ['--arm' => true])->assertSuccessful();
        $this->assertCount(3, $this->placed, 'queued orders reserve the account; no duplicate orders');
        $this->fills = true;
        $this->artisan('theta:basket-rebalance', ['--arm' => true])->assertSuccessful();
        $this->assertCount(3, $this->placed);
        $this->assertSame('reconciled', $event->refresh()->status);
        $this->assertEquals(100, $event->cash_deployed);
        $this->assertSame(3, RobinhoodTradeJournal::where('status', 'filled')->where('strategy_label', 'theta_basket_etf')->count());
        $this->assertSame(1, RebalanceEvent::count(), 'ticks resume the saved plan');
    }

    public function test_position_count_advances_with_each_submitted_name(): void
    {
        $this->preview();
        config()->set('harness.basket.max_open_positions', 2);
        $this->artisan('theta:basket-rebalance', ['--arm' => true])->assertSuccessful();
        $this->assertCount(2, $this->placed);
        $this->assertContains('allocation:max_open_positions_reached', RebalanceEvent::sole()->notes['blocked']);
    }

    public function test_decreased_cash_and_daily_caps_block_before_placement(): void
    {
        $this->preview();
        $this->cash = 40;
        $this->artisan('theta:basket-rebalance', ['--arm' => true])->assertSuccessful();
        $this->assertCount(0, $this->placed);
        $this->cash = 100;
        config()->set('harness.allocation.per_day_max_debit', 40);
        $this->artisan('theta:basket-rebalance', ['--arm' => true])->assertSuccessful();
        $this->assertCount(0, $this->placed);
    }

    public function test_credit_deferral_keeps_reserved_ref_retryable(): void
    {
        $this->preview();
        $this->placeError = 'credit';
        $this->artisan('theta:basket-rebalance', ['--arm' => true])->assertSuccessful();
        $ref = HarnessOrderRef::sole();
        $this->assertSame('reserved', $ref->status);
        $this->placeError = null;
        $this->artisan('theta:basket-rebalance', ['--arm' => true])->assertSuccessful();
        $this->assertCount(3, $this->placed);
        $this->assertSame($ref->client_ref_id, $this->placed[0]['ref_id']);
    }

    public function test_unknown_placement_never_retries_with_a_new_reference(): void
    {
        $this->preview();
        $this->placeError = 'unknown';
        $this->artisan('theta:basket-rebalance', ['--arm' => true])->assertSuccessful();
        $this->assertSame('failed', HarnessOrderRef::sole()->status);
        $this->placeError = null;
        $this->artisan('theta:basket-rebalance', ['--arm' => true])->assertSuccessful();
        $this->assertCount(0, $this->placed, 'an unresolved outcome freezes the batch');
    }

    public function test_closed_session_kill_switch_and_expired_event_never_place(): void
    {
        $this->preview();
        config()->set('harness.kill_switch', true);
        $this->artisan('theta:basket-rebalance', ['--arm' => true])->assertSuccessful();
        config()->set('harness.kill_switch', false);
        Carbon::setTestNow(Carbon::parse('2026-11-03 21:01:00', 'UTC'));
        $this->artisan('theta:basket-rebalance', ['--arm' => true])->assertSuccessful();
        Carbon::setTestNow(Carbon::parse('2026-11-04 15:00:00', 'UTC'));
        $this->artisan('theta:basket-rebalance', ['--arm' => true])->assertSuccessful();
        $this->assertCount(0, $this->placed);
    }

    public function test_authorized_buying_power_includes_pending_deposit_without_relabeling_settled_cash(): void
    {
        $this->cash = 117.08;
        $this->buyingPower = 317.08;
        config()->set('harness.basket.funding_source', 'broker_buying_power');
        $event = $this->preview();
        // Three test names each hit the unchanged $50 order cap.
        $this->assertEquals(150, $event->gross_buys_usd);
        $this->assertEquals(117.08, $event->settled_cash_before);
        $this->assertEquals(317.08, $event->notes['broker_buying_power_before']);
        $this->artisan('theta:basket-rebalance', ['--arm' => true])->assertSuccessful();
        $this->assertCount(3, $this->placed);
        $this->assertEquals(150, array_sum(array_column($this->placed, 'dollar_amount')));
    }

    public function test_buying_power_change_requires_a_new_preview_and_current_power_is_rechecked(): void
    {
        $this->preview();
        config()->set('harness.basket.funding_source', 'broker_buying_power');
        $this->artisan('theta:basket-rebalance', ['--arm' => true])->assertFailed();
        $this->preview();
        $this->buyingPower = 10;
        $this->artisan('theta:basket-rebalance', ['--arm' => true])->assertSuccessful();
        $this->assertCount(0, $this->placed);
    }

    public function test_default_cash_mode_does_not_adopt_buying_power(): void
    {
        $this->buyingPower = 317.08;
        $event = $this->preview();
        $this->assertEquals(100, $event->gross_buys_usd);
        $this->assertSame('settled_cash', $event->notes['funding_source']);
    }

    public function test_shared_execution_lock_defers_without_broker_reads(): void
    {
        $release = ConvexLoopLock::acquire();
        try {
            $this->artisan('theta:basket-rebalance', ['--dry-run' => true])->assertSuccessful();
            Http::assertNothingSent();
        } finally {
            $release();
        }
    }

    public function test_unknown_held_market_value_blocks_instead_of_assuming_zero(): void
    {
        $this->positions = [['symbol' => 'AAA', 'quantity' => 1, 'average_price' => 10]];
        $this->artisan('theta:basket-rebalance', ['--dry-run' => true])->assertFailed();
        $this->assertSame(0, RebalanceEvent::count());
    }
}
