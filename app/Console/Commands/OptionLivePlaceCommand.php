<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\AssemblesHarnessConfig;
use App\Robinhood\Execution\OrderRefStore;
use App\Robinhood\Mcp\OAuthTokenStore;
use App\Robinhood\Mcp\RobinhoodMcpClient;
use App\Robinhood\Options\OptionBrokerContext;
use App\Robinhood\Options\OptionContractResolver;
use App\Robinhood\Options\OptionExecutor;
use App\Robinhood\Options\OptionOrderIntent;
use App\Robinhood\Options\OptionRailPipeline;
use App\Robinhood\Telemetry\JsonlRecorder;
use Illuminate\Console\Command;

/**
 * LIVE option placement for one explicit contract. Scheduled autopilot uses
 * the same executor after loading complete intents from the journal.
 * Runs the complete spine: resolve -> rails -> persisted ref -> review ->
 * full rails -> place -> journal.
 */
class OptionLivePlaceCommand extends Command
{
    use AssemblesHarnessConfig;

    protected $signature = 'theta:option-live-place
        {--symbol= : Underlying symbol}
        {--expiration= : YYYY-MM-DD}
        {--strike= : Exact strike}
        {--type=call : call|put}
        {--quantity=1 : Contracts}
        {--limit-price= : Limit price per contract (required)}
        {--side=buy : buy (BTO) | sell (STC)}';

    protected $description = 'Place one bounded live single-leg option order through resolve -> rails -> review -> place -> journal';

    public function handle(): int
    {
        $cfg = $this->assembleConfig();
        $recorder = JsonlRecorder::fromConfig($cfg);

        $symbol = strtoupper((string) $this->option('symbol'));
        $expiration = (string) $this->option('expiration');
        $strike = $this->option('strike');
        $type = (string) $this->option('type');
        $side = (string) $this->option('side');
        $limitPrice = $this->option('limit-price');

        if ($symbol === '' || $expiration === '' || $strike === null || $limitPrice === null || ! in_array($type, ['call', 'put'], true) || ! in_array($side, ['buy', 'sell'], true)) {
            $this->error('required: --symbol --expiration --strike --type=call|put --limit-price [--side=buy|sell] [--quantity=N]');

            return self::INVALID;
        }

        if (! OAuthTokenStore::fromConfig($cfg)->hasToken()) {
            $this->error('blocker: oauth token absent');

            return self::FAILURE;
        }

        $client = RobinhoodMcpClient::fromConfig($cfg, $recorder);

        try {
            $resolution = (new OptionContractResolver($client, $cfg))->resolveExact($symbol, $type, $expiration, (float) $strike);
        } catch (\Throwable $e) {
            $this->error("resolver mechanical blocker: {$e->getMessage()}");

            return self::FAILURE;
        }

        if (($resolution['contract']['option_id'] ?? '') === '') {
            $this->error('contract unresolved: '.implode('; ', $resolution['blockers']));

            return self::FAILURE;
        }

        $contract = $resolution['contract'];

        $intent = new OptionOrderIntent(
            underlyingSymbol: $symbol,
            optionType: $type,
            expirationDate: $expiration,
            strikePrice: (float) $strike,
            side: $side,
            positionEffect: $side === 'buy' ? 'open' : 'close',
            quantity: max(1, (int) $this->option('quantity')),
            limitPrice: (float) $limitPrice,
            optionId: $contract['option_id'],
            chainId: $contract['chain_id'] ?? null,
            underlyingType: $contract['underlying_type'] ?? 'equity',
            source: 'theta:option-live-place',
        );

        try {
            $context = array_merge(OptionBrokerContext::assemble($client, $cfg), [
                'contract' => $contract,
                'quote' => $resolution['quote'],
            ]);
        } catch (\Throwable $e) {
            $this->error("broker-truth context mechanical blocker: {$e->getMessage()}");

            return self::FAILURE;
        }

        $executor = new OptionExecutor($client, new OptionRailPipeline, new OrderRefStore, $recorder, $cfg);

        try {
            $result = $executor->execute($intent, $context);
        } catch (\Throwable $e) {
            $this->error("placement mechanical failure (ref persisted; run theta:option-reconcile before retry): {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info("classification: {$result['classification']} (verdict {$result['verdict']->verdict()})");
        $this->table(
            ['gate', 'ok', 'category', 'reason'],
            array_map(
                fn (array $r) => [$r['gate'], $r['ok'] ? 'ok' : 'BLOCK', (string) ($r['category'] ?? ''), (string) ($r['reason'] ?? '')],
                $result['verdict']->toArray()['ledger'],
            ),
        );

        if ($result['ref'] !== null) {
            $this->line("client_ref_id: {$result['ref']->client_ref_id}");
            $this->line('broker_order_id: '.($result['ref']->broker_order_id ?? '—'));
        }
        $this->line('journal_id: '.($result['journal_id'] ?? '—'));

        return self::SUCCESS;
    }
}
