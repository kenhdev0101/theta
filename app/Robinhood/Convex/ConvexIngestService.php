<?php

declare(strict_types=1);

namespace App\Robinhood\Convex;

use App\Robinhood\Mcp\PortfolioReader;
use App\Robinhood\Mcp\RobinhoodMcpClient;
use App\Robinhood\Telemetry\JsonlRecorder;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Read-only data plane for the fringe-convexity near-0.20Δ gamma-expression
 * lane. Pulls live broker option truth (quotes + greeks + bid/ask + volume +
 * open_interest + updated_at) for the configured index underlyings in the
 * configured DTE band, assembles the sealed convex feature extractor's input
 * contract, routes it through the greeks service (convex_feature_row), validates
 * the emitted row against the sealed schema (convex_feature_schema), and appends
 * every clean observation to the feature matrix with reserved outcome columns
 * left null for the later calibration join.
 *
 * Spec: docs/research/fringe-convexity/green-engine-architecture.md (§3a data
 * plane, §4 control flow). The math/thresholds are a sealed, immutable library
 * — this class only wires the feed; it computes no money-moving number.
 */
final class ConvexIngestService
{
    public function __construct(
        private readonly RobinhoodMcpClient $mcp,
        private readonly GreeksRunClient $greeks,
        private readonly FeatureMatrixWriter $writer,
        private readonly ?JsonlRecorder $recorder,
        private readonly array $cfg,
    ) {}

    /**
     * Run one sweep across the given underlyings (defaults to configured
     * tickers). Returns a flat list of per-(ticker, tenor) result rows for the
     * command's readback table. `$append=false` computes + validates + stamps
     * but does not write the matrix (dry-run).
     *
     * @param  list<string>|null  $tickers
     * @return list<array<string,mixed>>
     */
    public function sweep(?array $tickers = null, bool $append = true): array
    {
        $tickers ??= $this->configuredTickers();
        $bucket = (string) data_get($this->cfg, 'convex.bucket', 'index');
        $snapshotTs = Carbon::now('UTC')->toIso8601ZuluString();
        $results = [];

        foreach ($tickers as $ticker) {
            $ticker = strtoupper(trim($ticker));
            if ($ticker === '') {
                continue;
            }

            try {
                $spot = $this->resolveSpot($ticker);
            } catch (Throwable $e) {
                $results[] = $this->block($ticker, null, 'spot_unresolved', $e->getMessage());

                continue;
            }

            try {
                $chains = PortfolioReader::listFrom($this->mcp->getOptionChains($ticker), ['chains']);
            } catch (Throwable $e) {
                $results[] = $this->block($ticker, null, 'chain_read_failed', $e->getMessage());

                continue;
            }

            $chain = $chains[0] ?? null;
            $chainId = is_array($chain) ? (string) ($chain['id'] ?? '') : '';
            if ($chainId === '') {
                $results[] = $this->block($ticker, null, 'no_chain', null);

                continue;
            }

            $expirations = $this->selectExpirations((array) ($chain['expiration_dates'] ?? []));
            if ($expirations === []) {
                $results[] = $this->block($ticker, null, 'no_expiration_in_band', null);

                continue;
            }

            foreach ($expirations as $expiration) {
                try {
                    $results[] = $this->ingestTenor($ticker, $bucket, $spot, $chainId, $expiration, $snapshotTs, $append);
                } catch (Throwable $e) {
                    $results[] = $this->block($ticker, $this->dteFor($expiration), 'tenor_ingest_failed', $e->getMessage());
                }
            }
        }

        return $results;
    }

    /** @return array<string,mixed> */
    private function ingestTenor(
        string $ticker,
        string $bucket,
        float $spot,
        string $chainId,
        string $expiration,
        string $snapshotTs,
        bool $append,
    ): array {
        $dte = $this->dteFor($expiration);

        $calls = $this->buildSideRows($chainId, $expiration, 'call', $spot,
            (float) data_get($this->cfg, 'convex.call_moneyness_min', 0.98),
            (float) data_get($this->cfg, 'convex.call_moneyness_max', 1.30));
        $puts = $this->buildSideRows($chainId, $expiration, 'put', $spot,
            (float) data_get($this->cfg, 'convex.put_moneyness_min', 0.70),
            (float) data_get($this->cfg, 'convex.put_moneyness_max', 1.02));

        if ($calls['rows'] === [] && $puts['rows'] === []) {
            return $this->block($ticker, $dte, 'no_quotable_contracts', null);
        }

        $args = [
            '--ticker', $ticker,
            '--bucket', $bucket,
            '--spot', $this->num($spot),
            '--dte', (string) $dte,
            '--call-rows', $this->json($calls['rows']),
            '--put-rows', $this->json($puts['rows']),
            '--repeg-cap-frac', $this->num((float) data_get($this->cfg, 'convex.entry_repeg_cap_frac', 0.5)),
            '--format', 'json',
        ];

        $envelope = $this->greeks->run('convex_feature_row', $args);
        $row = $envelope['stdout_json'] ?? null;
        if (($envelope['ok'] ?? false) !== true || ! is_array($row)) {
            return $this->block($ticker, $dte, 'feature_row_failed', (string) ($envelope['stderr'] ?? 'no stdout_json'));
        }

        // Clean-row gate: the sealed schema validates the emitted row before it
        // is allowed into the matrix. A drift/contract violation is surfaced,
        // never appended.
        $check = $this->greeks->run('convex_feature_schema', ['--row', $this->json($row)]);
        $schema = $check['stdout_json'] ?? null;
        $schemaOk = ($check['ok'] ?? false) === true && is_array($schema) && ($schema['ok'] ?? false) === true;

        $freshness = [
            'max_quote_age_seconds' => max($calls['max_age'], $puts['max_age']),
            'stale_quotes' => $calls['stale'] + $puts['stale'],
            'stale_gate_seconds' => (int) data_get($this->cfg, 'convex.min_quote_fresh_seconds', 60),
        ];

        // The emitted row IS the canonical column contract: the schema --row
        // check above only passes when list(row.keys()) == FEATURE_COLUMNS, so
        // the key order here is drift-safe without a second round-trip.
        $appended = false;
        if ($schemaOk && $append) {
            $this->writer->append(array_keys($row), $row);
            $appended = true;
        }

        $this->stamp('convex_ingest', [
            'event' => 'feature_row',
            'ticker' => $ticker,
            'bucket' => $bucket,
            'expiration' => $expiration,
            'dte' => $dte,
            'spot' => round($spot, 4),
            'snapshot_ts' => $snapshotTs,
            'verdict' => $row['verdict'] ?? null,
            'failed_signals' => $row['failed_signals'] ?? null,
            'pending_signals' => $row['pending_signals'] ?? null,
            'far_delta' => $row['far_delta'] ?? null,
            'achievable_cost_bps' => $row['achievable_cost_bps'] ?? null,
            'fill_probability' => $row['fill_probability'] ?? null,
            'n_call_rows' => count($calls['rows']),
            'n_put_rows' => count($puts['rows']),
            'schema_ok' => $schemaOk,
            'schema_violations' => $schemaOk ? [] : (is_array($schema) ? ($schema['violations'] ?? ['schema_run_failed']) : ['schema_run_failed']),
            'appended' => $appended,
            'freshness' => $freshness,
        ]);

        return [
            'ticker' => $ticker,
            'dte' => $dte,
            'spot' => round($spot, 4),
            'verdict' => $row['verdict'] ?? null,
            'far_delta' => $row['far_delta'] ?? null,
            'achievable_cost_bps' => $row['achievable_cost_bps'] ?? null,
            'fill_probability' => $row['fill_probability'] ?? null,
            'n_calls' => count($calls['rows']),
            'n_puts' => count($puts['rows']),
            'max_age_s' => $freshness['max_quote_age_seconds'],
            'stale' => $freshness['stale_quotes'],
            'schema_ok' => $schemaOk,
            'appended' => $appended,
            'block' => $schemaOk ? null : 'schema_drift',
        ];
    }

    /**
     * Assemble one side's clean row payload for the sealed extractor:
     * {strike, iv, delta, gamma, bid, ask, volume, open_interest}. Instruments
     * are windowed by moneyness, evenly down-sampled to the per-side cap so the
     * whole wing shape (ATM → deep fringe) survives, then quoted in ≤20-id
     * chunks (broker close-data cap).
     *
     * @return array{rows:list<array<string,mixed>>, max_age:float, stale:int, n_instruments:int}
     */
    private function buildSideRows(string $chainId, string $expiration, string $type, float $spot, float $lo, float $hi): array
    {
        $instruments = $this->paginateInstruments($chainId, $expiration, $type, $spot * $hi);

        $windowed = [];
        foreach ($instruments as $inst) {
            $strike = isset($inst['strike_price']) && is_numeric($inst['strike_price']) ? (float) $inst['strike_price'] : null;
            $id = (string) ($inst['id'] ?? '');
            if ($strike === null || $id === '' || $strike < $spot * $lo || $strike > $spot * $hi) {
                continue;
            }
            $windowed[] = ['id' => $id, 'strike' => $strike];
        }
        usort($windowed, fn (array $a, array $b): int => $a['strike'] <=> $b['strike']);
        $windowed = $this->downsample($windowed, max(1, (int) data_get($this->cfg, 'convex.max_strikes_per_side', 24)));

        $byId = [];
        foreach ($windowed as $w) {
            $byId[$w['id']] = $w['strike'];
        }

        $rows = [];
        $maxAge = 0.0;
        $stale = 0;
        $gate = (int) data_get($this->cfg, 'convex.min_quote_fresh_seconds', 60);
        $chunkSize = max(1, (int) data_get($this->cfg, 'convex.quote_chunk_size', 20));

        foreach (array_chunk(array_keys($byId), $chunkSize) as $ids) {
            $quotePayload = $this->mcp->getOptionQuotes(array_values($ids));
            foreach (PortfolioReader::listFrom($quotePayload, ['results']) as $result) {
                $quote = is_array($result['quote'] ?? null) ? $result['quote'] : (is_array($result) ? $result : []);
                $instrumentId = (string) ($quote['instrument_id'] ?? '');
                if (! isset($byId[$instrumentId])) {
                    continue;
                }
                $extracted = $this->extractQuoteRow($byId[$instrumentId], $quote, $gate);
                if ($extracted === null) {
                    continue;
                }
                $rows[] = $extracted['row'];
                $maxAge = max($maxAge, $extracted['age'] ?? 0.0);
                $stale += $extracted['stale'] ? 1 : 0;
            }
        }

        return ['rows' => $rows, 'max_age' => round($maxAge, 3), 'stale' => $stale, 'n_instruments' => count($byId)];
    }

    /**
     * Fetch active instruments for one (chain, expiration, type), walking the
     * broker's cursor pages. The instrument list is paginated at ~100/page in
     * ascending strike order, so page 1 for a high-priced index can be entirely
     * below spot — the OTM call wing (the product) lives on later pages. We page
     * until a whole page sits above the window ceiling, the cursor runs out, or
     * the page cap is hit.
     *
     * @return list<array<string,mixed>>
     */
    private function paginateInstruments(string $chainId, string $expiration, string $type, float $windowHi): array
    {
        $maxPages = max(1, (int) data_get($this->cfg, 'convex.max_instrument_pages', 10));
        $all = [];
        $cursor = null;

        for ($page = 0; $page < $maxPages; $page++) {
            $payload = $this->mcp->getOptionInstruments(array_filter([
                'chain_id' => $chainId,
                'expiration_dates' => $expiration,
                'type' => $type,
                'state' => 'active',
                'cursor' => $cursor,
            ], fn ($v): bool => $v !== null));

            $pageRows = PortfolioReader::listFrom($payload, ['instruments']);
            $all = array_merge($all, $pageRows);

            // Ascending strikes: once an entire page clears the window ceiling,
            // every later page is deeper OTM still — stop paying for it.
            $pageStrikes = array_values(array_filter(array_map(
                fn ($r): ?float => isset($r['strike_price']) && is_numeric($r['strike_price']) ? (float) $r['strike_price'] : null,
                $pageRows,
            ), fn ($s): bool => $s !== null));
            if ($pageStrikes !== [] && min($pageStrikes) > $windowHi) {
                break;
            }

            $next = data_get($payload, 'data.next') ?? data_get($payload, 'next');
            // The live surface returns `next` as a RAW opaque cursor token
            // (e.g. base64 "p=658.0000"), not a URL — so pass it straight
            // through, falling back to URL-param extraction only if it happens
            // to be a URL. (A URL-only parse silently dead-ends on the sub-spot
            // page and loses the entire OTM call wing.)
            $cursor = (is_string($next) && $next !== '') ? ($this->cursorFromNext($next) ?? $next) : null;
            if ($cursor === null) {
                break;
            }
        }

        return $all;
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

    /**
     * Map a broker option quote to the sealed extractor's row contract. bid/ask
     * are kept raw (the extractor runs its own quote-quality pre-filter and
     * cleaning — this layer does not pre-judge). gamma is optional (nullable in
     * the schema); the broker may or may not carry it.
     *
     * @return array{row:array<string,mixed>, age:float, stale:bool}|null
     */
    private function extractQuoteRow(float $strike, array $quote, int $gate): ?array
    {
        $bid = $this->floatOrNull($quote['bid_price'] ?? null);
        $ask = $this->floatOrNull($quote['ask_price'] ?? null);
        if ($bid === null && $ask === null) {
            return null; // nothing quotable — the extractor needs at least a side
        }

        $updatedAt = $quote['updated_at'] ?? null;
        $age = null;
        $stale = true;
        if (is_string($updatedAt) && $updatedAt !== '') {
            $age = round(abs(Carbon::parse($updatedAt)->diffInMilliseconds(Carbon::now())) / 1000, 3);
            $stale = $age > $gate;
        }

        return [
            'row' => [
                'strike' => $strike,
                'iv' => $this->floatOrNull($quote['implied_volatility'] ?? null),
                'delta' => $this->floatOrNull($quote['delta'] ?? null),
                'gamma' => $this->floatOrNull($quote['gamma'] ?? null),
                'bid' => $bid,
                'ask' => $ask,
                'volume' => $this->numericOrNull($quote['volume'] ?? null),
                'open_interest' => $this->numericOrNull($quote['open_interest'] ?? null),
            ],
            'age' => $age ?? 0.0,
            'stale' => $stale,
        ];
    }

    private function resolveSpot(string $ticker): float
    {
        $rows = PortfolioReader::listFrom($this->mcp->getEquityQuotes([$ticker]), ['results']);
        foreach ($rows as $result) {
            $quote = is_array($result['quote'] ?? null) ? $result['quote'] : (is_array($result) ? $result : []);
            if (strtoupper((string) ($quote['symbol'] ?? $ticker)) !== $ticker) {
                continue;
            }
            $last = $this->floatOrNull($quote['last_trade_price'] ?? null)
                ?? $this->floatOrNull($quote['mark_price'] ?? null);
            if ($last !== null) {
                return $last;
            }
            $bid = $this->floatOrNull($quote['bid_price'] ?? null);
            $ask = $this->floatOrNull($quote['ask_price'] ?? null);
            if ($bid !== null && $ask !== null) {
                return round(($bid + $ask) / 2, 4);
            }
        }

        throw new \RuntimeException("no usable equity quote for {$ticker}");
    }

    /**
     * Expirations whose calendar DTE falls inside the configured band, nearest
     * first, capped to tenors_per_ticker.
     *
     * @param  list<string>  $expirations
     * @return list<string>
     */
    private function selectExpirations(array $expirations): array
    {
        $floor = (int) data_get($this->cfg, 'convex.dte_floor', 45);
        $ceiling = (int) data_get($this->cfg, 'convex.dte_ceiling', 90);
        $cap = max(1, (int) data_get($this->cfg, 'convex.tenors_per_ticker', 2));

        $eligible = [];
        foreach ($expirations as $date) {
            $dte = $this->dteFor((string) $date);
            if ($dte >= $floor && $dte <= $ceiling) {
                $eligible[(string) $date] = $dte;
            }
        }
        asort($eligible);

        return array_slice(array_keys($eligible), 0, $cap);
    }

    private function dteFor(string $expiration): int
    {
        return (int) Carbon::now()->startOfDay()->diffInDays(Carbon::parse($expiration)->startOfDay(), false);
    }

    /** @param list<array{id:string,strike:float}> $rows */
    private function downsample(array $rows, int $cap): array
    {
        $n = count($rows);
        if ($n <= $cap) {
            return $rows;
        }
        $out = [];
        for ($i = 0; $i < $cap; $i++) {
            $out[] = $rows[(int) floor($i * ($n - 1) / ($cap - 1))];
        }

        return array_values(array_unique($out, SORT_REGULAR));
    }

    /** @return list<string> */
    private function configuredTickers(): array
    {
        $tickers = (array) data_get($this->cfg, 'convex.tickers', ['SPY', 'QQQ', 'IWM']);

        return array_values(array_filter(array_map(fn ($t): string => strtoupper(trim((string) $t)), $tickers)));
    }

    /** @return array<string,mixed> */
    private function block(string $ticker, ?int $dte, string $reason, ?string $detail): array
    {
        $this->stamp('convex_ingest', array_filter([
            'event' => 'block',
            'ticker' => $ticker,
            'dte' => $dte,
            'reason' => $reason,
            'detail' => $detail,
        ], fn ($v): bool => $v !== null));

        return [
            'ticker' => $ticker,
            'dte' => $dte,
            'spot' => null,
            'verdict' => null,
            'far_delta' => null,
            'achievable_cost_bps' => null,
            'fill_probability' => null,
            'n_calls' => 0,
            'n_puts' => 0,
            'max_age_s' => null,
            'stale' => null,
            'schema_ok' => false,
            'appended' => false,
            'block' => $reason,
        ];
    }

    private function stamp(string $stream, array $data): void
    {
        $this->recorder?->record($stream, $data);
    }

    private function floatOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function numericOrNull(mixed $value): int|float|null
    {
        if (! is_numeric($value)) {
            return null;
        }

        return $value + 0;
    }

    private function num(float $value): string
    {
        return rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
    }

    private function json(mixed $value): string
    {
        return (string) json_encode($value, JSON_THROW_ON_ERROR);
    }
}
