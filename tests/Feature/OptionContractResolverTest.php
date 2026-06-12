<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Robinhood\Mcp\RobinhoodMcpClient;
use App\Robinhood\Options\OptionContractResolver;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Resolver pinned to the live-measured instrument/quote response shapes.
 */
class OptionContractResolverTest extends TestCase
{
    /** @var list<array> */
    private array $instruments = [];

    private ?array $quote = null;

    /** @var list<array> */
    private array $instrumentRequests = [];

    private function liveShapeInstrument(array $overrides = []): array
    {
        return array_merge([
            'id' => 'e38a8aed-dccc-480b-84aa-f746e2eba554',
            'chain_id' => 'c277b118-58d9-4060-8dc5-a3b5898955cb',
            'chain_symbol' => 'SPY',
            'underlying_type' => 'equity',
            'expiration_date' => '2026-06-15',
            'strike_price' => '740.0000',
            'type' => 'call',
            'state' => 'active',
            'tradability' => 'tradable',
        ], $overrides);
    }

    private function liveShapeQuote(array $overrides = []): array
    {
        return array_merge([
            'instrument_id' => 'e38a8aed-dccc-480b-84aa-f746e2eba554',
            'ask_price' => '4.660000',
            'bid_price' => '4.610000',
            'mark_price' => '4.635000',
            'implied_volatility' => '0.197068',
            'delta' => '0.444580',
            'open_interest' => 2852,
            'volume' => 17242,
            'updated_at' => now()->subSeconds(2)->toIso8601ZuluString('microsecond'),
        ], $overrides);
    }

    private function fakeMcp(): void
    {
        Http::fake(function (Request $request) {
            $payload = $request->data();
            $method = $payload['method'] ?? '';

            if ($method === 'initialize') {
                return Http::response(['jsonrpc' => '2.0', 'id' => $payload['id'], 'result' => []]);
            }
            if ($method === 'notifications/initialized') {
                return Http::response('', 202);
            }
            if ($method === 'tools/call') {
                $tool = $payload['params']['name'] ?? '';
                $arguments = $payload['params']['arguments'] ?? [];

                if ($tool === 'get_option_instruments') {
                    $this->instrumentRequests[] = $arguments;

                    return $this->toolResponse($payload['id'], ['data' => ['instruments' => $this->instruments]]);
                }
                if ($tool === 'get_option_quotes') {
                    return $this->toolResponse($payload['id'], ['data' => ['results' => $this->quote === null ? [] : [['quote' => $this->quote]]]]);
                }
            }

            return Http::response(['jsonrpc' => '2.0', 'id' => $payload['id'] ?? null, 'result' => []]);
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

    private function resolver(): OptionContractResolver
    {
        $cfg = array_replace((array) config('harness'), ['oauth_token' => 'fixture-token']);

        return new OptionContractResolver(RobinhoodMcpClient::fromConfig($cfg), $cfg);
    }

    private function resolve(): array
    {
        return $this->resolver()->resolveExact('SPY', 'call', '2026-06-15', 740.0);
    }

    public function test_exact_spec_resolves_with_quote_and_measured_request_format(): void
    {
        $this->fakeMcp();
        $this->instruments = [$this->liveShapeInstrument()];
        $this->quote = $this->liveShapeQuote();

        $result = $this->resolve();

        $this->assertSame(OptionContractResolver::VERDICT_RESOLVED, $result['verdict'], implode('; ', $result['blockers']));
        $this->assertSame('e38a8aed-dccc-480b-84aa-f746e2eba554', $result['contract']['option_id']);
        $this->assertSame('c277b118-58d9-4060-8dc5-a3b5898955cb', $result['contract']['chain_id']);
        $this->assertSame(4.61, $result['quote']['bid']);
        $this->assertSame(4.66, $result['quote']['ask']);
        $this->assertSame(4.635, $result['quote']['mid']);
        $this->assertFalse($result['quote']['stale']);

        // Request format pinned to the measured schema: 4dp strike string.
        $request = $this->instrumentRequests[0];
        $this->assertSame('SPY', $request['chain_symbol']);
        $this->assertSame('2026-06-15', $request['expiration_dates']);
        $this->assertSame('740.0000', $request['strike_price']);
        $this->assertSame('call', $request['type']);
        $this->assertSame('active', $request['state']);
    }

    public function test_no_matching_contract_blocks(): void
    {
        $this->fakeMcp();
        $this->instruments = [];

        $result = $this->resolve();

        $this->assertSame(OptionContractResolver::VERDICT_BLOCKED, $result['verdict']);
        $this->assertContains('no_matching_contract', $result['blockers']);
        $this->assertNull($result['contract']);
    }

    public function test_ambiguous_match_blocks_rather_than_guessing(): void
    {
        $this->fakeMcp();
        $this->instruments = [$this->liveShapeInstrument(), $this->liveShapeInstrument(['id' => 'other-uuid'])];

        $result = $this->resolve();

        $this->assertSame(OptionContractResolver::VERDICT_BLOCKED, $result['verdict']);
        $this->assertStringContainsString('ambiguous_match', $result['blockers'][0]);
    }

    public function test_untradable_contract_carries_blocker(): void
    {
        $this->fakeMcp();
        $this->instruments = [$this->liveShapeInstrument(['tradability' => 'untradable'])];
        $this->quote = $this->liveShapeQuote();

        $result = $this->resolve();

        $this->assertSame(OptionContractResolver::VERDICT_BLOCKED, $result['verdict']);
        $this->assertContains('contract_untradable', $result['blockers']);
        $this->assertNotNull($result['contract'], 'contract details still surface for the readback');
    }

    public function test_stale_quote_carries_blocker(): void
    {
        $this->fakeMcp();
        $this->instruments = [$this->liveShapeInstrument()];
        $this->quote = $this->liveShapeQuote(['updated_at' => now()->subHours(14)->toIso8601ZuluString('microsecond')]);

        $result = $this->resolve();

        $this->assertContains('quote_stale', $result['blockers']);
        $this->assertTrue($result['quote']['stale']);
    }

    public function test_wide_spread_and_high_ask_carry_blockers(): void
    {
        $this->fakeMcp();
        $this->instruments = [$this->liveShapeInstrument()];
        $this->quote = $this->liveShapeQuote(['bid_price' => '1.000000', 'ask_price' => '6.000000']);

        $result = $this->resolve();

        $this->assertContains('spread_above_max', $result['blockers']);
        $this->assertContains('ask_above_max', $result['blockers']);
    }

    public function test_zero_bid_is_dropped_per_broker_guide(): void
    {
        $this->fakeMcp();
        $this->instruments = [$this->liveShapeInstrument()];
        $this->quote = $this->liveShapeQuote(['bid_price' => '0.000000']);

        $result = $this->resolve();

        $this->assertNull($result['quote']['bid']);
        $this->assertContains('bid_or_ask_missing', $result['blockers']);
    }

    public function test_missing_quote_blocks(): void
    {
        $this->fakeMcp();
        $this->instruments = [$this->liveShapeInstrument()];
        $this->quote = null;

        $result = $this->resolve();

        $this->assertContains('quote_unavailable', $result['blockers']);
    }

    public function test_delta_and_moneyness_hooks_are_blocked_not_model_routed(): void
    {
        $this->fakeMcp();

        $delta = $this->resolver()->resolveByDeltaTarget('SPY', 'call', '2026-06-15', 0.45);
        $this->assertSame(OptionContractResolver::VERDICT_BLOCKED, $delta['verdict']);
        $this->assertStringContainsString('selection_rule_not_implemented', $delta['blockers'][0]);

        $moneyness = $this->resolver()->resolveByMoneyness('SPY', 'call', '2026-06-15', 1.0);
        $this->assertSame(OptionContractResolver::VERDICT_BLOCKED, $moneyness['verdict']);
    }
}
