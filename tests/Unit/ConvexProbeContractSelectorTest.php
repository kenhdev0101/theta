<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Robinhood\Convex\ConvexProbeContractSelector;
use App\Robinhood\Mcp\RobinhoodMcpClient;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class ConvexProbeContractSelectorTest extends TestCase
{
    public function test_fresh_near_delta_contract_is_selected_by_broker_id_with_one_contract_cash_ceiling(): void
    {
        $this->travelTo(Carbon::parse('2026-10-07T18:00:00Z'));
        $client = $this->createMock(RobinhoodMcpClient::class);
        $client->method('getOptionChains')->willReturn(['data' => ['chains' => [[
            'id' => 'chain-iwm', 'expiration_dates' => ['2026-10-09', '2026-10-16'],
        ]]]]);
        $client->method('getEquityQuotes')->willReturn(['data' => ['results' => [[
            'quote' => ['symbol' => 'IWM', 'last_trade_price' => '280.00'],
        ]]]]);
        $client->method('getOptionInstruments')->willReturn(['data' => ['instruments' => [
            ['id' => 'fresh-cheap', 'chain_id' => 'chain-iwm', 'strike_price' => '290.00', 'tradability' => 'tradable'],
            ['id' => 'fresh-expensive', 'chain_id' => 'chain-iwm', 'strike_price' => '285.00', 'tradability' => 'tradable'],
            ['id' => 'wrong-delta', 'chain_id' => 'chain-iwm', 'strike_price' => '300.00', 'tradability' => 'tradable'],
        ]]]);
        $client->method('getOptionQuotes')->willReturn(['data' => ['results' => [
            ['quote' => ['instrument_id' => 'fresh-cheap', 'bid_price' => '0.75', 'ask_price' => '0.85', 'delta' => '0.21', 'updated_at' => '2026-10-07T17:59:58Z']],
            ['quote' => ['instrument_id' => 'fresh-expensive', 'bid_price' => '1.80', 'ask_price' => '2.00', 'delta' => '0.20', 'updated_at' => '2026-10-07T17:59:58Z']],
            ['quote' => ['instrument_id' => 'wrong-delta', 'bid_price' => '0.30', 'ask_price' => '0.35', 'delta' => '0.08', 'updated_at' => '2026-10-07T17:59:58Z']],
        ]]]);

        $result = (new ConvexProbeContractSelector($client, ['options' => ['min_quote_fresh_seconds' => 10, 'max_spread_bps' => 1500]]))
            ->select('IWM', 'call', 7, 21, 0.20, 100.0);

        $this->assertSame([], $result['blockers']);
        $this->assertSame('fresh-cheap', $result['contract']['option_id']);
        $this->assertSame(0.85, $result['quote']['ask']);

        $excluded = (new ConvexProbeContractSelector($client, ['options' => ['min_quote_fresh_seconds' => 10, 'max_spread_bps' => 1500]]))
            ->select('IWM', 'call', 7, 21, 0.20, 250.0, ['fresh-cheap']);
        $this->assertSame('fresh-expensive', $excluded['contract']['option_id']);
    }
}
