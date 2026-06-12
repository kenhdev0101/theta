<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Robinhood\Execution\OrderRefStore;
use App\Robinhood\Mcp\RobinhoodMcpClient;
use App\Robinhood\Options\OptionExecutor;
use App\Robinhood\Options\OptionOrderIntent;
use App\Robinhood\Options\OptionRailPipeline;
use App\Robinhood\Telemetry\JsonlRecorder;
use Tests\TestCase;

/**
 * Wire-format mapping pinned to the live-measured review/place schemas:
 * legs[] with option_id/side/position_effect, string numerics, review adds
 * chain_symbol + underlying_type for the fee/collateral fetch.
 */
class OptionIntentPayloadTest extends TestCase
{
    private function executor(): OptionExecutor
    {
        $cfg = array_replace((array) config('harness'), ['oauth_token' => 'fixture-token']);

        return new OptionExecutor(
            client: RobinhoodMcpClient::fromConfig($cfg),
            rails: new OptionRailPipeline,
            refs: new OrderRefStore,
            telemetry: new JsonlRecorder(sys_get_temp_dir().'/theta-opt-payload-'.getmypid()),
            cfg: $cfg,
        );
    }

    private function intent(array $overrides = []): OptionOrderIntent
    {
        $defaults = [
            'underlyingSymbol' => 'spy',
            'optionType' => 'call',
            'expirationDate' => '2026-06-15',
            'strikePrice' => 740.0,
            'side' => 'buy',
            'positionEffect' => 'open',
            'quantity' => 2,
            'limitPrice' => 1.5,
            'optionId' => 'opt-uuid-1',
            'chainId' => 'chain-uuid-1',
            'underlyingType' => 'equity',
        ];

        return new OptionOrderIntent(...array_merge($defaults, $overrides));
    }

    public function test_long_call_bto_maps_to_measured_review_payload(): void
    {
        $payload = $this->executor()->reviewPayload($this->intent());

        $this->assertSame([
            ['option_id' => 'opt-uuid-1', 'side' => 'buy', 'position_effect' => 'open', 'ratio_quantity' => 1],
        ], $payload['legs']);
        $this->assertSame('limit', $payload['type']);
        $this->assertSame('2', $payload['quantity']);
        $this->assertSame('1.50', $payload['price']);
        $this->assertSame('gfd', $payload['time_in_force']);
        $this->assertSame('regular_hours', $payload['market_hours']);
        $this->assertSame('SPY', $payload['chain_symbol']);
        $this->assertSame('equity', $payload['underlying_type']);
    }

    public function test_long_put_bto_maps_identically_with_put_leg(): void
    {
        $payload = $this->executor()->reviewPayload($this->intent(['optionType' => 'put']));

        // The leg carries the contract via option_id; type call/put lives in
        // the resolved instrument, not the order payload.
        $this->assertSame('buy', $payload['legs'][0]['side']);
        $this->assertSame('open', $payload['legs'][0]['position_effect']);
    }

    public function test_sell_to_close_maps_position_effect_close(): void
    {
        $payload = $this->executor()->placePayload($this->intent(['side' => 'sell', 'positionEffect' => 'close']));

        $this->assertSame('sell', $payload['legs'][0]['side']);
        $this->assertSame('close', $payload['legs'][0]['position_effect']);
    }

    public function test_place_payload_omits_review_only_fields(): void
    {
        $payload = $this->executor()->placePayload($this->intent());

        $this->assertArrayNotHasKey('chain_symbol', $payload);
        $this->assertArrayNotHasKey('underlying_type', $payload);
        $this->assertArrayNotHasKey('ref_id', $payload, 'ref_id is injected by the client at place time, never pre-baked');
    }

    public function test_estimated_debit_uses_contract_multiplier(): void
    {
        $this->assertSame(300.0, $this->intent()->estimatedDebit());
        $this->assertSame(0.0, $this->intent(['side' => 'sell', 'positionEffect' => 'close'])->estimatedDebit());
        $this->assertNull($this->intent(['limitPrice' => null])->estimatedDebit());
    }
}
