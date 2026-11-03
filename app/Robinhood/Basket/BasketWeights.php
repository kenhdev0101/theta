<?php

declare(strict_types=1);

namespace App\Robinhood\Basket;

use RuntimeException;

/**
 * Thin weights source for the equity-basket "ETF" (thesis §11.2/§11.7).
 *
 * V1 reads docs/weighting-basket.csv directly (the §10 weights ARE the
 * allocation). Each row's `weight_pct` is normalized to a simplex fraction
 * w_i = weight_pct_i / Σ(weight_pct) so the fractions sum to exactly 1.0 and
 * `w_i × total_equity` fully invests the book across the basket by relative
 * weight. The raw CSV sum need not be exactly 100 (it is ~100.02).
 *
 * The normalized rows are hashed (weights_payload_hash) so a rebalance_event
 * is reproducible: the same CSV content always yields the same hash.
 */
final class BasketWeights
{
    /** @param list<array{ticker: string, weight_pct: float, weight: float, name: string, lane: string}> $rows */
    private function __construct(
        public readonly array $rows,
        public readonly string $sourceId,
        public readonly string $payloadHash,
        public readonly string $version,
    ) {}

    public static function fromCsv(string $path, ?string $sourceId = null): self
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("basket weights CSV not readable: {$path}");
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException("basket weights CSV read failed: {$path}");
        }

        $lines = preg_split('/\r\n|\r|\n/', trim($raw)) ?: [];
        if (count($lines) < 2) {
            throw new RuntimeException("basket weights CSV has no data rows: {$path}");
        }

        $header = str_getcsv((string) array_shift($lines));
        $cols = array_flip(array_map(static fn ($h) => strtolower(trim((string) $h)), $header));
        foreach (['ticker', 'weight_pct'] as $required) {
            if (! isset($cols[$required])) {
                throw new RuntimeException("basket weights CSV missing required column '{$required}': {$path}");
            }
        }

        $parsed = [];
        $sumPct = 0.0;
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $cells = str_getcsv($line);
            $ticker = strtoupper(trim((string) ($cells[$cols['ticker']] ?? '')));
            $pct = $cells[$cols['weight_pct']] ?? null;
            if ($ticker === '' || ! is_numeric($pct) || (float) $pct <= 0.0) {
                // A blank/zero-weight row is not a basket slot.
                continue;
            }
            $pct = (float) $pct;
            $sumPct += $pct;
            $parsed[] = [
                'ticker' => $ticker,
                'weight_pct' => $pct,
                'name' => isset($cols['name']) ? trim((string) ($cells[$cols['name']] ?? '')) : '',
                'lane' => isset($cols['lane']) ? trim((string) ($cells[$cols['lane']] ?? '')) : '',
            ];
        }

        if ($parsed === [] || $sumPct <= 0.0) {
            throw new RuntimeException("basket weights CSV yielded no positive-weight rows: {$path}");
        }

        // Normalize to a simplex. Dedupe-safe: a repeated ticker keeps the
        // first row (basket slots are unique by construction).
        $seen = [];
        $rows = [];
        foreach ($parsed as $row) {
            if (isset($seen[$row['ticker']])) {
                throw new RuntimeException('duplicate_basket_symbol');
            }
            $seen[$row['ticker']] = true;
            $rows[] = [
                'ticker' => $row['ticker'],
                'weight_pct' => $row['weight_pct'],
                'weight' => $row['weight_pct'] / $sumPct,
                'name' => $row['name'],
                'lane' => $row['lane'],
            ];
        }

        // Hash the normalized rows (ticker + 10dp weight), order-independent,
        // so the ledger's weights_payload_hash reproduces from CSV content.
        $hashInput = array_map(
            static fn (array $r): string => $r['ticker'].':'.number_format($r['weight'], 10, '.', ''),
            $rows,
        );
        sort($hashInput);
        $payloadHash = hash('sha256', implode('|', $hashInput));

        return new self(
            rows: $rows,
            sourceId: $sourceId ?? self::relativeId($path),
            payloadHash: $payloadHash,
            version: substr(hash('sha256', $raw), 0, 12),
        );
    }

    /** @return list<string> */
    public function tickers(): array
    {
        return array_map(static fn (array $r): string => $r['ticker'], $this->rows);
    }

    public function count(): int
    {
        return count($this->rows);
    }

    private static function relativeId(string $path): string
    {
        $base = base_path().DIRECTORY_SEPARATOR;
        if (str_starts_with($path, $base)) {
            return substr($path, strlen($base));
        }

        return basename($path);
    }
}
