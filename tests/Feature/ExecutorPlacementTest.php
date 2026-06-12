<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\HarnessOrderRef;
use App\Models\RobinhoodTradeJournal;
use App\Robinhood\Execution\EquityExecutor;
use App\Robinhood\Execution\OrderRefStore;
use App\Robinhood\Mcp\RobinhoodMcpClient;
use App\Robinhood\Rails\OrderIntent;
use App\Robinhood\Rails\RailPipeline;
use App\Robinhood\Telemetry\JsonlRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Assert;
use Tests\TestCase;

/**
 * Fixture-transport executor flow (unit-level proof only; integration proof
 * is live broker/account truth): the ref is durable BEFORE the place call,
 * and a re-run of the same logical intent cannot double-place.
 */
class ExecutorPlacementTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $placedRefIds = [];

    private function fakeMcp(): void
    {
        Http::fake(function (Request $request) {
            $payload = $request->data();
            $method = $payload['method'] ?? '';

            if ($method === 'initialize') {
                return Http::response([
                    'jsonrpc' => '2.0',
                    'id' => $payload['id'],
                    'result' => ['protocolVersion' => '2025-03-26', 'serverInfo' => ['name' => 'fixture']],
                ]);
            }

            if ($method === 'notifications/initialized') {
                return Http::response('', 202);
            }

            if ($method === 'tools/call') {
                $tool = $payload['params']['name'] ?? '';
                $arguments = $payload['params']['arguments'] ?? [];

                if ($tool === 'place_equity_order') {
                    $refId = (string) ($arguments['ref_id'] ?? '');
                    $this->placedRefIds[] = $refId;

                    Assert::assertTrue(
                        HarnessOrderRef::query()->where('client_ref_id', $refId)->exists(),
                        'ref_id must be persisted BEFORE the broker place call',
                    );

                    return $this->toolResponse($payload['id'], [
                        'order_id' => 'broker-order-1',
                        'state' => 'queued',
                        'ref_id' => $refId,
                    ]);
                }

                return $this->toolResponse($payload['id'], ['ok' => true]);
            }

            return Http::response(['jsonrpc' => '2.0', 'id' => $payload['id'] ?? null, 'result' => []]);
        });
    }

    private function toolResponse(mixed $id, array $body)
    {
        return Http::response([
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [
                'isError' => false,
                'content' => [['type' => 'text', 'text' => json_encode($body)]],
            ],
        ]);
    }

    private function executor(): EquityExecutor
    {
        $cfg = array_replace((array) config('harness'), [
            'oauth_token' => 'fixture-token',
            'kill_switch' => false,
        ]);

        return new EquityExecutor(
            client: RobinhoodMcpClient::fromConfig($cfg),
            rails: new RailPipeline,
            refs: new OrderRefStore,
            telemetry: new JsonlRecorder(sys_get_temp_dir().'/theta-test-'.getmypid()),
            cfg: $cfg,
        );
    }

    private function greenContext(): array
    {
        return [
            'session_open' => true,
            'tradability' => 'tradable',
            'settled_cash' => 100.0,
            'spent_today_debit' => 0.0,
            'symbol_open_debit' => [],
            'open_orders' => [],
            'open_positions' => [],
            'review' => ['ok' => true, 'alerts' => []],
        ];
    }

    public function test_allowed_intent_places_with_persisted_ref_and_journals_broker_truth(): void
    {
        $this->fakeMcp();

        $intent = new OrderIntent(symbol: 'XYZ', side: 'buy', orderType: 'limit', dollarAmount: 5.0, source: 'fixture-test');
        $result = $this->executor()->execute($intent, $this->greenContext());

        $this->assertSame('LIVE_POSTED_NOT_FILLED', $result['classification']);
        $this->assertCount(1, $this->placedRefIds);

        $ref = HarnessOrderRef::sole();
        $this->assertSame('placed', $ref->status);
        $this->assertSame('broker-order-1', $ref->broker_order_id);
        $this->assertSame($ref->client_ref_id, $this->placedRefIds[0]);

        $journal = RobinhoodTradeJournal::sole();
        $this->assertSame('submitted', $journal->status);
        $this->assertSame($ref->client_ref_id, $journal->client_ref_id);
        $this->assertFalse((bool) ($journal->broker_payload['dry_run'] ?? true));
    }

    public function test_rerun_of_placed_intent_is_blocked_not_double_placed(): void
    {
        $this->fakeMcp();

        $intent = new OrderIntent(symbol: 'XYZ', side: 'buy', orderType: 'limit', dollarAmount: 5.0, source: 'fixture-test');
        $executor = $this->executor();

        $first = $executor->execute($intent, $this->greenContext());
        $second = $executor->execute($intent, $this->greenContext());

        $this->assertSame('LIVE_POSTED_NOT_FILLED', $first['classification']);
        $this->assertSame('LIVE_RISK_ABORTED', $second['classification']);
        $this->assertContains('idempotency:ref_already_placed', $second['verdict']->blockReasons());
        $this->assertCount(1, $this->placedRefIds, 'a re-run must never reach the broker a second time');
        $this->assertSame(1, HarnessOrderRef::count());
    }

    public function test_failed_attempt_blocks_rerun_until_reconciled(): void
    {
        $this->fakeMcp();

        $intent = new OrderIntent(symbol: 'XYZ', side: 'buy', orderType: 'limit', dollarAmount: 5.0, source: 'fixture-test');

        $store = new OrderRefStore;
        $store->markFailed($store->reserve($intent), 'transport timeout mid-place');

        $result = $this->executor()->execute($intent, $this->greenContext());

        $this->assertSame('LIVE_RISK_ABORTED', $result['classification']);
        $this->assertContains('idempotency:ref_attempted_requires_reconcile', $result['verdict']->blockReasons());
        $this->assertCount(0, $this->placedRefIds, 'an ambiguous failed attempt must reconcile before any resubmission');
        $this->assertSame(1, HarnessOrderRef::count());
    }

    public function test_blocked_intent_never_calls_place_and_journals_risk_abort(): void
    {
        $this->fakeMcp();

        $intent = new OrderIntent(symbol: 'XYZ', side: 'buy', orderType: 'limit', dollarAmount: 5.0, source: 'fixture-test');
        $context = array_merge($this->greenContext(), ['settled_cash' => null]);

        $result = $this->executor()->execute($intent, $context);

        $this->assertSame('LIVE_RISK_ABORTED', $result['classification']);
        $this->assertContains('settled_cash:settled_cash_unverified', $result['verdict']->blockReasons());
        $this->assertCount(0, $this->placedRefIds);
        $this->assertSame('risk_aborted', RobinhoodTradeJournal::sole()->status);
    }
}
