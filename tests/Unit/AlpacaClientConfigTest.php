<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\MarketData\AlpacaMarketDataClient;
use PHPUnit\Framework\TestCase;

class AlpacaClientConfigTest extends TestCase
{
    private const SECRET = 'super-secret-data-key-value';

    private function harnessCfg(): array
    {
        return [
            'mcp_endpoint' => 'https://agent.robinhood.com/mcp/trading',
            'alpaca' => [
                'key_id' => 'PKTESTKEYID',
                'secret_key' => self::SECRET,
                'feed' => 'sip',
                'base_url' => 'https://data.alpaca.markets',
                'stream_url' => 'wss://stream.data.alpaca.markets/v2/sip',
                'staleness_seconds' => 7,
            ],
        ];
    }

    public function test_from_config_accepts_full_harness_assembly(): void
    {
        $client = AlpacaMarketDataClient::fromConfig($this->harnessCfg());

        $context = $client->telemetryContext();
        $this->assertSame('sip', $context['feed']);
        $this->assertSame('https://data.alpaca.markets', $context['base_url']);
        $this->assertSame(7, $context['staleness_seconds']);
    }

    public function test_from_config_accepts_alpaca_block_directly(): void
    {
        $client = AlpacaMarketDataClient::fromConfig($this->harnessCfg()['alpaca']);

        $this->assertSame('sip', $client->telemetryContext()['feed']);
    }

    public function test_telemetry_context_never_exposes_the_secret(): void
    {
        $client = AlpacaMarketDataClient::fromConfig($this->harnessCfg());

        $serialized = json_encode($client->telemetryContext());

        $this->assertStringNotContainsString(self::SECRET, $serialized);
        $this->assertStringNotContainsString('secret', strtolower((string) $serialized));
    }

    public function test_telemetry_context_masks_the_key_id(): void
    {
        $context = AlpacaMarketDataClient::fromConfig($this->harnessCfg())->telemetryContext();

        $this->assertSame('PKTE…', $context['key_id_masked']);
        $this->assertStringNotContainsString('PKTESTKEYID', (string) json_encode($context));
    }
}
