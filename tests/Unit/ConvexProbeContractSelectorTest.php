<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Robinhood\Convex\ConvexProbeContractSelector;
use App\Robinhood\Mcp\RobinhoodMcpClient;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class ConvexProbeContractSelectorTest extends TestCase
{
    public function test_selection_stamps_prices_rejections_and_partial_reads_without_added_requests(): void
    {
        $this->travelTo(Carbon::parse('2026-10-22T18:00:00Z'));
        $dir = sys_get_temp_dir().'/convex-selection-evidence-'.uniqid();
        $client = $this->createMock(RobinhoodMcpClient::class);
        $client->expects($this->once())->method('getOptionChains')->willReturn(['data' => ['chains' => [[
            'id' => 'chain-spy', 'expiration_dates' => ['2026-12-18', '2026-12-25'],
        ]]]]);
        $client->expects($this->once())->method('getEquityQuotes')->willReturn(['data' => ['results' => [[
            'quote' => ['last_trade_price' => '762.90'],
        ]]]]);
        $client->expects($this->exactly(2))->method('getOptionInstruments')->willReturn(['data' => ['instruments' => [[
            'id' => 'option-spy-800', 'strike_price' => '800.00', 'tradability' => 'tradable',
        ]]]]);
        $calls = 0;
        $client->expects($this->exactly(2))->method('getOptionQuotes')->willReturnCallback(function () use (&$calls) {
            if (++$calls === 2) {
                throw new \RuntimeException('simulated interruption with secret-error-text');
            }

            return ['data' => ['results' => [['quote' => [
                'instrument_id' => 'option-spy-800', 'bid_price' => '4.15', 'ask_price' => '4.18',
                'delta' => '.2096', 'updated_at' => '2026-10-22T17:59:58Z', 'token' => 'secret-token',
            ]]]]];
        });
        try {
            $selector = new ConvexProbeContractSelector($client, ['convex' => ['evidence' => ['dir' => $dir]]]);
            try {
                $selector->select('SPY', 'call', 57, 70, .20, 50, [], 30, 1.3, ['probe_id' => 698]);
                $this->fail('original broker exception must propagate');
            } catch (\RuntimeException $e) {
                $this->assertSame('simulated interruption with secret-error-text', $e->getMessage());
            }
            $raw = file_get_contents($dir.'/convex-evidence-2026-10-22.jsonl');
            $stamp = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame('read_interrupted', $stamp['outcome']);
            $this->assertSame(698, $stamp['probe_id']);
            $this->assertSame('4.18', $stamp['samples'][0]['ask']);
            $this->assertSame(4.17, $stamp['samples'][0]['entry_limit']);
            $this->assertContains('premium_above_cash_ceiling', $stamp['samples'][0]['blockers']);
            $this->assertStringNotContainsString('secret', $raw);
        } finally {
            foreach (glob($dir.'/*') ?: [] as $path) {
                unlink($path);
            }
            @rmdir($dir);
        }
    }

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

    public function test_no_contract_names_spread_and_cash_filters_without_relaxing_them(): void
    {
        $this->travelTo(Carbon::parse('2026-10-08T18:11:00Z'));
        $client = $this->createMock(RobinhoodMcpClient::class);
        $client->method('getOptionChains')->willReturn(['data' => ['chains' => [[
            'id' => 'chain-xlf', 'expiration_dates' => ['2026-12-18'],
        ]]]]);
        $client->method('getEquityQuotes')->willReturn(['data' => ['results' => [[
            'quote' => ['symbol' => 'XLF', 'last_trade_price' => '54.20'],
        ]]]]);
        $client->method('getOptionInstruments')->willReturn(['data' => ['instruments' => [
            ['id' => 'wide-58', 'chain_id' => 'chain-xlf', 'strike_price' => '58.00', 'tradability' => 'tradable'],
            ['id' => 'dear-59', 'chain_id' => 'chain-xlf', 'strike_price' => '59.00', 'tradability' => 'tradable'],
        ]]]);
        $client->method('getOptionQuotes')->willReturn(['data' => ['results' => [
            ['quote' => ['instrument_id' => 'wide-58', 'bid_price' => '0.35', 'ask_price' => '0.48',
                'delta' => '0.2045', 'updated_at' => '2026-10-08T18:10:58Z']],
            ['quote' => ['instrument_id' => 'dear-59', 'bid_price' => '1.95', 'ask_price' => '2.00',
                'delta' => '0.21', 'updated_at' => '2026-10-08T18:10:58Z']],
        ]]]);

        $result = (new ConvexProbeContractSelector($client, ['options' => [
            'min_quote_fresh_seconds' => 60, 'max_spread_bps' => 500,
        ]]))->select('XLF', 'call', 71, 71, 0.205, 50.0);

        $this->assertSame([
            'no_fresh_tradable_delta_contract_within_premium_cap',
            'spread_above_rail:1',
            'premium_above_cash_ceiling:1',
        ], $result['blockers']);
        $this->assertArrayNotHasKey('contract', $result);

        $bounded = (new ConvexProbeContractSelector($client, [
            'convex' => ['entry_repeg_cap_frac' => 0.5],
            'options' => ['min_quote_fresh_seconds' => 60, 'max_spread_bps' => 500],
        ]))->select('XLF', 'call', 71, 71, 0.205, 50.0, [], 30.0, 1.30);
        $this->assertSame([
            'no_fresh_tradable_delta_contract_within_premium_cap',
            'premium_above_cash_ceiling:1',
            'convex_round_trip_cost_exceeds_budget:1',
        ], $bounded['blockers']);
        $this->assertArrayNotHasKey('contract', $bounded);
    }
}
