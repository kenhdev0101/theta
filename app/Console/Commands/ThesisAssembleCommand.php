<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\AssemblesHarnessConfig;
use App\MarketData\AlpacaMarketDataClient;
use App\Models\RobinhoodTradeJournal;
use App\Models\ThesisIntent;
use App\Robinhood\Mcp\OAuthTokenStore;
use App\Robinhood\Mcp\RobinhoodMcpClient;
use App\Robinhood\Options\OptionContractResolver;
use App\Robinhood\Telemetry\JsonlRecorder;
use App\Robinhood\Thesis\ThesisContractSelector;
use App\Robinhood\Thesis\ThesisEntryPricer;
use App\Robinhood\Thesis\ThesisLaneConfig;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Pre-open assembly for the thesis-position lane (S3 of
 * docs/thesis-position-lane.md).
 *
 * The operator precommits a thesis while calm; this command turns each armed
 * thesis into one concrete slate play shortly before the open — expiration
 * inside the DTE band, strike nearest live spot, entry limit derived from the
 * live option quote. Strikes and limits are NEVER authored ahead of time:
 * pre-derived prices die on overnight gaps, which is the whole reason this
 * runs at T-45 instead of at authoring time.
 *
 * APPROVAL-FIRST BY CONSTRUCTION. This command never reads `harness.mode`.
 * Activation of a ThesisIntent *is* the operator's standing approval for this
 * lane, so an `auto`/`approval` mode flag has no meaning here and must never
 * become a hidden second switch. The lane's own `harness.thesis.enabled` flag
 * plus the kill switch are the arm/disarm surface.
 *
 * JOURNAL STATUS. Assembly writes `thesis_assembled`, not `reviewed`.
 * `reviewed` is the scalp autopilot's default eligible status and that command
 * is scheduled every minute all session, filtered only by
 * `asset_class = option` — a thesis play written as `reviewed` would be picked
 * up and placed by the scalp lane, outside the thesis entry window and without
 * the chase cap. A lane-private status keeps the two claim sets disjoint and
 * follows the existing precedent of `autopilot_claimed` / `execution_deferred`
 * / `scan_suppressed`. See ThesisLaneConfig.
 *
 * QUOTE FRESHNESS. At T-45 there is no live option quote — options do not
 * trade pre-open. Assembly therefore records the option quote's age and
 * staleness rather than hard-blocking on the 10-second gate, and anchors the
 * chase ladder on the mid it saw. A stale anchor is stamped
 * `assembly_stale_quote` so the executor re-anchors once against the first
 * live quote. Freshness where it moves money is unchanged: the rails still
 * enforce the 10-second option-quote gate at placement.
 */
class ThesisAssembleCommand extends Command
{
    use AssemblesHarnessConfig;

    protected $signature = 'theta:thesis-assemble
        {--dry-run : Evaluate and print the decision per thesis WITHOUT writing any journal row. Read-only for the journal, but it still makes live broker reads, so every arm gate — including the kill switch — applies.}';

    protected $description = 'Derive today\'s thesis-lane slate plays against live quotes ahead of the open';

    public function handle(): int
    {
        $cfg = $this->assembleConfig();
        $lane = ThesisLaneConfig::lane($cfg);
        $recorder = JsonlRecorder::fromConfig($cfg);
        $dryRun = (bool) $this->option('dry-run');

        if (! (bool) ($lane['enabled'] ?? false)) {
            $this->warn('thesis assembly disarmed: thesis lane disabled');

            return self::SUCCESS;
        }

        // --dry-run writes no journal rows, but it does hit the live broker
        // (chains, instruments, quotes). An engaged kill switch disarms the
        // whole lane, reads included — fail-closed consistency beats an
        // off-hours preview convenience.
        if ((bool) ($cfg['kill_switch'] ?? true)) {
            $this->warn('thesis assembly disarmed: kill switch engaged');

            return self::SUCCESS;
        }

        if (! OAuthTokenStore::fromConfig($cfg)->hasToken()) {
            $this->error('blocker: oauth token absent');

            return self::FAILURE;
        }

        /** @var Collection<int, ThesisIntent> $active */
        $active = ThesisIntent::query()
            ->where('status', ThesisIntent::STATUS_ACTIVE)
            ->orderBy('id')
            ->get();

        $theses = $active->filter(static fn (ThesisIntent $intent): bool => $intent->isEnterable())->values();

        if ($theses->isEmpty()) {
            $this->info('thesis assembly idle: no enterable active theses');

            return self::SUCCESS;
        }

        $client = RobinhoodMcpClient::fromConfig($cfg, $recorder);
        $data = AlpacaMarketDataClient::fromConfig($cfg);
        $selector = new ThesisContractSelector($client);

        $maxOpen = ThesisLaneConfig::maxOpenPositions($cfg);
        $openPlays = $this->openThesisPlayCount();
        $assembled = 0;
        $skipped = 0;
        // A dry run does not persist rows, so the per-underlying and
        // concurrency checks would otherwise let the preview promise plays the
        // live run would refuse. Track what the run has already committed to.
        $engagedSymbols = [];

        if ($dryRun) {
            $this->info('DRY RUN — read-only slate preview. No journal rows written, no orders reviewed or placed.');
        }

        foreach ($theses as $thesis) {
            $decision = $this->assembleOne($thesis, $cfg, $lane, $client, $data, $selector, $maxOpen, $openPlays, $dryRun, $engagedSymbols);

            $recorder->record('thesis_assembly', array_merge([
                'event' => $decision['decision'] === 'ASSEMBLED' ? 'assembled' : 'skipped',
                'dry_run' => $dryRun,
                'thesis_intent_id' => $thesis->id,
                'symbol' => strtoupper($thesis->underlying_symbol),
                'direction' => $thesis->direction,
                'structure' => $thesis->structure,
            ], $decision));

            if ($decision['decision'] === 'ASSEMBLED') {
                $assembled++;
                $openPlays++;
                $engagedSymbols[] = strtoupper($thesis->underlying_symbol);
                $this->info(sprintf(
                    'thesis %d %s: %s %s @ %0.2f debit %0.2f%s',
                    $thesis->id,
                    strtoupper($thesis->underlying_symbol),
                    $dryRun ? 'WOULD ASSEMBLE' : 'assembled play '.(string) ($decision['journal_id'] ?? '—'),
                    (string) ($decision['contract_label'] ?? '—'),
                    (float) ($decision['limit_price'] ?? 0.0),
                    (float) ($decision['estimated_debit'] ?? 0.0),
                    ($decision['anchor_source'] ?? '') === 'assembly_stale_quote' ? ' (stale pre-open anchor)' : '',
                ));

                continue;
            }

            $skipped++;
            $this->warn(sprintf(
                'thesis %d %s: skipped — %s',
                $thesis->id,
                strtoupper($thesis->underlying_symbol),
                (string) ($decision['reason'] ?? 'unknown'),
            ));
        }

        $this->info("thesis assembly complete: assembled={$assembled} skipped={$skipped} open_plays={$openPlays}/{$maxOpen}");

        return self::SUCCESS;
    }

    /**
     * One thesis, one decision. Never returns silently: the caller stamps
     * whatever comes back into the thesis_assembly stream.
     *
     * @return array<string,mixed>
     */
    private function assembleOne(
        ThesisIntent $thesis,
        array $cfg,
        array $lane,
        RobinhoodMcpClient $client,
        AlpacaMarketDataClient $data,
        ThesisContractSelector $selector,
        int $maxOpen,
        int $openPlays,
        bool $dryRun,
        array $engagedSymbols,
    ): array {
        $symbol = strtoupper($thesis->underlying_symbol);

        if ($this->hasLivePlay($thesis->id)) {
            return ['decision' => 'SKIP', 'reason' => 'existing_play_open'];
        }

        // Zero is a hard stop, never "unbounded": an unconfigured concurrency
        // cap must not read as permission to open positions.
        if ($maxOpen <= 0) {
            return [
                'decision' => 'SKIP',
                'reason' => 'max_open_positions_unconfigured',
                'max_open_positions' => $maxOpen,
            ];
        }

        if ($openPlays >= $maxOpen) {
            return [
                'decision' => 'SKIP',
                'reason' => 'max_open_positions_reached',
                'open_plays' => $openPlays,
                'max_open_positions' => $maxOpen,
            ];
        }

        // The per-underlying debit rail counts POSITIONS, so two resting
        // entry orders in the same name both pass it and both can fill —
        // twice the precommitment. One live play per underlying, enforced
        // where the second play would be created.
        if (in_array($symbol, $engagedSymbols, true) || $this->hasLivePlayForUnderlying($symbol)) {
            return ['decision' => 'SKIP', 'reason' => 'underlying_already_engaged', 'symbol' => $symbol];
        }

        $perPlayMaxDebit = ThesisLaneConfig::perPlayMaxDebit($cfg, (float) $thesis->max_debit);
        if ($perPlayMaxDebit <= 0.0) {
            return ['decision' => 'SKIP', 'reason' => 'per_play_max_debit_unconfigured'];
        }
        $laneCfg = ThesisLaneConfig::railsConfig($cfg, $perPlayMaxDebit);

        try {
            $underlying = $data->latestQuote($symbol);
        } catch (\Throwable $e) {
            return ['decision' => 'SKIP', 'reason' => 'underlying_quote_blocker: '.$e->getMessage()];
        }

        $spot = $this->spotFromQuote($underlying);
        if ($spot === null) {
            return [
                'decision' => 'SKIP',
                'reason' => 'underlying_quote_unusable',
                'underlying_quote' => $underlying,
            ];
        }

        $optionType = $thesis->structure === ThesisIntent::STRUCTURE_LONG_PUT ? 'put' : 'call';

        // ThesisIntent::activationBlockers() checks the lane rails at
        // AUTHORING time, but dte_floor/dte_ceiling can move afterwards. Clamp
        // here too, or the config is inert on every already-active thesis.
        $band = ThesisLaneConfig::clampDteBand($cfg, (int) $thesis->dte_min, (int) $thesis->dte_max);
        if ($band['empty']) {
            return [
                'decision' => 'SKIP',
                'reason' => 'dte_band_empty_after_lane_clamp',
                'dte_band' => [$thesis->dte_min, $thesis->dte_max],
                'dte_band_clamp' => $band,
            ];
        }

        try {
            $expiration = $selector->chooseExpiration($symbol, $band['dte_min'], $band['dte_max']);
        } catch (\Throwable $e) {
            return ['decision' => 'SKIP', 'reason' => 'chain_read_blocker: '.$e->getMessage()];
        }

        if ($expiration['expiration'] === null) {
            return [
                'decision' => 'SKIP',
                'reason' => (string) ($expiration['reason'] ?? 'no_expiration_in_dte_band'),
                'dte_band' => [$band['dte_min'], $band['dte_max']],
                'dte_band_clamp' => $band,
            ];
        }

        try {
            $strike = $selector->chooseStrike($symbol, (string) $expiration['expiration'], $optionType, $spot);
        } catch (\Throwable $e) {
            return ['decision' => 'SKIP', 'reason' => 'strike_ladder_blocker: '.$e->getMessage()];
        }

        if ($strike['strike'] === null) {
            return [
                'decision' => 'SKIP',
                'reason' => (string) ($strike['reason'] ?? 'no_listed_strikes'),
                'expiration' => $expiration['expiration'],
            ];
        }

        try {
            $resolution = (new OptionContractResolver($client, $laneCfg))->resolveExact(
                $symbol,
                $optionType,
                (string) $expiration['expiration'],
                (float) $strike['strike'],
            );
        } catch (\Throwable $e) {
            return ['decision' => 'SKIP', 'reason' => 'resolver_blocker: '.$e->getMessage()];
        }

        $contract = $resolution['contract'];
        $quote = $resolution['quote'];
        // `quote_stale` is expected at a pre-open assembly and is recorded,
        // not fatal — the executor re-derives against a live quote and the
        // rails still enforce the freshness gate before any money moves.
        $fatal = array_values(array_filter(
            (array) $resolution['blockers'],
            static fn (string $blocker): bool => $blocker !== 'quote_stale',
        ));

        if (! is_array($contract) || ! is_array($quote) || $fatal !== []) {
            return [
                'decision' => 'SKIP',
                'reason' => 'contract_blocked: '.($fatal === [] ? 'contract_or_quote_unresolved' : implode('; ', $fatal)),
                'expiration' => $expiration['expiration'],
                'strike' => $strike['strike'],
                'blockers' => (array) $resolution['blockers'],
            ];
        }

        $priced = ThesisEntryPricer::derive($quote, (float) $thesis->entry_limit_offset_bps);
        if ($priced['limit'] === null || $priced['mid'] === null) {
            return [
                'decision' => 'SKIP',
                'reason' => 'entry_limit_underivable: '.(string) ($priced['reason'] ?? 'unknown'),
                'expiration' => $expiration['expiration'],
                'strike' => $strike['strike'],
                'quote' => $quote,
            ];
        }

        $limit = (float) $priced['limit'];
        $multiplier = (float) ($cfg['options']['contract_multiplier'] ?? 100.0);
        $estimatedDebit = round($limit * $multiplier, 2);
        $askDebit = round((float) $quote['ask'] * $multiplier, 2);

        if ($askDebit > $perPlayMaxDebit) {
            return [
                'decision' => 'SKIP',
                'reason' => 'premium_above_play_cap',
                'ask_debit' => $askDebit,
                'per_play_max_debit' => $perPlayMaxDebit,
            ];
        }

        $anchorSource = ($quote['stale'] ?? true) === true ? 'assembly_stale_quote' : 'assembly_live_quote';
        $contractLabel = sprintf(
            '%s %s %s %s',
            $symbol,
            (string) $contract['expiration_date'],
            rtrim(rtrim(number_format((float) $contract['strike_price'], 4, '.', ''), '0'), '.'),
            strtoupper(substr($optionType, 0, 1)),
        );

        $decision = [
            'decision' => 'ASSEMBLED',
            'reason' => null,
            'contract_label' => $contractLabel,
            'option_id' => (string) $contract['option_id'],
            'expiration' => (string) $contract['expiration_date'],
            'dte' => $expiration['dte'],
            'strike' => (float) $contract['strike_price'],
            'option_type' => $optionType,
            'limit_price' => $limit,
            'estimated_debit' => $estimatedDebit,
            'per_play_max_debit' => $perPlayMaxDebit,
            'anchor_mid' => (float) $priced['mid'],
            'anchor_source' => $anchorSource,
            'dte_band_clamp' => $band,
            'quote_age_seconds' => $quote['age_seconds'] ?? null,
            'quote_stale' => $quote['stale'] ?? null,
            'spread_bps' => $quote['spread_bps'] ?? null,
            'spot' => $spot,
            'journal_id' => null,
        ];

        if ($dryRun) {
            return $decision;
        }

        $journal = RobinhoodTradeJournal::query()->create([
            'account_number' => (string) ($cfg['agentic_account'] ?? ''),
            'asset_class' => 'option',
            'symbol' => $symbol,
            'underlying_symbol' => $symbol,
            'option_id' => (string) $contract['option_id'],
            'chain_id' => $contract['chain_id'] ?? null,
            'option_type' => $optionType,
            'expiration_date' => (string) $contract['expiration_date'],
            'strike_price' => (float) $contract['strike_price'],
            'status' => ThesisLaneConfig::STATUS_ASSEMBLED,
            'intent' => $optionType === 'call' ? 'long_call' : 'long_put',
            'side' => 'buy',
            'position_effect' => 'open',
            'order_type' => 'limit',
            'time_in_force' => 'gfd',
            'market_hours' => 'regular_hours',
            'quantity' => 1,
            'limit_price' => $limit,
            'dollar_amount' => $estimatedDebit,
            'strategy_label' => ThesisLaneConfig::STRATEGY_LABEL,
            'structure_class' => 'single_leg_long',
            'thesis_intent_id' => $thesis->id,
            'thesis' => $thesis->thesis,
            'invalidation' => $this->invalidationSummary($thesis),
            'watchlisted_at' => now(),
            'scanner_payload' => [
                'intent_source' => 'theta:thesis-assemble',
                'thesis' => [
                    'id' => $thesis->id,
                    'direction' => $thesis->direction,
                    'structure' => $thesis->structure,
                    'dte_band' => [(int) $thesis->dte_min, (int) $thesis->dte_max],
                    'catalyst' => $thesis->catalyst,
                    'price_stop' => $thesis->price_stop,
                    'time_stop_date' => $thesis->time_stop_date?->toDateString(),
                    'profit_target_bps' => $thesis->profit_target_bps,
                ],
                'underlying_quote' => $underlying,
                'selection' => [
                    'spot' => $spot,
                    'dte_band_clamp' => $band,
                    'expiration' => $expiration,
                    'strike' => $strike,
                    'quote' => $quote,
                ],
            ],
            'notes' => [
                'thesis_entry' => [
                    'assembled_at' => now()->toISOString(),
                    'anchor_mid' => (float) $priced['mid'],
                    'anchor_source' => $anchorSource,
                    'entry_limit_offset_bps' => (float) $thesis->entry_limit_offset_bps,
                    'per_play_max_debit' => $perPlayMaxDebit,
                    'assembly_limit_price' => $limit,
                    'assembly_quote' => [
                        'bid' => $quote['bid'],
                        'ask' => $quote['ask'],
                        'spread_bps' => $quote['spread_bps'],
                        'age_seconds' => $quote['age_seconds'] ?? null,
                        'stale' => $quote['stale'] ?? null,
                    ],
                ],
            ],
        ]);

        $decision['journal_id'] = $journal->id;

        return $decision;
    }

    /** Count of thesis-lane plays that already own a concurrency slot. */
    private function openThesisPlayCount(): int
    {
        return RobinhoodTradeJournal::query()
            ->where('asset_class', 'option')
            ->whereNotNull('thesis_intent_id')
            ->whereIn('status', ThesisLaneConfig::NON_TERMINAL_PLAY_STATUSES)
            ->count();
    }

    private function hasLivePlay(int $thesisIntentId): bool
    {
        return RobinhoodTradeJournal::query()
            ->where('asset_class', 'option')
            ->where('thesis_intent_id', $thesisIntentId)
            ->whereIn('status', ThesisLaneConfig::NON_TERMINAL_PLAY_STATUSES)
            ->exists();
    }

    /** One live thesis play per underlying — two theses on one name is 2x the cap. */
    private function hasLivePlayForUnderlying(string $symbol): bool
    {
        return RobinhoodTradeJournal::query()
            ->where('asset_class', 'option')
            ->whereNotNull('thesis_intent_id')
            ->where('underlying_symbol', strtoupper($symbol))
            ->whereIn('status', ThesisLaneConfig::NON_TERMINAL_PLAY_STATUSES)
            ->exists();
    }

    /** Mid of the live underlying book; null when the book is unusable. */
    private function spotFromQuote(array $quote): ?float
    {
        $bid = $quote['bid'] ?? null;
        $ask = $quote['ask'] ?? null;

        if (! is_numeric($bid) || ! is_numeric($ask) || (float) $bid <= 0.0 || (float) $ask <= 0.0) {
            return null;
        }

        return round(((float) $bid + (float) $ask) / 2, 4);
    }

    private function invalidationSummary(ThesisIntent $thesis): string
    {
        return sprintf(
            'price_stop=%s time_stop=%s profit_target_bps=%s',
            $thesis->price_stop ?? '—',
            $thesis->time_stop_date?->toDateString() ?? '—',
            $thesis->profit_target_bps ?? '—',
        );
    }
}
