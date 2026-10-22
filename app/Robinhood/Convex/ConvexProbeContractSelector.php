<?php

declare(strict_types=1);

namespace App\Robinhood\Convex;

use App\Robinhood\Mcp\PortfolioReader;
use App\Robinhood\Mcp\RobinhoodMcpClient;
use Illuminate\Support\Carbon;

/** Selects one broker-identified, currently quotable option near a spec delta. */
final class ConvexProbeContractSelector
{
    public function __construct(private readonly RobinhoodMcpClient $client, private readonly array $cfg) {}

    /** @return array{contract?:array,quote?:array,blockers:list<string>} */
    /** @param list<string> $excludedOptionIds */
    public function select(string $symbol, string $type, int $minDte, int $maxDte, float $targetDelta, float $maxPremium, array $excludedOptionIds = [], ?float $hardStopPct = null, ?float $profitTargetMult = null, array $evidenceContext = []): array
    {
        $samples = [];
        $result = null;
        try {
            $result = $this->selectObserved($symbol, $type, $minDte, $maxDte, $targetDelta, $maxPremium,
                $excludedOptionIds, $hardStopPct, $profitTargetMult, $samples);

            return $result;
        } finally {
            ConvexEvidence::stamp($this->cfg, 'selection_observed', [
                ...$evidenceContext, 'ticker' => $symbol, 'option_type' => $type,
                'target_delta' => $targetDelta, 'premium_ceiling_usd' => $maxPremium,
                'outcome' => $result === null ? 'read_interrupted' : (isset($result['contract']) ? 'selected' : 'blocked'),
                'contract' => $result['contract'] ?? null, 'quote' => $result['quote'] ?? null,
                'blockers' => $result['blockers'] ?? [], 'samples' => $samples,
            ]);
        }
    }

    private function selectObserved(string $symbol, string $type, int $minDte, int $maxDte, float $targetDelta, float $maxPremium, array $excludedOptionIds, ?float $hardStopPct, ?float $profitTargetMult, array &$samples): array
    {
        if ($minDte < 1 || $maxDte < $minDte || $targetDelta <= 0 || $targetDelta >= 1 || $maxPremium <= 0) {
            return ['blockers' => ['probe_selection_invalid']];
        }

        $chains = PortfolioReader::listFrom($this->client->getOptionChains($symbol), ['chains']);
        $chain = $chains[0] ?? null;
        if (! is_array($chain) || empty($chain['id'])) {
            return ['blockers' => ['option_chain_unavailable']];
        }

        $today = Carbon::now('America/New_York')->startOfDay();
        $expirations = [];
        foreach ((array) ($chain['expiration_dates'] ?? []) as $date) {
            if (! is_string($date)) {
                continue;
            }
            try {
                $dte = (int) $today->diffInDays(Carbon::parse($date, 'America/New_York')->startOfDay(), false);
            } catch (\Throwable) {
                continue;
            }
            if ($dte >= $minDte && $dte <= $maxDte) {
                $expirations[] = $date;
            }
        }
        sort($expirations);
        if ($expirations === []) {
            return ['blockers' => ['no_expiration_in_spec_band']];
        }
        // Daily expirations can dominate the front of a DTE band while the
        // standard Friday series carries the actual quote traffic. Prefer the
        // weekly series for this one-contract mechanical probe.
        $fridays = array_values(array_filter($expirations, static fn (string $date): bool => Carbon::parse($date)->isFriday()));
        if ($fridays !== []) {
            $expirations = $fridays;
        }

        $equityQuotes = PortfolioReader::listFrom($this->client->getEquityQuotes([$symbol]), ['results']);
        $spotQuote = $equityQuotes[0]['quote'] ?? null;
        $spot = is_array($spotQuote)
            ? (self::positive($spotQuote['last_trade_price'] ?? null) ?? self::positive($spotQuote['mark_price'] ?? null))
            : null;
        if ($spot === null) {
            return ['blockers' => ['underlying_spot_unavailable']];
        }

        $candidates = [];
        $rejected = [
            'excluded_option_id' => 0,
            'invalid_two_sided_quote' => 0,
            'delta_outside_target' => 0,
            'quote_stale' => 0,
            'spread_above_rail' => 0,
            'convex_exit_spread_consumes_thesis_risk' => 0,
            'premium_above_cash_ceiling' => 0,
        ];
        $quotedInstruments = 0;
        $maxPages = max(1, min(10, (int) data_get($this->cfg, 'convex.max_instrument_pages', 10)));
        $quoteAgeLimit = max(1, (int) data_get($this->cfg, 'options.min_quote_fresh_seconds', 10));
        $maxSpreadBps = (float) data_get($this->cfg, 'options.max_spread_bps', 1500);
        foreach (array_slice($expirations, 0, 2) as $expiration) {
            $cursor = null;
            $instruments = [];
            for ($page = 0; $page < $maxPages; $page++) {
                $payload = $this->client->getOptionInstruments(array_filter([
                    'chain_id' => (string) $chain['id'], 'expiration_dates' => $expiration,
                    'type' => $type, 'state' => 'active', 'cursor' => $cursor,
                ], static fn ($value): bool => $value !== null));
                $pageRows = PortfolioReader::listFrom($payload, ['instruments']);
                foreach ($pageRows as $instrument) {
                    $strike = is_array($instrument) ? self::positive($instrument['strike_price'] ?? null) : null;
                    // Match the emitted convex matrix's 15% call/put wing.
                    // A wider sampling window wasted the 16 quote slots on
                    // far strikes and could skip the adjacent 0.20Δ contract.
                    $inWing = $strike !== null && ($type === 'call'
                        ? $strike >= $spot && $strike <= $spot * 1.15
                        : $strike <= $spot && $strike >= $spot * 0.85);
                    if ($inWing && ($instrument['tradability'] ?? null) === 'tradable' && ! empty($instrument['id'])) {
                        $instruments[(string) $instrument['id']] = $instrument;
                    }
                }
                $firstStrike = is_array($pageRows[0] ?? null) ? self::positive($pageRows[0]['strike_price'] ?? null) : null;
                if ($firstStrike !== null && $firstStrike > ($type === 'call' ? $spot * 1.15 : $spot)) {
                    break;
                }
                $next = data_get($payload, 'data.next') ?? data_get($payload, 'next');
                if (! is_string($next) || $next === '') {
                    break;
                }
                $query = parse_url($next, PHP_URL_QUERY);
                if (is_string($query)) {
                    parse_str($query, $params);
                    $cursor = is_string($params['cursor'] ?? null) ? $params['cursor'] : $next;
                } else {
                    $cursor = $next;
                }
            }

            // Quote a bounded, evenly spaced wing so one probe cannot consume
            // the entire shared broker-credit minute.
            uasort($instruments, static fn (array $a, array $b): int => (float) $a['strike_price'] <=> (float) $b['strike_price']);
            if (count($instruments) > 16) {
                $rows = array_values($instruments);
                $sampled = [];
                for ($i = 0; $i < 16; $i++) {
                    $row = $rows[(int) round($i * (count($rows) - 1) / 15)];
                    $sampled[(string) $row['id']] = $row;
                }
                $instruments = $sampled;
            }

            foreach (array_chunk(array_keys($instruments), 20) as $ids) {
                $quotes = PortfolioReader::listFrom($this->client->getOptionQuotes($ids), ['results']);
                foreach ($quotes as $result) {
                    $quote = is_array($result['quote'] ?? null) ? $result['quote'] : [];
                    $id = (string) ($quote['instrument_id'] ?? '');
                    $instrument = $instruments[$id] ?? null;
                    if ($instrument === null) {
                        continue;
                    }
                    $quotedInstruments++;
                    $sample = min(count($samples), 31);
                    $samples[$sample] = [
                        'option_id' => $id, 'expiration_date' => $expiration,
                        'strike_price' => (float) $instrument['strike_price'],
                        ...ConvexEvidence::quote($quote), 'blockers' => [],
                    ];
                    if (in_array($id, $excludedOptionIds, true)) {
                        $rejected['excluded_option_id']++;
                        $samples[$sample]['blockers'][] = 'excluded_option_id';

                        continue;
                    }
                    $bid = self::positive($quote['bid_price'] ?? null);
                    $ask = self::positive($quote['ask_price'] ?? null);
                    $delta = self::positive(isset($quote['delta']) ? abs((float) $quote['delta']) : null);
                    $updated = $quote['updated_at'] ?? null;
                    if ($bid === null || $ask === null || $ask < $bid || $delta === null || ! is_string($updated)) {
                        $rejected['invalid_two_sided_quote']++;
                        $samples[$sample]['blockers'][] = 'invalid_two_sided_quote';

                        continue;
                    }
                    if (abs($delta - $targetDelta) > 0.05) {
                        $rejected['delta_outside_target']++;
                        $samples[$sample]['blockers'][] = 'delta_outside_target';

                        continue;
                    }
                    try {
                        $age = abs(Carbon::parse($updated)->diffInSeconds(Carbon::now()));
                    } catch (\Throwable) {
                        $rejected['quote_stale']++;
                        $samples[$sample]['blockers'][] = 'quote_stale';

                        continue;
                    }
                    $mid = ($bid + $ask) / 2;
                    $spreadBps = ($ask - $bid) / $mid * 10000;
                    $pricing = $hardStopPct === null ? null : ConvexEntryPricing::quote(
                        $bid, $ask, $hardStopPct, (float) data_get($this->cfg, 'convex.entry_repeg_cap_frac', 0.5),
                        $profitTargetMult, (float) data_get($this->cfg, 'convex.round_trip_cost_budget_fraction', 0.5),
                    );
                    $limit = $pricing['limit'] ?? ceil($ask * 100) / 100;
                    $premium = $limit * 100;
                    $samples[$sample]['age_seconds'] = $age;
                    $samples[$sample]['entry_limit'] = $limit;
                    $samples[$sample]['round_trip_cost_bps'] = $pricing['round_trip_cost_bps'] ?? null;
                    $samples[$sample]['round_trip_budget_bps'] = $pricing['round_trip_budget_bps'] ?? null;
                    $blocked = false;
                    if ($age > $quoteAgeLimit) {
                        $rejected['quote_stale']++;
                        $samples[$sample]['blockers'][] = 'quote_stale';
                        $blocked = true;
                    }
                    if ($hardStopPct === null && $spreadBps > $maxSpreadBps) {
                        $rejected['spread_above_rail']++;
                        $samples[$sample]['blockers'][] = 'spread_above_rail';
                        $blocked = true;
                    }
                    if ($pricing !== null && ! isset($pricing['limit'])) {
                        $samples[$sample]['blockers'][] = $pricing['blocker'];
                        $rejected[$pricing['blocker']] = ($rejected[$pricing['blocker']] ?? 0) + 1;
                        $blocked = true;
                    }
                    if ($premium > $maxPremium) {
                        $rejected['premium_above_cash_ceiling']++;
                        $samples[$sample]['blockers'][] = 'premium_above_cash_ceiling';
                        $blocked = true;
                    }
                    if ($blocked) {
                        continue;
                    }
                    $candidates[] = [
                        'score' => abs($delta - $targetDelta),
                        'entry_limit' => $limit,
                        'contract' => [
                            'option_id' => $id, 'chain_id' => $instrument['chain_id'] ?? $chain['id'],
                            'chain_symbol' => strtoupper($symbol), 'underlying_type' => $instrument['underlying_type'] ?? 'equity',
                            'expiration_date' => $expiration, 'strike_price' => (float) $instrument['strike_price'],
                            'type' => $type, 'state' => 'active', 'tradability' => 'tradable',
                        ],
                        'quote' => [
                            'bid' => $bid, 'ask' => $ask, 'mid' => $mid, 'spread_bps' => round($spreadBps, 2),
                            'delta' => (float) $quote['delta'], 'updated_at' => $updated,
                            'age_seconds' => $age, 'stale' => false,
                            'entry_limit' => $limit,
                            'entry_cost_bps' => $pricing['entry_cost_bps'] ?? null,
                            'exit_cost_bps' => $pricing['exit_cost_bps'] ?? null,
                            'round_trip_cost_bps' => $pricing['round_trip_cost_bps'] ?? null,
                            'round_trip_budget_bps' => $pricing['round_trip_budget_bps'] ?? null,
                        ],
                    ];
                }
            }
        }

        usort($candidates, static fn (array $a, array $b): int => ($a['score'] <=> $b['score']) ?: ($a['quote']['ask'] <=> $b['quote']['ask']));
        if ($candidates === []) {
            $blockers = ['no_fresh_tradable_delta_contract_within_premium_cap'];
            if ($quotedInstruments === 0) {
                $blockers[] = 'no_quoted_instrument_in_wing';
            }
            foreach ($rejected as $reason => $count) {
                if ($count > 0) {
                    $blockers[] = $reason.':'.$count;
                }
            }

            return ['blockers' => $blockers];
        }

        return ['contract' => $candidates[0]['contract'], 'quote' => $candidates[0]['quote'], 'blockers' => []];
    }

    private static function positive(mixed $value): ?float
    {
        return is_numeric($value) && (float) $value > 0 ? (float) $value : null;
    }
}
