<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\AssemblesHarnessConfig;
use App\MarketData\AlpacaMarketDataClient;
use App\Models\RobinhoodTradeJournal;
use App\Robinhood\Mcp\OAuthTokenStore;
use App\Robinhood\Mcp\PortfolioReader;
use App\Robinhood\Mcp\RobinhoodMcpClient;
use App\Robinhood\Options\OptionBrokerContext;
use App\Robinhood\Options\OptionGateResult;
use App\Robinhood\Options\OptionOrderIntent;
use App\Robinhood\Options\OptionRailPipeline;
use App\Robinhood\Options\OptionRailVerdict;
use App\Robinhood\Options\MomentumUniverseSelector;
use App\Robinhood\Rails\SessionClock;
use App\Robinhood\Telemetry\JsonlRecorder;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Autonomous data-plane -> reviewed-option-intent producer.
 *
 * Alpaca is the discovery substrate. Robinhood is used only after discovery
 * for broker truth, chain/instrument/quote, and review.
 */
class OptionIntentProducerCommand extends Command
{
    use AssemblesHarnessConfig;

    protected $signature = 'theta:option-intent-producer
        {--symbols= : Comma-separated symbols; defaults to harness.options.producer.symbols}
        {--limit=1 : Maximum reviewed intents to create this run}
        {--dry-run : Evaluate and print the decision per symbol WITHOUT reviewing, placing, or writing intents. Read-only preview; bypasses the mode/kill/session arm gates so decisions can be inspected off-hours.}';

    protected $description = 'Produce fresh reviewed option intents from live data for scheduled autopilot';

    public function handle(): int
    {
        $cfg = $this->assembleConfig();
        $options = (array) ($cfg['options'] ?? []);
        $producer = (array) ($options['producer'] ?? []);
        $recorder = JsonlRecorder::fromConfig($cfg);
        // Read-only preview: evaluate and print decisions, never review/place/persist.
        $dryRun = (bool) $this->option('dry-run');

        if (! $dryRun && ($cfg['mode'] ?? 'approval') !== 'auto') {
            $this->warn('producer disarmed: HARNESS_MODE is not auto');

            return self::SUCCESS;
        }

        if (! (bool) ($options['enabled'] ?? false) || ! (bool) ($producer['enabled'] ?? false)) {
            $this->warn('producer disarmed: options producer disabled');

            return self::SUCCESS;
        }

        if (! $dryRun && (bool) ($cfg['kill_switch'] ?? true)) {
            $this->warn('producer disarmed: kill switch engaged');

            return self::SUCCESS;
        }

        if (! $dryRun && ! (new SessionClock)->isRegularHoursOpen()) {
            $this->info('producer idle: regular options session closed');

            return self::SUCCESS;
        }

        if (! OAuthTokenStore::fromConfig($cfg)->hasToken()) {
            $this->error('blocker: oauth token absent');

            return self::FAILURE;
        }

        $data = AlpacaMarketDataClient::fromConfig($cfg);
        $symbols = $this->symbols($producer, $data, new MomentumUniverseSelector);
        if ($symbols === []) {
            $this->warn('producer idle: no symbols configured');

            return self::SUCCESS;
        }

        $maxCreated = max(1, (int) $this->option('limit'));
        $client = RobinhoodMcpClient::fromConfig($cfg, $recorder);
        $rails = new OptionRailPipeline;

        if ($dryRun) {
            $this->previewEntries($client, $cfg, $producer, $rails, $data, $symbols);

            return self::SUCCESS;
        }

        $created = 0;
        $blocked = 0;
        $deferred = 0;
        // Decision/execution separation: when armed, evaluation never depends on
        // capital — capital gates execution only (capital-short setups defer).
        $capitalIndependent = (bool) ($producer['capital_independent_evaluation'] ?? false);
        $entryBrokerContext = null;
        $entryCandidates = [];

        $exitLimit = max($maxCreated, (int) ($options['max_open_positions'] ?? $maxCreated));
        $exitResult = $this->produceExitIntents($client, $cfg, $producer, $rails, $recorder, $exitLimit);
        $created += $exitResult['created'];
        $blocked += $exitResult['blocked'];

        foreach ($symbols as $symbol) {
            if ($created >= $maxCreated) {
                break;
            }

            if ($this->hasPendingReviewedIntent($symbol)) {
                $this->line("{$symbol}: existing reviewed intent pending");

                continue;
            }

            try {
                $underlying = $data->latestQuote($symbol);
            } catch (\Throwable $e) {
                $blocked++;
                $this->recordProducerBlock($symbol, 'data_plane_mechanical_blocker: '.$e->getMessage(), [], $producer);
                $this->error("{$symbol}: data-plane blocker: {$e->getMessage()}");

                continue;
            }

            if (($underlying['stale'] ?? true) === true || ! is_numeric($underlying['bid'] ?? null) || ! is_numeric($underlying['ask'] ?? null)) {
                $blocked++;
                $this->recordProducerBlock($symbol, 'underlying_quote_stale_or_incomplete', ['underlying_quote' => $underlying], $producer);
                $this->warn("{$symbol}: underlying quote stale or incomplete");

                continue;
            }

            try {
                $bars = $data->recentBars(
                    $symbol,
                    max(3, (int) ($producer['momentum_lookback_bars'] ?? 10)),
                    (string) ($producer['momentum_timeframe'] ?? '1Min'),
                );
            } catch (\Throwable $e) {
                $blocked++;
                $this->recordProducerBlock($symbol, 'momentum_data_blocker: '.$e->getMessage(), [
                    'underlying_quote' => $underlying,
                ], $producer);
                $this->error("{$symbol}: momentum data blocker: {$e->getMessage()}");

                continue;
            }

            $momentum = $this->momentumSignal($symbol, $underlying, $bars, $producer);
            if (($momentum['verdict'] ?? 'BLOCK') !== 'ALLOW') {
                $blocked++;
                $this->recordProducerBlock($symbol, 'momentum_signal_absent: '.(string) ($momentum['reason'] ?? 'unknown'), [
                    'underlying_quote' => $underlying,
                    'momentum_signal' => $momentum,
                ], $producer);
                $this->warn("{$symbol}: momentum signal absent");

                continue;
            }

            try {
                $entryBrokerContext ??= OptionBrokerContext::assemble($client, $cfg);
            } catch (\Throwable $e) {
                $blocked++;
                $this->recordProducerBlock($symbol, 'broker_truth_blocker: '.$e->getMessage(), [
                    'underlying_quote' => $underlying,
                    'momentum_signal' => $momentum,
                ], $producer);
                $this->error("{$symbol}: broker-truth blocker: {$e->getMessage()}");

                continue;
            }

            $brokerContext = $entryBrokerContext;
            $recentEntry = $this->recentEntryForSymbol($symbol, $producer);
            if ($recentEntry !== null) {
                $blocked++;
                $this->recordProducerBlock($symbol, 'entry_symbol_cooldown', [
                    'underlying_quote' => $underlying,
                    'momentum_signal' => $momentum,
                    'recent_entry' => $recentEntry,
                ], $producer);
                $this->warn("{$symbol}: entry symbol cooldown");

                continue;
            }

            $selection = $this->selectContract(
                $client,
                $cfg,
                $symbol,
                (float) $underlying['ask'],
                (string) ($momentum['option_type'] ?? $producer['option_type'] ?? 'call'),
                $brokerContext,
                $capitalIndependent,
            );
            if (($selection['contract'] ?? null) === null || ($selection['quote'] ?? null) === null) {
                $blocked++;
                $this->recordProducerBlock($symbol, 'no_marketable_contract: '.implode('; ', (array) ($selection['blockers'] ?? [])), [
                    'underlying_quote' => $underlying,
                    'momentum_signal' => $momentum,
                    'selection' => $selection,
                ], $producer);
                $this->warn("{$symbol}: no marketable contract");

                continue;
            }

            $contract = $selection['contract'];
            $quote = $selection['quote'];
            $limitPrice = (float) $quote['ask'];

            $intent = new OptionOrderIntent(
                underlyingSymbol: $symbol,
                optionType: (string) $contract['type'],
                expirationDate: (string) $contract['expiration_date'],
                strikePrice: (float) $contract['strike_price'],
                side: 'buy',
                positionEffect: 'open',
                quantity: 1,
                limitPrice: $limitPrice,
                optionId: (string) $contract['option_id'],
                chainId: $contract['chain_id'] ?? null,
                underlyingType: $contract['underlying_type'] ?? 'equity',
                source: 'theta:option-intent-producer',
            );

            $context = array_merge($brokerContext, [
                'contract' => $contract,
                'quote' => $quote,
            ]);

            // Candidate quality is capital-independent (when armed): evaluate the
            // structural/marketability gates WITHOUT caps so a capital-short
            // ticker is still a valid candidate; capital gates execution only.
            $preVerdict = $rails->evaluate($intent, $cfg, $context, includeReview: false, includeCaps: ! $capitalIndependent);
            if (! $preVerdict->allowed()) {
                $blocked++;
                if (! $this->recentProducerBlockExists($symbol, $producer)) {
                    $this->journalIntent($intent, 'risk_aborted', $underlying, $momentum, $selection, [], $preVerdict->toArray());
                }
                $this->warn("{$symbol}: producer pre-review rail block {$preVerdict->verdict()}");

                continue;
            }

            if ($this->hasOpenJournalForOption((string) $contract['option_id'])) {
                $this->line("{$symbol}: selected option already has open journal state");

                continue;
            }

            $allocation = $this->allocationScore($intent, $momentum, $selection, $producer);
            if ($allocation['expected_gain'] < (float) ($producer['allocation_min_expected_gain'] ?? 0.0)) {
                $blocked++;
                $this->recordProducerBlock($symbol, 'allocation_expected_gain_below_min', [
                    'underlying_quote' => $underlying,
                    'momentum_signal' => $momentum,
                    'selection' => $selection,
                    'allocation' => $allocation,
                ], $producer);
                $this->warn("{$symbol}: allocation expected gain below minimum");

                continue;
            }

            $selection['allocation'] = $allocation;
            $entryCandidates[] = [
                'intent' => $intent,
                'context' => $context,
                'underlying' => $underlying,
                'momentum' => $momentum,
                'selection' => $selection,
                'allocation' => $allocation,
            ];
            $this->line(sprintf(
                '%s: candidate %s expected_gain=%0.2f velocity=%0.4f debit=%0.2f',
                $symbol,
                $intent->contractLabel(),
                $allocation['expected_gain'],
                $allocation['capital_velocity_score'],
                $allocation['capital_required'],
            ));
        }

        usort($entryCandidates, function (array $a, array $b): int {
            $score = ($b['allocation']['capital_velocity_score'] ?? 0.0) <=> ($a['allocation']['capital_velocity_score'] ?? 0.0);
            if ($score !== 0) {
                return $score;
            }

            return ($b['allocation']['expected_gain'] ?? 0.0) <=> ($a['allocation']['expected_gain'] ?? 0.0);
        });

        foreach ($entryCandidates as $candidate) {
            if ($created >= $maxCreated) {
                break;
            }

            /** @var OptionOrderIntent $intent */
            $intent = $candidate['intent'];
            $context = $candidate['context'];
            $symbol = $intent->underlyingSymbol;

            if ($this->hasOpenJournalForOption((string) $intent->optionId)) {
                $this->line("{$symbol}: selected option already has open journal state");

                continue;
            }

            // Capital/allocation gates EXECUTION only — never evaluation. A valid
            // setup that is merely capital-short (e.g. sale proceeds awaiting
            // settlement) is recorded as execution-deferred and re-promoted once
            // capital frees. We check this BEFORE the broker review so we never
            // spend a review call on an order we cannot fund. Deferred rows are
            // NOT executable — only a fresh re-evaluation next cycle promotes a
            // capital-ready candidate to `reviewed`.
            if ($capitalIndependent) {
                $capitalVerdict = $rails->evaluate($intent, $cfg, $context, includeReview: false, includeCaps: true);
                if (! $capitalVerdict->allowed()) {
                    if ($this->isCapitalOnlyBlock($capitalVerdict)) {
                        $deferred++;
                        $this->recordProducerBlock($symbol, 'execution_deferred_capital: '.$this->firstBlockToken($capitalVerdict), [
                            'underlying_quote' => $candidate['underlying'],
                            'momentum_signal' => $candidate['momentum'],
                            'selection' => $candidate['selection'],
                            'allocation' => $candidate['allocation'],
                            'rail_verdict' => $capitalVerdict->toArray(),
                        ], $producer, status: 'execution_deferred');
                        $this->info("{$symbol}: evaluated; execution deferred (capital) {$capitalVerdict->verdict()}");
                    } else {
                        $blocked++;
                        if (! $this->recentProducerBlockExists($symbol, $producer)) {
                            $this->journalIntent($intent, 'risk_aborted', $candidate['underlying'], $candidate['momentum'], $candidate['selection'], [], $capitalVerdict->toArray());
                        }
                        $this->warn("{$symbol}: producer rail block {$capitalVerdict->verdict()}");
                    }

                    continue;
                }
            }

            try {
                $executor = new \App\Robinhood\Options\OptionExecutor(
                    $client,
                    $rails,
                    new \App\Robinhood\Execution\OrderRefStore,
                    $recorder,
                    $cfg,
                );
                $review = $client->reviewOptionOrder($executor->reviewPayload($intent));
                $context['review'] = $review;
            } catch (\Throwable $e) {
                $blocked++;
                $this->recordProducerBlock($symbol, 'review_blocker: '.$e->getMessage(), [
                    'underlying_quote' => $candidate['underlying'],
                    'momentum_signal' => $candidate['momentum'],
                    'selection' => $candidate['selection'],
                ], $producer);
                $this->error("{$symbol}: review blocker: {$e->getMessage()}");

                continue;
            }

            $verdict = $rails->evaluate($intent, $cfg, $context);
            if (! $verdict->allowed()) {
                $blocked++;
                if (! $this->recentProducerBlockExists($symbol, $producer)) {
                    $this->journalIntent($intent, 'risk_aborted', $candidate['underlying'], $candidate['momentum'], $candidate['selection'], $review, $verdict->toArray());
                }
                $this->warn("{$symbol}: producer rail block {$verdict->verdict()}");

                continue;
            }

            $journal = $this->journalIntent($intent, 'reviewed', $candidate['underlying'], $candidate['momentum'], $candidate['selection'], $review, $verdict->toArray());
            $created++;
            $this->info(sprintf(
                '%s: reviewed option intent %d %s @ %0.2f expected_gain=%0.2f',
                $symbol,
                $journal->id,
                $intent->contractLabel(),
                (float) $intent->limitPrice,
                $candidate['allocation']['expected_gain'],
            ));
        }

        $this->info("producer complete: created={$created} blocked={$blocked} deferred={$deferred}");

        return self::SUCCESS;
    }

    /** True when every blocking gate in the verdict is the capital/allocation gate. */
    private function isCapitalOnlyBlock(OptionRailVerdict $verdict): bool
    {
        $blocking = array_filter($verdict->results, fn (OptionGateResult $r): bool => ! $r->ok);
        if ($blocking === []) {
            return false;
        }

        foreach ($blocking as $result) {
            if ($result->gate !== 'caps') {
                return false;
            }
        }

        return true;
    }

    /** First blocking reason token (gate prefix stripped), e.g. "buying_power_insufficient". */
    private function firstBlockToken(OptionRailVerdict $verdict): string
    {
        foreach ($verdict->results as $result) {
            if (! $result->ok) {
                return (string) $result->reason;
            }
        }

        return 'unknown';
    }

    /**
     * Read-only decision preview: run the exact entry evaluation per symbol and
     * print what the producer WOULD do, without reviewing, placing, or writing
     * any journal rows. Lets the operator watch decisions — including
     * capital-independent evaluation and capital deferral — off-hours.
     */
    private function previewEntries(RobinhoodMcpClient $client, array $cfg, array $producer, OptionRailPipeline $rails, AlpacaMarketDataClient $data, array $symbols): void
    {
        $capitalIndependent = (bool) ($producer['capital_independent_evaluation'] ?? false);
        $money = fn ($v): string => $v === null ? 'n/a' : '$'.number_format((float) $v, 2);

        $this->info(sprintf('DRY RUN — read-only decision preview (capital_independent=%s). No review, no place, no journal writes.', $capitalIndependent ? 'on' : 'off'));

        try {
            $brokerContext = OptionBrokerContext::assemble($client, $cfg);
            $this->line(sprintf(
                'broker truth: buying_power=%s settled_cash=%s open_positions=%d',
                $money($brokerContext['buying_power'] ?? null),
                $money($brokerContext['settled_cash'] ?? null),
                is_array($brokerContext['open_option_positions'] ?? null) ? count($brokerContext['open_option_positions']) : 0,
            ));
        } catch (\Throwable $e) {
            $this->warn('broker-truth read failed: '.$e->getMessage().' (continuing; capital figures unknown)');
            $brokerContext = [];
        }

        foreach ($symbols as $symbol) {
            try {
                $underlying = $data->latestQuote($symbol);
            } catch (\Throwable $e) {
                $this->line("{$symbol}: data-plane blocker — {$e->getMessage()}");

                continue;
            }
            if (($underlying['stale'] ?? true) === true || ! is_numeric($underlying['bid'] ?? null) || ! is_numeric($underlying['ask'] ?? null)) {
                $this->line("{$symbol}: underlying quote stale/incomplete");

                continue;
            }

            try {
                $bars = $data->recentBars($symbol, max(3, (int) ($producer['momentum_lookback_bars'] ?? 10)), (string) ($producer['momentum_timeframe'] ?? '1Min'));
            } catch (\Throwable $e) {
                $this->line("{$symbol}: momentum data blocker — {$e->getMessage()}");

                continue;
            }

            $momentum = $this->momentumSignal($symbol, $underlying, $bars, $producer);
            if (($momentum['verdict'] ?? 'BLOCK') !== 'ALLOW') {
                $this->line(sprintf('%s: no momentum — %s', $symbol, (string) ($momentum['reason'] ?? 'unknown')));

                continue;
            }

            $selection = $this->selectContract($client, $cfg, $symbol, (float) $underlying['ask'], (string) ($momentum['option_type'] ?? $producer['option_type'] ?? 'call'), $brokerContext, $capitalIndependent);
            if (($selection['contract'] ?? null) === null || ($selection['quote'] ?? null) === null) {
                $this->line(sprintf('%s: no marketable contract — %s', $symbol, implode('; ', (array) ($selection['blockers'] ?? []))));

                continue;
            }

            $contract = $selection['contract'];
            $quote = $selection['quote'];
            $intent = new OptionOrderIntent(
                underlyingSymbol: $symbol,
                optionType: (string) $contract['type'],
                expirationDate: (string) $contract['expiration_date'],
                strikePrice: (float) $contract['strike_price'],
                side: 'buy',
                positionEffect: 'open',
                quantity: 1,
                limitPrice: (float) $quote['ask'],
                optionId: (string) $contract['option_id'],
                chainId: $contract['chain_id'] ?? null,
                underlyingType: $contract['underlying_type'] ?? 'equity',
                source: 'theta:option-intent-producer:dry-run',
            );
            $context = array_merge($brokerContext, ['contract' => $contract, 'quote' => $quote]);

            $structural = $rails->evaluate($intent, $cfg, $context, includeReview: false, includeCaps: ! $capitalIndependent);
            if (! $structural->allowed()) {
                $this->line(sprintf('%s: structural block — %s (%s)', $symbol, $structural->verdict(), implode('; ', $structural->blockReasons())));

                continue;
            }

            $allocation = $this->allocationScore($intent, $momentum, $selection, $producer);
            $capital = $rails->evaluate($intent, $cfg, $context, includeReview: false, includeCaps: true);

            if (! $capital->allowed()) {
                if ($capitalIndependent && $this->isCapitalOnlyBlock($capital)) {
                    $this->info(sprintf('%s: WOULD EVALUATE → DEFER (capital) %s debit=%s — %s', $symbol, $intent->contractLabel(), $money($intent->estimatedDebit()), $this->firstBlockToken($capital)));
                } else {
                    $this->line(sprintf('%s: blocked — %s (%s)', $symbol, $capital->verdict(), implode('; ', $capital->blockReasons())));
                }

                continue;
            }

            $this->info(sprintf('%s: WOULD REVIEW → PLACE %s @ %s debit=%s expected_gain=%0.2f', $symbol, $intent->contractLabel(), $money($quote['ask']), $money($intent->estimatedDebit()), (float) ($allocation['expected_gain'] ?? 0.0)));
        }

        $this->info('dry-run preview complete — no orders reviewed or placed.');
    }

    private function produceExitIntents(RobinhoodMcpClient $client, array $cfg, array $producer, OptionRailPipeline $rails, JsonlRecorder $recorder, int $maxCreated): array
    {
        if (! (bool) ($producer['exit_enabled'] ?? true) || $maxCreated <= 0) {
            return ['created' => 0, 'blocked' => 0];
        }

        $created = 0;
        $blocked = 0;
        $exitBrokerContext = null;

        try {
            $positions = PortfolioReader::listFrom($client->getOptionPositions(nonzero: true), ['positions']);
        } catch (\Throwable $e) {
            $this->error('exit producer blocker: '.$e->getMessage());

            return ['created' => 0, 'blocked' => 1];
        }

        $heldPositions = [];
        foreach ($positions as $position) {
            $held = $this->normalizeOpenOptionPosition($position);
            if ($held === null) {
                continue;
            }

            $heldPositions[] = $this->enrichPositionContract($client, $held);
        }

        usort($heldPositions, function (array $a, array $b): int {
            $aHeld = is_numeric($a['held_minutes'] ?? null) ? (float) $a['held_minutes'] : -1.0;
            $bHeld = is_numeric($b['held_minutes'] ?? null) ? (float) $b['held_minutes'] : -1.0;
            if ($aHeld !== $bHeld) {
                return $bHeld <=> $aHeld;
            }

            return strcmp((string) ($a['opened_at'] ?? ''), (string) ($b['opened_at'] ?? ''));
        });

        foreach ($heldPositions as $held) {
            if ($created >= $maxCreated) {
                break;
            }

            if (! in_array($held['option_type'] ?? null, ['call', 'put'], true) || ($held['strike_price'] ?? null) === null || ($held['expiration_date'] ?? '') === '') {
                $blocked++;
                $this->recordProducerBlock((string) $held['underlying_symbol'], 'exit_contract_unresolved', [
                    'exit_position' => $held,
                ], $producer);
                $this->warn("{$held['underlying_symbol']}: exit contract unresolved");

                continue;
            }

            if ($this->hasPendingCloseIntent((string) $held['option_id'])) {
                $this->line("{$held['underlying_symbol']}: close intent already pending for {$held['option_id']}");

                continue;
            }

            try {
                $quoteRows = PortfolioReader::listFrom($client->getOptionQuotes([(string) $held['option_id']]), ['results']);
                $quote = $this->normalizeQuote($quoteRows[0]['quote'] ?? null, (array) ($cfg['options'] ?? []));
            } catch (\Throwable $e) {
                $blocked++;
                $this->recordProducerBlock((string) $held['underlying_symbol'], 'exit_quote_blocker: '.$e->getMessage(), [
                    'exit_position' => $held,
                ], $producer);
                $this->error("{$held['underlying_symbol']}: exit quote blocker: {$e->getMessage()}");

                continue;
            }

            $exitSignal = $this->exitSignal($held, $quote, $producer);
            if (($exitSignal['verdict'] ?? 'BLOCK') !== 'ALLOW') {
                $blocked++;
                $payload = [
                    'exit_position' => $held,
                    'exit_signal' => $exitSignal,
                    'quote' => $quote,
                ];
                $reason = 'exit_'.$exitSignal['reason'];
                $this->recentProducerBlockExists((string) $held['underlying_symbol'], $producer)
                    ? $this->recordSuppressedProducerBlock((string) $held['underlying_symbol'], $reason, $payload)
                    : $this->recordProducerBlock((string) $held['underlying_symbol'], $reason, $payload, $producer);
                continue;
            }

            $intent = new OptionOrderIntent(
                underlyingSymbol: (string) $held['underlying_symbol'],
                optionType: (string) $held['option_type'],
                expirationDate: (string) $held['expiration_date'],
                strikePrice: (float) $held['strike_price'],
                side: 'sell',
                positionEffect: 'close',
                quantity: max(1, (int) $held['quantity']),
                limitPrice: (float) $quote['bid'],
                optionId: (string) $held['option_id'],
                chainId: $held['chain_id'] ?? null,
                underlyingType: $held['underlying_type'] ?? 'equity',
                source: 'theta:option-intent-producer:exit',
            );

            try {
                $exitBrokerContext ??= OptionBrokerContext::assemble($client, $cfg);
                $context = array_merge($exitBrokerContext, [
                    'contract' => [
                        'option_id' => (string) $held['option_id'],
                        'chain_id' => $held['chain_id'] ?? null,
                        'tradability' => $held['tradability'] ?? 'tradable',
                        'state' => $held['state'] ?? 'active',
                    ],
                    'quote' => $quote,
                ]);
            } catch (\Throwable $e) {
                $blocked++;
                $this->recordProducerBlock((string) $held['underlying_symbol'], 'exit_broker_truth_blocker: '.$e->getMessage(), [
                    'exit_position' => $held,
                    'exit_signal' => $exitSignal,
                ], $producer);
                $this->error("{$held['underlying_symbol']}: exit broker-truth blocker: {$e->getMessage()}");

                continue;
            }

            $preVerdict = $rails->evaluate($intent, $cfg, $context, includeReview: false);
            if (! $preVerdict->allowed()) {
                $blocked++;
                if (! $this->recentProducerBlockExists((string) $held['underlying_symbol'], $producer)) {
                    $this->journalIntent($intent, 'risk_aborted', [], $this->exitSignalPayload($exitSignal), [
                        'quote' => $quote,
                        'position' => $held,
                    ], [], $preVerdict->toArray());
                }
                $this->warn("{$held['underlying_symbol']}: exit pre-review rail block {$preVerdict->verdict()}");

                continue;
            }

            try {
                $executor = new \App\Robinhood\Options\OptionExecutor(
                    $client,
                    $rails,
                    new \App\Robinhood\Execution\OrderRefStore,
                    $recorder,
                    $cfg,
                );
                $review = $client->reviewOptionOrder($executor->reviewPayload($intent));
                $context['review'] = $review;
            } catch (\Throwable $e) {
                $blocked++;
                $this->recordProducerBlock((string) $held['underlying_symbol'], 'exit_review_blocker: '.$e->getMessage(), [
                    'exit_position' => $held,
                    'exit_signal' => $exitSignal,
                ], $producer);
                $this->error("{$held['underlying_symbol']}: exit review blocker: {$e->getMessage()}");

                continue;
            }

            $verdict = $rails->evaluate($intent, $cfg, $context);
            if (! $verdict->allowed()) {
                $blocked++;
                if (! $this->recentProducerBlockExists((string) $held['underlying_symbol'], $producer)) {
                    $this->journalIntent($intent, 'risk_aborted', [], $this->exitSignalPayload($exitSignal), [
                        'quote' => $quote,
                        'position' => $held,
                    ], $review, $verdict->toArray());
                }
                $this->warn("{$held['underlying_symbol']}: exit rail block {$verdict->verdict()}");

                continue;
            }

            $journal = $this->journalIntent($intent, 'reviewed', [], $this->exitSignalPayload($exitSignal), [
                'quote' => $quote,
                'position' => $held,
            ], $review, $verdict->toArray());
            $created++;
            $this->info("{$held['underlying_symbol']}: reviewed exit intent {$journal->id} {$intent->contractLabel()} @ {$quote['bid']}");
        }

        return ['created' => $created, 'blocked' => $blocked];
    }

    private function enrichPositionContract(RobinhoodMcpClient $client, array $held): array
    {
        if (in_array($held['option_type'] ?? null, ['call', 'put'], true) && ($held['strike_price'] ?? null) !== null && ($held['expiration_date'] ?? '') !== '') {
            return $held;
        }

        try {
            $instruments = PortfolioReader::listFrom($client->getOptionInstruments([
                'ids' => (string) $held['option_id'],
            ]), ['instruments', 'results']);
        } catch (\Throwable) {
            return $held;
        }

        $instrument = $instruments[0] ?? null;
        if (! is_array($instrument)) {
            return $held;
        }

        $strike = $this->positiveOrNull($instrument['strike_price'] ?? null);

        return array_merge($held, [
            'chain_id' => $held['chain_id'] ?? ($instrument['chain_id'] ?? null),
            'underlying_symbol' => strtoupper((string) ($held['underlying_symbol'] ?: ($instrument['chain_symbol'] ?? ''))),
            'underlying_type' => $held['underlying_type'] ?? ($instrument['underlying_type'] ?? 'equity'),
            'option_type' => in_array(($instrument['type'] ?? null), ['call', 'put'], true) ? $instrument['type'] : ($held['option_type'] ?? null),
            'expiration_date' => $held['expiration_date'] ?: (string) ($instrument['expiration_date'] ?? ''),
            'strike_price' => $held['strike_price'] ?? $strike,
            'tradability' => $instrument['tradability'] ?? ($held['tradability'] ?? 'tradable'),
            'state' => $instrument['state'] ?? ($held['state'] ?? 'active'),
        ]);
    }

    private function normalizeOpenOptionPosition(mixed $position): ?array
    {
        if (! is_array($position)) {
            return null;
        }

        $optionId = (string) (
            data_get($position, 'option_id')
            ?? data_get($position, 'instrument_id')
            ?? data_get($position, 'option')
            ?? data_get($position, 'instrument')
            ?? ''
        );
        $symbol = strtoupper((string) (
            data_get($position, 'underlying_symbol')
            ?? data_get($position, 'chain_symbol')
            ?? data_get($position, 'symbol')
            ?? data_get($position, 'option.chain_symbol')
            ?? ''
        ));
        $quantity = $this->positiveOrNull(
            data_get($position, 'quantity')
            ?? data_get($position, 'quantity_available')
            ?? data_get($position, 'open_quantity')
        );
        $expiration = (string) (
            data_get($position, 'expiration_date')
            ?? data_get($position, 'option.expiration_date')
            ?? ''
        );
        $strike = $this->positiveOrNull(
            data_get($position, 'strike_price')
            ?? data_get($position, 'option.strike_price')
        );
        $optionType = strtolower((string) (
            data_get($position, 'type')
            ?? data_get($position, 'option_type')
            ?? data_get($position, 'option.type')
            ?? ''
        ));

        if ($optionId === '' || $symbol === '' || $quantity === null) {
            return null;
        }

        $entry = RobinhoodTradeJournal::query()
            ->where('asset_class', 'option')
            ->where('option_id', $optionId)
            ->where('side', 'buy')
            ->where('position_effect', 'open')
            ->where('status', 'filled')
            ->latest('filled_at')
            ->latest('id')
            ->first();

        $averagePrice = $this->positionAveragePrice($position);
        if ($averagePrice <= 0.0 && $entry instanceof RobinhoodTradeJournal) {
            $averagePrice = (float) ($entry->average_fill_price ?? $entry->limit_price ?? 0.0);
        }

        $openedAt = null;
        $brokerOpenedAt = data_get($position, 'opened_at');
        if (is_string($brokerOpenedAt) && $brokerOpenedAt !== '') {
            try {
                $openedAt = Carbon::parse($brokerOpenedAt);
            } catch (\Throwable) {
                $openedAt = null;
            }
        }
        $openedAt ??= $entry?->filled_at ?? $entry?->created_at;

        return [
            'option_id' => $optionId,
            'chain_id' => data_get($position, 'chain_id') ?? data_get($position, 'option.chain_id'),
            'underlying_symbol' => $symbol,
            'underlying_type' => data_get($position, 'underlying_type') ?? 'equity',
            'option_type' => in_array($optionType, ['call', 'put'], true) ? $optionType : null,
            'expiration_date' => $expiration,
            'strike_price' => $strike,
            'quantity' => (int) floor($quantity),
            'average_price' => $averagePrice > 0.0 ? $averagePrice : null,
            'opened_at' => $openedAt?->toIso8601String(),
            'held_minutes' => $openedAt instanceof Carbon ? round(abs($openedAt->diffInSeconds(now())) / 60, 2) : null,
            'tradability' => data_get($position, 'tradability') ?? 'tradable',
            'state' => data_get($position, 'state') ?? 'active',
        ];
    }

    private function positionAveragePrice(array $position): float
    {
        $value = data_get($position, 'average_price')
            ?? data_get($position, 'average_open_price')
            ?? data_get($position, 'average_cost')
            ?? data_get($position, 'avg_price');

        if (! is_numeric($value)) {
            return 0.0;
        }

        $price = (float) $value;

        return $price > 20.0 ? round($price / 100.0, 8) : $price;
    }

    private function exitSignal(array $position, ?array $quote, array $producer): array
    {
        if ($quote === null || ($quote['stale'] ?? true) === true || ($quote['bid'] ?? null) === null) {
            return [
                'verdict' => 'BLOCK',
                'reason' => 'exit_quote_stale_or_incomplete',
                'position' => $position,
                'quote' => $quote,
            ];
        }

        $average = $this->positiveOrNull($position['average_price'] ?? null);
        if ($average === null) {
            return [
                'verdict' => 'BLOCK',
                'reason' => 'entry_price_unverified',
                'position' => $position,
                'quote' => $quote,
            ];
        }

        $bid = (float) $quote['bid'];
        $pnlBps = round(($bid - $average) / $average * 10000, 2);
        $heldMinutes = is_numeric($position['held_minutes'] ?? null) ? (float) $position['held_minutes'] : null;
        $profitTargetBps = (float) ($producer['exit_profit_target_bps'] ?? 1200.0);
        $stopLossBps = (float) ($producer['exit_stop_loss_bps'] ?? 2000.0);
        $maxHoldMinutes = (float) ($producer['exit_max_hold_minutes'] ?? 15.0);
        $maxHoldMinPnlBps = (float) ($producer['exit_max_hold_min_pnl_bps'] ?? 0.0);

        $reason = null;
        if ($pnlBps >= $profitTargetBps) {
            $reason = 'profit_target_reached';
        } elseif ($pnlBps <= -$stopLossBps) {
            $reason = 'stop_loss_reached';
        } elseif ($heldMinutes !== null && $heldMinutes >= $maxHoldMinutes && $pnlBps >= $maxHoldMinPnlBps) {
            $reason = 'max_hold_reached';
        } elseif ($heldMinutes !== null && $heldMinutes >= $maxHoldMinutes) {
            $reason = 'max_hold_waiting_for_min_pnl';
        }

        return [
            'verdict' => in_array($reason, ['profit_target_reached', 'stop_loss_reached', 'max_hold_reached'], true) ? 'ALLOW' : 'BLOCK',
            'reason' => $reason ?? 'exit_thresholds_not_met',
            'option_id' => $position['option_id'],
            'underlying_symbol' => $position['underlying_symbol'],
            'average_price' => $average,
            'bid' => $bid,
            'pnl_bps' => $pnlBps,
            'held_minutes' => $heldMinutes,
            'profit_target_bps' => $profitTargetBps,
            'stop_loss_bps' => $stopLossBps,
            'max_hold_minutes' => $maxHoldMinutes,
            'max_hold_min_pnl_bps' => $maxHoldMinPnlBps,
        ];
    }

    private function exitSignalPayload(array $exitSignal): array
    {
        return [
            'verdict' => $exitSignal['verdict'] ?? 'BLOCK',
            'reason' => 'exit_'.$exitSignal['reason'],
            'exit_signal' => $exitSignal,
        ];
    }

    private function momentumSignal(string $symbol, array $underlying, array $barsPayload, array $producer): array
    {
        $configuredSide = (string) ($producer['option_type'] ?? 'auto');
        $side = in_array($configuredSide, ['call', 'put'], true) ? $configuredSide : 'auto';
        $lookback = max(3, (int) ($producer['momentum_lookback_bars'] ?? 10));
        $minReturnBps = (float) ($producer['momentum_min_return_bps'] ?? 3.0);
        $minVolumeExpansion = (float) ($producer['momentum_min_volume_expansion'] ?? 0.75);
        $maxSpreadBps = (float) ($producer['underlying_max_spread_bps'] ?? 5.0);
        $barFreshSeconds = max(1, (int) ($producer['momentum_bar_fresh_seconds'] ?? 90));

        $bid = $this->positiveOrNull($underlying['bid'] ?? null);
        $ask = $this->positiveOrNull($underlying['ask'] ?? null);
        $mid = ($bid !== null && $ask !== null) ? ($bid + $ask) / 2 : null;
        $spreadBps = ($bid !== null && $ask !== null && $mid !== null && $mid > 0.0)
            ? round(($ask - $bid) / $mid * 10000, 2)
            : null;

        if ($spreadBps === null || $spreadBps > $maxSpreadBps) {
            return [
                'verdict' => 'BLOCK',
                'reason' => 'underlying_spread_above_max',
                'symbol' => strtoupper($symbol),
                'side' => $side,
                'option_type' => null,
                'spread_bps' => $spreadBps,
                'max_spread_bps' => $maxSpreadBps,
            ];
        }

        $bars = array_values(array_filter((array) ($barsPayload['bars'] ?? []), function (array $bar): bool {
            return is_numeric($bar['close'] ?? null) && is_numeric($bar['volume'] ?? null);
        }));

        if (count($bars) < $lookback) {
            return [
                'verdict' => 'BLOCK',
                'reason' => 'insufficient_momentum_bars',
                'symbol' => strtoupper($symbol),
                'side' => $side,
                'option_type' => null,
                'bars_seen' => count($bars),
                'bars_required' => $lookback,
            ];
        }

        $bars = array_slice($bars, -$lookback);
        $latest = $bars[array_key_last($bars)];
        if (($latest['age_seconds'] ?? null) === null || (float) $latest['age_seconds'] > $barFreshSeconds) {
            return [
                'verdict' => 'BLOCK',
                'reason' => 'latest_momentum_bar_stale',
                'symbol' => strtoupper($symbol),
                'side' => $side,
                'option_type' => null,
                'latest_bar_age_seconds' => $latest['age_seconds'] ?? null,
                'momentum_bar_fresh_seconds' => $barFreshSeconds,
            ];
        }

        $firstClose = (float) $bars[0]['close'];
        $latestClose = (float) $latest['close'];
        $slopeIndex = max(0, count($bars) - 4);
        $slopeBase = (float) $bars[$slopeIndex]['close'];
        if ($firstClose <= 0.0 || $slopeBase <= 0.0) {
            return [
                'verdict' => 'BLOCK',
                'reason' => 'invalid_momentum_bar_price',
                'symbol' => strtoupper($symbol),
                'side' => $side,
                'option_type' => null,
            ];
        }

        $returnBps = round(($latestClose - $firstClose) / $firstClose * 10000, 2);
        $slopeBps = round(($latestClose - $slopeBase) / $slopeBase * 10000, 2);
        $previousVolumes = array_map(fn (array $bar): float => (float) $bar['volume'], array_slice($bars, 0, -1));
        $avgPreviousVolume = count($previousVolumes) > 0 ? array_sum($previousVolumes) / count($previousVolumes) : 0.0;
        $latestVolume = (float) $latest['volume'];
        $volumeExpansion = $avgPreviousVolume > 0.0 ? round($latestVolume / $avgPreviousVolume, 3) : null;
        $optionType = null;
        if ($side === 'put') {
            $directionOk = $returnBps <= -$minReturnBps && $slopeBps < 0.0;
            $optionType = $directionOk ? 'put' : null;
        } elseif ($side === 'call') {
            $directionOk = $returnBps >= $minReturnBps && $slopeBps > 0.0;
            $optionType = $directionOk ? 'call' : null;
        } else {
            $callOk = $returnBps >= $minReturnBps && $slopeBps > 0.0;
            $putOk = $returnBps <= -$minReturnBps && $slopeBps < 0.0;
            $directionOk = $callOk || $putOk;
            $optionType = $callOk ? 'call' : ($putOk ? 'put' : null);
        }
        $volumeOk = $volumeExpansion !== null && $volumeExpansion >= $minVolumeExpansion;

        return [
            'verdict' => ($directionOk && $volumeOk) ? 'ALLOW' : 'BLOCK',
            'reason' => $directionOk ? ($volumeOk ? 'momentum_confirmed' : 'volume_expansion_below_min') : 'directional_momentum_below_min',
            'symbol' => strtoupper($symbol),
            'side' => $side,
            'option_type' => $optionType,
            'timeframe' => $barsPayload['timeframe'] ?? null,
            'lookback_bars' => $lookback,
            'first_close' => $firstClose,
            'latest_close' => $latestClose,
            'return_bps' => $returnBps,
            'slope_bps' => $slopeBps,
            'min_return_bps' => $minReturnBps,
            'latest_volume' => $latestVolume,
            'avg_previous_volume' => round($avgPreviousVolume, 2),
            'volume_expansion' => $volumeExpansion,
            'min_volume_expansion' => $minVolumeExpansion,
            'underlying_spread_bps' => $spreadBps,
            'underlying_max_spread_bps' => $maxSpreadBps,
            'latest_bar_ts' => $latest['source_ts'] ?? null,
            'latest_bar_age_seconds' => $latest['age_seconds'] ?? null,
            'momentum_bar_fresh_seconds' => $barFreshSeconds,
        ];
    }

    /** @return list<string> */
    private function symbols(array $producer, AlpacaMarketDataClient $data, MomentumUniverseSelector $selector): array
    {
        $override = (string) $this->option('symbols');
        $raw = $override !== '' ? $override : implode(',', (array) ($producer['symbols'] ?? ['SPY']));

        $symbols = array_values(array_unique(array_filter(array_map(
            fn (string $symbol): string => strtoupper(trim($symbol)),
            explode(',', $raw),
        ))));

        if ($override !== '' || ($producer['universe_source'] ?? 'static') !== 'dynamic') {
            return $symbols;
        }

        return $selector->select($data, $symbols, $producer);
    }

    private function hasPendingReviewedIntent(string $symbol): bool
    {
        return RobinhoodTradeJournal::query()
            ->where('asset_class', 'option')
            ->where('underlying_symbol', strtoupper($symbol))
            ->whereIn('status', ['reviewed', 'autopilot_claimed'])
            ->exists();
    }

    private function hasOpenJournalForOption(string $optionId): bool
    {
        return RobinhoodTradeJournal::query()
            ->where('asset_class', 'option')
            ->where('option_id', $optionId)
            ->whereIn('status', ['reviewed', 'autopilot_claimed', 'submitted', 'open', 'partially_filled'])
            ->exists();
    }

    private function hasPendingCloseIntent(string $optionId): bool
    {
        return RobinhoodTradeJournal::query()
            ->where('asset_class', 'option')
            ->where('option_id', $optionId)
            ->where('side', 'sell')
            ->where('position_effect', 'close')
            ->whereIn('status', ['reviewed', 'autopilot_claimed', 'submitted', 'open', 'partially_filled'])
            ->exists();
    }

    private function allocationScore(OptionOrderIntent $intent, array $momentum, array $selection, array $producer): array
    {
        $capitalRequired = max(0.0, (float) $intent->estimatedDebit());
        $capitalLockDays = max(0.25, (float) ($producer['allocation_capital_lock_days'] ?? 1.0));
        $signalReturnBps = abs((float) ($momentum['return_bps'] ?? 0.0));
        $signalSlopeBps = abs((float) ($momentum['slope_bps'] ?? 0.0));
        $volumeExpansion = max(0.1, (float) ($momentum['volume_expansion'] ?? 1.0));
        $spreadBps = max(0.0, (float) data_get($selection, 'quote.spread_bps', 0.0));
        $qualityBps = max(0.0, (($signalReturnBps + $signalSlopeBps) * $volumeExpansion) - ($spreadBps / 10.0));
        $multiplier = max(0.01, (float) ($producer['allocation_expected_gain_multiplier'] ?? 1.0));
        $profitTargetBps = max(1.0, (float) ($producer['exit_profit_target_bps'] ?? 1200.0));
        $expectedReturnBps = min($profitTargetBps, max(1.0, $qualityBps * $multiplier));
        $expectedGain = $capitalRequired * $expectedReturnBps / 10000.0;
        $capitalDays = max(0.01, $capitalRequired * $capitalLockDays);

        return [
            'method' => 'capital_velocity_v1',
            'capital_required' => round($capitalRequired, 2),
            'capital_lock_days' => round($capitalLockDays, 4),
            'signal_return_bps' => round($signalReturnBps, 2),
            'signal_slope_bps' => round($signalSlopeBps, 2),
            'volume_expansion' => round($volumeExpansion, 3),
            'spread_penalty_bps' => round($spreadBps / 10.0, 2),
            'expected_return_bps' => round($expectedReturnBps, 2),
            'expected_gain' => round($expectedGain, 4),
            'capital_velocity_score' => round($expectedGain / $capitalDays, 6),
        ];
    }

    private function recentEntryForSymbol(string $symbol, array $producer): ?array
    {
        $cooldownMinutes = max(0, (int) ($producer['entry_symbol_cooldown_minutes'] ?? 0));
        if ($cooldownMinutes <= 0) {
            return null;
        }

        $row = RobinhoodTradeJournal::query()
            ->where('asset_class', 'option')
            ->where('underlying_symbol', strtoupper($symbol))
            ->where('side', 'buy')
            ->where('position_effect', 'open')
            ->whereIn('status', ['reviewed', 'autopilot_claimed', 'submitted', 'open', 'partially_filled', 'filled'])
            ->where('created_at', '>=', now()->subMinutes($cooldownMinutes))
            ->latest('created_at')
            ->latest('id')
            ->first(['id', 'status', 'option_id', 'dollar_amount', 'created_at', 'filled_at']);

        if (! $row instanceof RobinhoodTradeJournal) {
            return null;
        }

        return [
            'id' => $row->id,
            'status' => $row->status,
            'option_id' => $row->option_id,
            'dollar_amount' => $row->dollar_amount !== null ? (float) $row->dollar_amount : null,
            'created_at' => $row->created_at?->toIso8601String(),
            'filled_at' => $row->filled_at?->toIso8601String(),
            'cooldown_minutes' => $cooldownMinutes,
        ];
    }

    private function selectContract(RobinhoodMcpClient $client, array $cfg, string $symbol, float $underlyingAsk, ?string $optionType = null, array $brokerContext = [], bool $capitalIndependent = false): array
    {
        $options = (array) ($cfg['options'] ?? []);
        $producer = (array) ($options['producer'] ?? []);
        // When armed, selection sizes to STRATEGY caps only — capital must not
        // gate evaluation. The capital-aware caps are still recorded for
        // visibility (they gate execution at the caps rail, surfaced as
        // execution deferral).
        $premiumCaps = $this->producerPremiumCaps($options, $brokerContext, $symbol, capitalIndependent: $capitalIndependent);
        $maxPremium = $premiumCaps === [] ? null : round((float) min($premiumCaps), 4);
        $maxPremiumLimiter = $premiumCaps === []
            ? null
            : (string) array_key_first($premiumCaps);

        $selectionConstraints = [
            'max_premium' => $maxPremium,
            'contract_multiplier' => (float) ($options['contract_multiplier'] ?? 100.0),
            'capital_independent_selection' => $capitalIndependent,
            'buying_power' => $brokerContext['buying_power'] ?? null,
            'settled_cash' => $brokerContext['settled_cash'] ?? null,
            'options_open_debit' => $brokerContext['options_open_debit'] ?? null,
            'options_spent_today_debit' => $brokerContext['options_spent_today_debit'] ?? null,
            'daily_gross_entry_debit' => $options['daily_gross_entry_debit'] ?? null,
            'underlying_open_debit' => data_get($brokerContext, 'underlying_open_debit.'.strtoupper($symbol)),
            'buying_power_reserve_debit' => $producer['buying_power_reserve_debit'] ?? null,
        ];
        $selectionConstraints['max_premium_limiter'] = $maxPremiumLimiter;
        $selectionConstraints['premium_caps'] = $premiumCaps;
        // What capital would constrain at execution (informational only).
        $selectionConstraints['execution_premium_caps'] = $this->producerPremiumCaps($options, $brokerContext, $symbol, capitalIndependent: false);

        if ($maxPremium === null) {
            return ['contract' => null, 'quote' => null, 'blockers' => ['producer_max_premium_unconfigured'], 'constraints' => $selectionConstraints];
        }

        if ($maxPremium <= 0.0) {
            $blockers = ['producer_max_premium_exhausted'];
            if ($maxPremiumLimiter !== null && str_starts_with($maxPremiumLimiter, 'remaining_')) {
                $blockers[] = $maxPremiumLimiter.'_limited';
            }

            return ['contract' => null, 'quote' => null, 'blockers' => $blockers, 'constraints' => $selectionConstraints];
        }

        $chains = PortfolioReader::listFrom($client->getOptionChains($symbol), ['chains']);
        $expiration = $this->chooseExpiration($chains, (int) ($producer['expiration_min_days'] ?? 1));
        if ($expiration === null) {
            return ['contract' => null, 'quote' => null, 'blockers' => ['no_eligible_expiration'], 'constraints' => $selectionConstraints];
        }

        $contracts = $this->chainContracts(
            $client,
            $symbol,
            $expiration,
            (string) ($optionType ?: $producer['option_type'] ?? 'call'),
            max(1, (int) ($producer['max_chain_pages'] ?? 3)),
        );

        if ($contracts === []) {
            return ['contract' => null, 'quote' => null, 'blockers' => ['no_contracts_listed'], 'constraints' => $selectionConstraints];
        }

        $side = (string) ($optionType ?: $producer['option_type'] ?? 'call');
        $contracts = array_values(array_filter($contracts, function (array $contract) use ($side, $underlyingAsk): bool {
            $strike = (float) ($contract['strike_price'] ?? 0);

            return $side === 'put' ? $strike <= $underlyingAsk : $strike >= $underlyingAsk;
        }));

        usort($contracts, fn (array $a, array $b): int => abs((float) $a['strike_price'] - $underlyingAsk) <=> abs((float) $b['strike_price'] - $underlyingAsk));

        $best = null;
        $bestQuote = null;
        $blockers = [];
        $quoteChunkLimit = max(1, (int) ($producer['quote_chunk_limit'] ?? 1));
        foreach (array_slice(array_chunk($contracts, 20), 0, $quoteChunkLimit) as $chunk) {
            $ids = array_values(array_map(fn (array $contract): string => (string) $contract['id'], $chunk));
            $quotes = PortfolioReader::listFrom($client->getOptionQuotes($ids), ['results']);
            $quotesById = [];
            foreach ($quotes as $row) {
                $quote = $this->normalizeQuote($row['quote'] ?? null, $options);
                if ($quote !== null) {
                    $quotesById[(string) $quote['option_id']] = $quote;
                }
            }

            foreach ($chunk as $contract) {
                $quote = $quotesById[(string) ($contract['id'] ?? '')] ?? null;
                if ($quote === null) {
                    $blockers[] = 'quote_unavailable';

                    continue;
                }
                if (($quote['stale'] ?? true) === true) {
                    $blockers[] = 'quote_stale';

                    continue;
                }
                if (($quote['bid'] ?? null) === null || ($quote['ask'] ?? null) === null) {
                    $blockers[] = 'bid_or_ask_missing';

                    continue;
                }
                if ((float) $quote['ask'] > $maxPremium) {
                    $blockers[] = 'ask_above_producer_max';
                    if ($maxPremiumLimiter !== null && str_starts_with($maxPremiumLimiter, 'remaining_')) {
                        $blockers[] = $maxPremiumLimiter.'_limited';
                    }

                    continue;
                }
                if (($quote['spread_bps'] ?? null) !== null && (float) $quote['spread_bps'] > (float) ($options['max_spread_bps'] ?? 1500)) {
                    $blockers[] = 'spread_above_max';

                    continue;
                }

                if ($bestQuote === null || (float) $quote['ask'] > (float) $bestQuote['ask']) {
                    $best = $contract;
                    $bestQuote = $quote;
                }
            }

            if ($best !== null) {
                break;
            }
        }

        if ($best === null || $bestQuote === null) {
            if (count($contracts) > $quoteChunkLimit * 20) {
                $blockers[] = 'quote_chunk_limit_reached';
            }

            return ['contract' => null, 'quote' => null, 'blockers' => array_values(array_unique($blockers)), 'constraints' => $selectionConstraints];
        }

        return [
            'contract' => [
                'option_id' => (string) $best['id'],
                'chain_id' => $best['chain_id'] ?? null,
                'chain_symbol' => $best['chain_symbol'] ?? strtoupper($symbol),
                'underlying_type' => $best['underlying_type'] ?? 'equity',
                'expiration_date' => $best['expiration_date'] ?? $expiration,
                'strike_price' => (float) $best['strike_price'],
                'type' => $best['type'] ?? ($producer['option_type'] ?? 'call'),
                'state' => $best['state'] ?? null,
                'tradability' => $best['tradability'] ?? null,
            ],
            'quote' => $bestQuote,
            'blockers' => [],
            'constraints' => $selectionConstraints,
        ];
    }

    private function producerMaxPremium(array $options, array $brokerContext, string $symbol): float
    {
        $premiumCaps = $this->producerPremiumCaps($options, $brokerContext, $symbol);

        return $premiumCaps === [] ? 0.0 : round((float) min($premiumCaps), 4);
    }

    /** @return array<string, float> */
    /**
     * @param  bool  $capitalIndependent  when true, return ONLY the strategy/
     *   config premium caps (max contract ask, per-play debit) and omit every
     *   account-capital cap (buying power, settled cash, reserve, remaining
     *   debits). Candidate selection uses this so evaluation never depends on
     *   available capital; capital gates execution at the caps rail instead.
     */
    private function producerPremiumCaps(array $options, array $brokerContext, string $symbol, bool $capitalIndependent = false): array
    {
        $producer = (array) ($options['producer'] ?? []);
        $multiplier = (float) ($options['contract_multiplier'] ?? 100.0);
        if ($multiplier <= 0.0) {
            return [];
        }

        // Strategy/config caps always bound a candidate — they describe the
        // play we would make, not whether we can currently fund it.
        $caps = [
            'max_contract_ask' => (float) ($options['max_contract_ask'] ?? 5.0),
            'per_play_max_debit' => (float) ($options['per_play_max_debit'] ?? 0.0) / $multiplier,
        ];

        if ($capitalIndependent) {
            $positiveCaps = array_filter($caps, fn (float $cap): bool => $cap >= 0.0);
            asort($positiveCaps);

            return array_map(fn (float $cap): float => round($cap, 4), $positiveCaps);
        }

        foreach (['buying_power', 'settled_cash'] as $key) {
            if (isset($brokerContext[$key]) && is_numeric($brokerContext[$key])) {
                $caps[$key] = max(0.0, (float) $brokerContext[$key]) / $multiplier;
            }
        }

        if (isset($brokerContext['buying_power']) && is_numeric($brokerContext['buying_power'])) {
            $reserve = max(0.0, (float) ($producer['buying_power_reserve_debit'] ?? 0.0));
            if ($reserve > 0.0) {
                $caps['remaining_buying_power_after_reserve'] = max(0.0, (float) $brokerContext['buying_power'] - $reserve) / $multiplier;
            }
        }

        if (isset($brokerContext['options_open_debit']) && is_numeric($brokerContext['options_open_debit'])) {
            $caps['remaining_open_day_debit'] = max(0.0, (float) ($options['per_day_max_debit'] ?? 0.0) - (float) $brokerContext['options_open_debit']) / $multiplier;
        }

        if (isset($brokerContext['options_spent_today_debit']) && is_numeric($brokerContext['options_spent_today_debit'])) {
            $caps['remaining_daily_gross_entry_debit'] = max(0.0, (float) ($options['daily_gross_entry_debit'] ?? 0.0) - (float) $brokerContext['options_spent_today_debit']) / $multiplier;
        }

        $underlyingDebits = (array) ($brokerContext['underlying_open_debit'] ?? []);
        if (isset($underlyingDebits[strtoupper($symbol)]) && is_numeric($underlyingDebits[strtoupper($symbol)])) {
            $caps['remaining_underlying_debit'] = max(0.0, (float) ($options['per_underlying_max_debit'] ?? 0.0) - (float) $underlyingDebits[strtoupper($symbol)]) / $multiplier;
        }

        $positiveCaps = array_filter($caps, fn (float $cap): bool => $cap >= 0.0);
        asort($positiveCaps);

        return array_map(fn (float $cap): float => round($cap, 4), $positiveCaps);
    }

    private function chooseExpiration(array $chains, int $minDays): ?string
    {
        $dates = [];
        foreach ($chains as $chain) {
            foreach ((array) ($chain['expiration_dates'] ?? []) as $date) {
                $expiration = Carbon::parse((string) $date)->startOfDay();
                if ($expiration->lessThan(now()->startOfDay()->addDays($minDays))) {
                    continue;
                }
                $dates[] = $expiration->toDateString();
            }
        }

        sort($dates);

        return $dates[0] ?? null;
    }

    private function chainContracts(RobinhoodMcpClient $client, string $symbol, string $expiration, string $type, int $maxPages): array
    {
        $contracts = [];
        $cursor = null;
        for ($page = 0; $page < $maxPages; $page++) {
            $payload = $client->getOptionInstruments(array_filter([
                'chain_symbol' => strtoupper($symbol),
                'expiration_dates' => $expiration,
                'type' => $type,
                'state' => 'active',
                'tradability' => 'tradable',
                'cursor' => $cursor,
            ], fn ($value): bool => $value !== null));

            $contracts = array_merge($contracts, PortfolioReader::listFrom($payload, ['instruments']));
            $next = data_get($payload, 'data.next') ?? data_get($payload, 'next');
            $cursor = is_string($next) ? $this->cursorFromNext($next) : null;
            if ($cursor === null) {
                break;
            }
        }

        return $contracts;
    }

    private function cursorFromNext(string $next): ?string
    {
        $query = parse_url($next, PHP_URL_QUERY);
        if (! is_string($query)) {
            return null;
        }

        parse_str($query, $params);
        $cursor = $params['cursor'] ?? null;

        return is_string($cursor) && $cursor !== '' ? $cursor : null;
    }

    private function normalizeQuote(mixed $quote, array $options): ?array
    {
        if (! is_array($quote)) {
            return null;
        }

        $bid = $this->positiveOrNull($quote['bid_price'] ?? null);
        $ask = $this->positiveOrNull($quote['ask_price'] ?? null);
        $mid = ($bid !== null && $ask !== null) ? round(($bid + $ask) / 2, 4) : null;
        $spread = ($bid !== null && $ask !== null) ? round($ask - $bid, 4) : null;
        $spreadBps = ($spread !== null && $mid !== null && $mid > 0) ? round($spread / $mid * 10000, 2) : null;
        $updatedAt = $quote['updated_at'] ?? null;
        $ageSeconds = null;
        $stale = true;
        if ($updatedAt !== null) {
            $ageSeconds = round(abs(Carbon::parse((string) $updatedAt)->diffInMilliseconds(now())) / 1000, 3);
            $stale = $ageSeconds > (int) ($options['min_quote_fresh_seconds'] ?? 10);
        }

        return [
            'option_id' => (string) ($quote['instrument_id'] ?? ''),
            'bid' => $bid,
            'ask' => $ask,
            'mid' => $mid,
            'mark' => $this->positiveOrNull($quote['mark_price'] ?? null),
            'spread' => $spread,
            'spread_bps' => $spreadBps,
            'bid_size' => $quote['bid_size'] ?? null,
            'ask_size' => $quote['ask_size'] ?? null,
            'delta' => isset($quote['delta']) ? (float) $quote['delta'] : null,
            'implied_volatility' => isset($quote['implied_volatility']) ? (float) $quote['implied_volatility'] : null,
            'open_interest' => $quote['open_interest'] ?? null,
            'volume' => $quote['volume'] ?? null,
            'updated_at' => $updatedAt,
            'age_seconds' => $ageSeconds,
            'stale' => $stale,
            'staleness_gate_seconds' => (int) ($options['min_quote_fresh_seconds'] ?? 10),
        ];
    }

    private function positiveOrNull(mixed $value): ?float
    {
        if (! is_numeric($value) || (float) $value <= 0.0) {
            return null;
        }

        return (float) $value;
    }

    private function journalIntent(OptionOrderIntent $intent, string $status, array $underlying, array $momentum, array $selection, array $review, array $verdict): RobinhoodTradeJournal
    {
        return RobinhoodTradeJournal::query()->create([
            'account_number' => (string) config('harness.agentic_account'),
            'asset_class' => 'option',
            'symbol' => strtoupper($intent->underlyingSymbol),
            'underlying_symbol' => strtoupper($intent->underlyingSymbol),
            'option_id' => $intent->optionId,
            'chain_id' => $intent->chainId,
            'option_type' => $intent->optionType,
            'expiration_date' => $intent->expirationDate,
            'strike_price' => $intent->strikePrice,
            'position_effect' => $intent->positionEffect,
            'status' => $status,
            'intent' => $intent->isOpening()
                ? ($intent->optionType === 'call' ? 'long_call' : 'long_put')
                : ($intent->optionType === 'call' ? 'close_long_call' : 'close_long_put'),
            'side' => $intent->side,
            'order_type' => $intent->orderType,
            'time_in_force' => $intent->timeInForce,
            'market_hours' => $intent->marketHours,
            'quantity' => $intent->quantity,
            'limit_price' => $intent->limitPrice,
            'dollar_amount' => $intent->estimatedDebit(),
            'strategy_label' => 'theta_option_single_leg',
            'structure_class' => 'single_leg_long',
            'reviewed_at' => $status === 'reviewed' ? now() : null,
            'review_payload' => $review,
            'scanner_payload' => array_merge([
                'intent_source' => $intent->source,
                'underlying_quote' => $underlying,
                'selection' => $selection,
            ], $this->scannerSignalPayload($momentum)),
            'notes' => ['rail_verdict' => $verdict],
        ]);
    }

    private function scannerSignalPayload(array $signal): array
    {
        if (isset($signal['exit_signal'])) {
            return ['exit_signal' => $signal['exit_signal']];
        }

        return ['momentum_signal' => $signal];
    }

    private function recentProducerBlockExists(string $symbol, array $producer, string $status = 'risk_aborted'): bool
    {
        $minutes = max(0, (int) ($producer['block_cooldown_minutes'] ?? 5));
        if ($minutes === 0) {
            return false;
        }

        return RobinhoodTradeJournal::query()
            ->where('asset_class', 'option')
            ->where('underlying_symbol', strtoupper($symbol))
            ->where('status', $status)
            ->where('created_at', '>=', now()->subMinutes($minutes))
            ->where('scanner_payload->intent_source', 'theta:option-intent-producer')
            ->exists();
    }

    /**
     * Record a producer outcome row with cooldown-based anti-spam. $status is
     * 'risk_aborted' for genuine blocks and 'execution_deferred' for valid
     * setups held back only by capital (so the cockpit can distinguish them).
     */
    private function recordProducerBlock(string $symbol, string $reason, array $payload = [], array $producer = [], string $status = 'risk_aborted'): ?RobinhoodTradeJournal
    {
        if ($this->recentProducerBlockExists($symbol, $producer, $status)) {
            return $this->recordSuppressedProducerBlock($symbol, $reason, $payload, $status);
        }

        return RobinhoodTradeJournal::query()->create([
            'account_number' => (string) config('harness.agentic_account'),
            'asset_class' => 'option',
            'symbol' => strtoupper($symbol),
            'underlying_symbol' => strtoupper($symbol),
            'status' => $status,
            'intent' => 'producer_scan',
            'strategy_label' => 'theta_option_single_leg',
            'scanner_payload' => array_merge(['intent_source' => 'theta:option-intent-producer'], $payload),
            'notes' => [
                'producer_block' => [
                    'reason' => $reason,
                    'blocked_at' => now()->toISOString(),
                ],
            ],
        ]);
    }

    private function recordSuppressedProducerBlock(string $symbol, string $reason, array $payload = [], string $blockedStatus = 'risk_aborted'): RobinhoodTradeJournal
    {
        $recent = RobinhoodTradeJournal::query()
            ->where('asset_class', 'option')
            ->where('underlying_symbol', strtoupper($symbol))
            ->where('status', $blockedStatus)
            ->where('scanner_payload->intent_source', 'theta:option-intent-producer')
            ->latest('id')
            ->first(['id', 'created_at', 'notes']);

        return RobinhoodTradeJournal::query()->create([
            'account_number' => (string) config('harness.agentic_account'),
            'asset_class' => 'option',
            'symbol' => strtoupper($symbol),
            'underlying_symbol' => strtoupper($symbol),
            'status' => 'scan_suppressed',
            'intent' => 'producer_scan',
            'strategy_label' => 'theta_option_single_leg',
            'scanner_payload' => array_merge(['intent_source' => 'theta:option-intent-producer'], $payload),
            'notes' => [
                'producer_block' => [
                    'reason' => $reason,
                    'blocked_at' => now()->toISOString(),
                    'suppressed_by_recent_block' => $recent?->id,
                    'suppressed_by_recent_block_created_at' => $recent?->created_at?->toISOString(),
                    'suppressed_by_recent_block_reason' => data_get($recent?->notes, 'producer_block.reason'),
                ],
            ],
        ]);
    }
}
