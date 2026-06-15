<?php

declare(strict_types=1);

namespace App\Robinhood\Options;

use App\MarketData\AlpacaMarketDataClient;

class MomentumUniverseSelector
{
    /** @param list<string> $seedSymbols @return list<string> */
    public function select(AlpacaMarketDataClient $data, array $seedSymbols, array $producer): array
    {
        $symbols = $this->normalize($seedSymbols);
        if (($producer['universe_source'] ?? 'static') !== 'dynamic') {
            return $symbols;
        }

        return $this->rank($data, $symbols, $producer);
    }

    /** @param list<string> $symbols @return list<string> */
    private function rank(AlpacaMarketDataClient $data, array $symbols, array $producer): array
    {
        $ranked = [];
        $lookback = max(3, (int) ($producer['momentum_lookback_bars'] ?? 10));
        $timeframe = (string) ($producer['momentum_timeframe'] ?? '1Min');
        $barFreshSeconds = max(1, (int) ($producer['momentum_bar_fresh_seconds'] ?? 180));

        foreach ($symbols as $symbol) {
            try {
                $barsPayload = $data->recentBars($symbol, $lookback, $timeframe);
            } catch (\Throwable) {
                continue;
            }

            $bars = array_values(array_filter((array) ($barsPayload['bars'] ?? []), function (array $bar): bool {
                return is_numeric($bar['close'] ?? null) && is_numeric($bar['volume'] ?? null);
            }));
            if (count($bars) < $lookback) {
                continue;
            }

            $bars = array_slice($bars, -$lookback);
            $first = $bars[0];
            $latest = $bars[array_key_last($bars)];
            if (($latest['age_seconds'] ?? null) === null || (float) $latest['age_seconds'] > $barFreshSeconds) {
                continue;
            }

            $firstClose = (float) $first['close'];
            $latestClose = (float) $latest['close'];
            if ($firstClose <= 0.0) {
                continue;
            }

            $previousVolumes = array_map(fn (array $bar): float => (float) $bar['volume'], array_slice($bars, 0, -1));
            $avgPreviousVolume = count($previousVolumes) > 0 ? array_sum($previousVolumes) / count($previousVolumes) : 0.0;
            $latestVolume = (float) $latest['volume'];
            $volumeExpansion = $avgPreviousVolume > 0.0 ? $latestVolume / $avgPreviousVolume : 0.0;
            $returnBps = ($latestClose - $firstClose) / $firstClose * 10000;

            $ranked[] = [
                'symbol' => $symbol,
                'score' => abs($returnBps) * max(0.25, $volumeExpansion),
            ];
        }

        usort($ranked, fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        $limit = max(1, (int) ($producer['dynamic_max_symbols'] ?? 6));
        $selected = array_map(
            fn (array $row): string => (string) $row['symbol'],
            array_slice($ranked, 0, $limit),
        );

        return $selected === [] ? array_slice($symbols, 0, $limit) : $selected;
    }

    /** @param list<string> $symbols @return list<string> */
    private function normalize(array $symbols): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn (string $symbol): string => strtoupper(trim($symbol)),
            $symbols,
        ))));
    }
}
