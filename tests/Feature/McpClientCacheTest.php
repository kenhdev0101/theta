<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Robinhood\Mcp\RobinhoodMcpClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class McpClientCacheTest extends TestCase
{
    public function test_stable_capability_reads_are_memoized_per_client_instance(): void
    {
        $toolsListCalls = 0;
        $getAccountsCalls = 0;

        Http::fake(function (Request $request) use (&$toolsListCalls, &$getAccountsCalls) {
            $payload = $request->data();
            $method = $payload['method'] ?? '';

            if ($method === 'initialize') {
                return Http::response(['jsonrpc' => '2.0', 'id' => $payload['id'], 'result' => []]);
            }
            if ($method === 'notifications/initialized') {
                return Http::response('', 202);
            }
            if ($method === 'tools/list') {
                $toolsListCalls++;

                return Http::response([
                    'jsonrpc' => '2.0',
                    'id' => $payload['id'],
                    'result' => ['tools' => [['name' => 'get_option_quotes']]],
                ]);
            }
            if ($method === 'tools/call' && ($payload['params']['name'] ?? '') === 'get_accounts') {
                $getAccountsCalls++;

                return $this->toolResponse($payload['id'], [
                    'data' => [
                        'accounts' => [[
                            'account_number' => config('harness.agentic_account'),
                            'type' => 'cash',
                            'option_level' => 'option_level_2',
                            'agentic_allowed' => true,
                        ]],
                    ],
                ]);
            }

            return Http::response(['jsonrpc' => '2.0', 'id' => $payload['id'] ?? null, 'result' => []]);
        });

        $cfg = array_replace((array) config('harness'), ['oauth_token' => 'fixture-token']);
        $client = RobinhoodMcpClient::fromConfig($cfg);

        $this->assertSame('get_option_quotes', $client->listTools()[0]['name']);
        $this->assertSame('get_option_quotes', $client->listTools()[0]['name']);
        $this->assertSame('option_level_2', $client->getAccountCapability()['option_level']);
        $this->assertSame('option_level_2', $client->getAccountCapability()['option_level']);

        $this->assertSame(1, $toolsListCalls, 'tools/list should be read once per client process');
        $this->assertSame(1, $getAccountsCalls, 'pinned account capability should be read once per client process');
    }

    public function test_option_metadata_reads_are_cached_but_quotes_stay_live(): void
    {
        Cache::flush();
        $chainCalls = 0;
        $instrumentCalls = 0;
        $quoteCalls = 0;

        Http::fake(function (Request $request) use (&$chainCalls, &$instrumentCalls, &$quoteCalls) {
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
                'get_option_chains' => $this->toolResponse($payload['id'], [
                    'data' => ['chains' => [['id' => 'chain-spy', 'expiration_dates' => ['2026-06-15']]]],
                    'call' => ++$chainCalls,
                ]),
                'get_option_instruments' => $this->toolResponse($payload['id'], [
                    'data' => ['instruments' => [['id' => 'opt-spy', 'chain_symbol' => 'SPY']]],
                    'call' => ++$instrumentCalls,
                ]),
                'get_option_quotes' => $this->toolResponse($payload['id'], [
                    'data' => ['results' => [['quote' => ['instrument_id' => 'opt-spy', 'ask_price' => (string) ++$quoteCalls]]]],
                ]),
                default => Http::response(['jsonrpc' => '2.0', 'id' => $payload['id'] ?? null, 'result' => []]),
            };
        });

        $cfg = array_replace_recursive((array) config('harness'), [
            'oauth_token' => 'fixture-token',
            'options' => ['metadata_cache_seconds' => 900],
        ]);
        $first = RobinhoodMcpClient::fromConfig($cfg);
        $second = RobinhoodMcpClient::fromConfig($cfg);

        $this->assertSame(1, $first->getOptionChains('SPY')['call']);
        $this->assertSame(1, $second->getOptionChains('SPY')['call']);
        $this->assertSame(1, $first->getOptionInstruments(['chain_symbol' => 'SPY'])['call']);
        $this->assertSame(1, $second->getOptionInstruments(['chain_symbol' => 'SPY'])['call']);

        $this->assertSame('1', data_get($first->getOptionQuotes(['opt-spy']), 'data.results.0.quote.ask_price'));
        $this->assertSame('2', data_get($second->getOptionQuotes(['opt-spy']), 'data.results.0.quote.ask_price'));
        $this->assertSame(1, $chainCalls);
        $this->assertSame(1, $instrumentCalls);
        $this->assertSame(2, $quoteCalls);
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
