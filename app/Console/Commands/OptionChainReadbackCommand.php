<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\AssemblesHarnessConfig;
use App\Robinhood\Mcp\OAuthTokenStore;
use App\Robinhood\Mcp\PortfolioReader;
use App\Robinhood\Mcp\RobinhoodMcpClient;
use App\Robinhood\Telemetry\JsonlRecorder;
use Illuminate\Console\Command;

class OptionChainReadbackCommand extends Command
{
    use AssemblesHarnessConfig;

    protected $signature = 'theta:option-chain-readback {--symbol=SPY}';

    protected $description = 'Read the live option chain for an underlying: chain id, expirations, multiplier';

    public function handle(): int
    {
        $cfg = $this->assembleConfig();
        $recorder = JsonlRecorder::fromConfig($cfg);
        $symbol = strtoupper((string) $this->option('symbol'));

        if (! OAuthTokenStore::fromConfig($cfg)->hasToken()) {
            $this->error('blocker: oauth token absent');

            return self::FAILURE;
        }

        $client = RobinhoodMcpClient::fromConfig($cfg, $recorder);

        try {
            $chains = PortfolioReader::listFrom($client->getOptionChains($symbol), ['chains']);
        } catch (\Throwable $e) {
            $this->error("chain read mechanical blocker: {$e->getMessage()}");

            return self::FAILURE;
        }

        if ($chains === []) {
            $this->error("no option chain for {$symbol}");

            return self::FAILURE;
        }

        foreach ($chains as $chain) {
            $expirations = (array) ($chain['expiration_dates'] ?? []);
            $this->table(['field', 'value'], [
                ['symbol', (string) ($chain['symbol'] ?? $symbol)],
                ['chain_id', (string) ($chain['id'] ?? '—')],
                ['can_open_position', ($chain['can_open_position'] ?? null) === true ? 'yes' : 'no'],
                ['trade_value_multiplier', (string) ($chain['trade_value_multiplier'] ?? '—')],
                ['extended_hours_state', (string) ($chain['extended_hours_state'] ?? '—')],
                ['expiration_count', (string) count($expirations)],
                ['nearest_expirations', implode(', ', array_slice($expirations, 0, 8))],
            ]);
        }

        $recorder->record('options_chain', [
            'event' => 'chain_readback',
            'symbol' => $symbol,
            'chains' => count($chains),
            'chain_id' => $chains[0]['id'] ?? null,
            'expiration_count' => count((array) ($chains[0]['expiration_dates'] ?? [])),
        ]);

        return self::SUCCESS;
    }
}
