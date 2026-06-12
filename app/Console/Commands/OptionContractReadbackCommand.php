<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\AssemblesHarnessConfig;
use App\Robinhood\Mcp\OAuthTokenStore;
use App\Robinhood\Mcp\RobinhoodMcpClient;
use App\Robinhood\Options\OptionContractResolver;
use App\Robinhood\Telemetry\JsonlRecorder;
use Illuminate\Console\Command;

class OptionContractReadbackCommand extends Command
{
    use AssemblesHarnessConfig;

    protected $signature = 'theta:option-contract-readback
        {--symbol= : Underlying symbol}
        {--expiration= : YYYY-MM-DD}
        {--strike= : Exact strike}
        {--type=call : call|put}';

    protected $description = 'Resolve one exact option contract deterministically and read its live quote';

    public function handle(): int
    {
        $cfg = $this->assembleConfig();
        $recorder = JsonlRecorder::fromConfig($cfg);

        $symbol = strtoupper((string) $this->option('symbol'));
        $expiration = (string) $this->option('expiration');
        $strike = $this->option('strike');
        $type = (string) $this->option('type');

        if ($symbol === '' || $expiration === '' || $strike === null || ! in_array($type, ['call', 'put'], true)) {
            $this->error('required: --symbol --expiration=YYYY-MM-DD --strike --type=call|put');

            return self::INVALID;
        }

        if (! OAuthTokenStore::fromConfig($cfg)->hasToken()) {
            $this->error('blocker: oauth token absent');

            return self::FAILURE;
        }

        $resolver = new OptionContractResolver(RobinhoodMcpClient::fromConfig($cfg, $recorder), $cfg);

        try {
            $result = $resolver->resolveExact($symbol, $type, $expiration, (float) $strike);
        } catch (\Throwable $e) {
            $this->error("resolver mechanical blocker: {$e->getMessage()}");

            return self::FAILURE;
        }

        $contract = $result['contract'] ?? [];
        $quote = $result['quote'] ?? [];

        $this->table(['field', 'value'], [
            ['verdict', $result['verdict']],
            ['option_id', (string) ($contract['option_id'] ?? '—')],
            ['chain_id', (string) ($contract['chain_id'] ?? '—')],
            ['contract', "{$symbol} {$expiration} {$strike} {$type}"],
            ['tradability', (string) ($contract['tradability'] ?? '—')],
            ['state', (string) ($contract['state'] ?? '—')],
            ['bid / ask', sprintf('%s / %s', $quote['bid'] ?? '—', $quote['ask'] ?? '—')],
            ['mid / mark', sprintf('%s / %s', $quote['mid'] ?? '—', $quote['mark'] ?? '—')],
            ['spread_bps', (string) ($quote['spread_bps'] ?? 'unverified')],
            ['delta / iv', sprintf('%s / %s', $quote['delta'] ?? '—', $quote['implied_volatility'] ?? '—')],
            ['oi / volume', sprintf('%s / %s', $quote['open_interest'] ?? '—', $quote['volume'] ?? '—')],
            ['quote_updated_at', (string) ($quote['updated_at'] ?? '—')],
            ['quote_age_s', (string) ($quote['age_seconds'] ?? 'unverified')],
            ['quote_stale', ($quote['stale'] ?? true) ? 'YES' : 'no'],
        ]);

        foreach ((array) ($result['blockers'] ?? []) as $blocker) {
            $this->warn("  blocker: {$blocker}");
        }

        $recorder->record('options_contract', [
            'event' => 'contract_readback',
            'verdict' => $result['verdict'],
            'blockers' => $result['blockers'],
            'option_id' => $contract['option_id'] ?? null,
            'contract' => "{$symbol} {$expiration} {$strike} {$type}",
            'quote' => $quote === [] ? null : array_diff_key($quote, ['staleness_gate_seconds' => true]),
        ]);

        return $result['verdict'] === OptionContractResolver::VERDICT_RESOLVED ? self::SUCCESS : self::FAILURE;
    }
}
