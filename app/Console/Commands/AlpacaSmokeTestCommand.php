<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\AssemblesHarnessConfig;
use App\MarketData\AlpacaMarketDataClient;
use App\Robinhood\Telemetry\JsonlRecorder;
use Illuminate\Console\Command;

class AlpacaSmokeTestCommand extends Command
{
    use AssemblesHarnessConfig;

    protected $signature = 'theta:alpaca-smoke-test {--symbol=SPY}';

    protected $description = 'Data-plane smoke test: latest SIP quote + bar with freshness/staleness verdict';

    public function handle(): int
    {
        $cfg = $this->assembleConfig();
        $symbol = strtoupper((string) $this->option('symbol'));

        $client = AlpacaMarketDataClient::fromConfig($cfg);
        $recorder = JsonlRecorder::fromConfig($cfg);

        try {
            $quote = $client->latestQuote($symbol);
            $bar = $client->latestBar($symbol);
        } catch (\Throwable $e) {
            $this->error("data-plane mechanical blocker: {$e->getMessage()}");
            $recorder->record('data_plane', [
                'event' => 'smoke_test_failed',
                'symbol' => $symbol,
                'error' => $e->getMessage(),
                'client' => $client->telemetryContext(),
            ]);

            return self::FAILURE;
        }

        $spreadBps = null;
        if (is_numeric($quote['bid'] ?? null) && is_numeric($quote['ask'] ?? null) && (float) $quote['bid'] > 0) {
            $mid = ((float) $quote['bid'] + (float) $quote['ask']) / 2;
            $spreadBps = round(((float) $quote['ask'] - (float) $quote['bid']) / $mid * 10000, 2);
        }

        $this->table(['field', 'value'], [
            ['symbol', $symbol],
            ['feed', $quote['feed']],
            ['bid', (string) ($quote['bid'] ?? '—')],
            ['ask', (string) ($quote['ask'] ?? '—')],
            ['spread_bps', (string) ($spreadBps ?? 'unverified')],
            ['quote_source_ts', (string) ($quote['source_ts'] ?? '—')],
            ['quote_age_s', (string) ($quote['age_seconds'] ?? 'unverified')],
            ['quote_stale', $quote['stale'] ? "YES (gate {$quote['staleness_gate_seconds']}s)" : 'no'],
            ['bar_close', (string) ($bar['close'] ?? '—')],
            ['bar_volume', (string) ($bar['volume'] ?? '—')],
            ['bar_source_ts', (string) ($bar['source_ts'] ?? '—')],
        ]);

        $recorder->record('data_plane', [
            'event' => 'smoke_test',
            'symbol' => $symbol,
            'quote' => array_diff_key($quote, ['raw' => true]),
            'bar' => array_diff_key($bar, ['raw' => true]),
            'spread_bps' => $spreadBps,
            'client' => $client->telemetryContext(),
        ]);

        if ($quote['stale']) {
            $this->warn('quote is stale by the configured gate — outside RTH this is expected; during RTH treat it as a data-plane defect to patch');
        }

        return self::SUCCESS;
    }
}
