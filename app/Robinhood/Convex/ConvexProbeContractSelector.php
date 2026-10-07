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
    public function select(string $symbol, string $type, int $minDte, int $maxDte, float $targetDelta, float $maxPremium, array $excludedOptionIds = []): array
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
                    if ($instrument === null || in_array($id, $excludedOptionIds, true)) {
                        continue;
                    }
                    $bid = self::positive($quote['bid_price'] ?? null);
                    $ask = self::positive($quote['ask_price'] ?? null);
                    $delta = self::positive(isset($quote['delta']) ? abs((float) $quote['delta']) : null);
                    $updated = $quote['updated_at'] ?? null;
                    if ($bid === null || $ask === null || $ask < $bid || $delta === null
                        || abs($delta - $targetDelta) > 0.05 || ! is_string($updated)) {
                        continue;
                    }
                    try {
                        $age = abs(Carbon::parse($updated)->diffInSeconds(Carbon::now()));
                    } catch (\Throwable) {
                        continue;
                    }
                    $mid = ($bid + $ask) / 2;
                    $spreadBps = ($ask - $bid) / $mid * 10000;
                    $premium = ceil($ask * 100) / 100 * 100;
                    if ($age > $quoteAgeLimit || $spreadBps > $maxSpreadBps || $premium > $maxPremium) {
                        continue;
                    }
                    $candidates[] = [
                        'score' => abs($delta - $targetDelta),
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
                        ],
                    ];
                }
            }
        }

        usort($candidates, static fn (array $a, array $b): int => ($a['score'] <=> $b['score']) ?: ($a['quote']['ask'] <=> $b['quote']['ask']));
        if ($candidates === []) {
            return ['blockers' => ['no_fresh_tradable_delta_contract_within_premium_cap']];
        }

        return ['contract' => $candidates[0]['contract'], 'quote' => $candidates[0]['quote'], 'blockers' => []];
    }

    private static function positive(mixed $value): ?float
    {
        return is_numeric($value) && (float) $value > 0 ? (float) $value : null;
    }
}
