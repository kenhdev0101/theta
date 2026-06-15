<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\AssemblesHarnessConfig;
use App\Models\RobinhoodTradeJournal;
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
 * REVIEW-ONLY probe: resolve -> rails -> review_option_order -> full rail
 * verdict. This command has no code path to place_option_order; live
 * placement is handled by theta:option-live-place or scheduled autopilot.
 */
class OptionReviewProbeCommand extends Command
{
    use AssemblesHarnessConfig;

    protected $signature = 'theta:option-review-probe
        {--symbol= : Underlying symbol}
        {--expiration= : YYYY-MM-DD}
        {--strike= : Exact strike}
        {--type=call : call|put}
        {--quantity=1 : Contracts}
        {--limit-price= : Limit price per contract}
        {--side=buy : buy (BTO) | sell (STC)}';

    protected $description = 'Resolve a contract, run option rails, and call broker review — never places';

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

        if ($symbol === '' || $expiration === '' || $strike === null || $limitPrice === null || ! in_array($type, ['call', 'put'], true)) {
            $this->error('required: --symbol --expiration --strike --type=call|put --limit-price (deterministic debit bound)');

            return self::INVALID;
        }

        if (! OAuthTokenStore::fromConfig($cfg)->hasToken()) {
            $this->error('blocker: oauth token absent');

            return self::FAILURE;
        }

        $client = RobinhoodMcpClient::fromConfig($cfg, $recorder);
        $resolver = new OptionContractResolver($client, $cfg);

        try {
            $resolution = $resolver->resolveExact($symbol, $type, $expiration, (float) $strike);
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
            source: 'theta:option-review-probe',
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

        $pipeline = new OptionRailPipeline;
        $executor = new OptionExecutor($client, $pipeline, new OrderRefStore, $recorder, $cfg);

        // Broker review — the only broker write-path surface this command
        // touches. Place is structurally unreachable from here.
        try {
            $review = $client->reviewOptionOrder($executor->reviewPayload($intent));
        } catch (\Throwable $e) {
            $review = ['ok' => false, 'error' => $e->getMessage()];
        }
        $context['review'] = $review;

        $verdict = $pipeline->evaluate($intent, $cfg, $context);

        $this->info("verdict: {$verdict->verdict()} (review-only probe; nothing placed)");
        $this->table(
            ['gate', 'ok', 'category', 'reason'],
            array_map(
                fn (array $r) => [$r['gate'], $r['ok'] ? 'ok' : 'BLOCK', (string) ($r['category'] ?? ''), (string) ($r['reason'] ?? '')],
                $verdict->toArray()['ledger'],
            ),
        );

        $status = $verdict->allowed() ? 'reviewed' : 'risk_aborted';

        $journal = RobinhoodTradeJournal::query()->create([
            'account_number' => (string) $cfg['agentic_account'],
            'asset_class' => 'option',
            'symbol' => $symbol,
            'underlying_symbol' => $symbol,
            'option_id' => $contract['option_id'],
            'chain_id' => $contract['chain_id'] ?? null,
            'option_type' => $type,
            'expiration_date' => $expiration,
            'strike_price' => (float) $strike,
            'position_effect' => $intent->positionEffect,
            'status' => $status,
            'intent' => $intent->isOpening() ? ($type === 'call' ? 'long_call' : 'long_put') : ($type === 'call' ? 'close_long_call' : 'close_long_put'),
            'side' => $side,
            'order_type' => 'limit',
            'quantity' => $intent->quantity,
            'limit_price' => (float) $limitPrice,
            'dollar_amount' => $intent->estimatedDebit(),
            'strategy_label' => 'theta_option_single_leg',
            'structure_class' => 'single_leg_long',
            'reviewed_at' => $verdict->allowed() ? now() : null,
            'review_payload' => $review,
            'scanner_payload' => ['intent_source' => 'theta:option-review-probe', 'quote' => $resolution['quote']],
            'notes' => ['rail_verdict' => $verdict->toArray(), 'probe' => 'review_only'],
        ]);

        $recorder->record('options_review_probe', [
            'event' => 'review_probe',
            'verdict' => $verdict->verdict(),
            'block_reasons' => $verdict->blockReasons(),
            'contract' => $intent->contractLabel(),
            'option_id' => $contract['option_id'],
            'journal_id' => $journal->id,
            'placed' => false,
        ]);

        $eligibility = $verdict->allowed()
            ? 'eligible for scheduled autopilot'
            : 'not eligible for scheduled autopilot';

        $this->line("journal_id: {$journal->id} (status={$status}; {$eligibility})");

        return self::SUCCESS;
    }
}
