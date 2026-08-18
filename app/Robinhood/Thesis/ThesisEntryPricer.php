<?php

declare(strict_types=1);

namespace App\Robinhood\Thesis;

/**
 * Deterministic entry-limit derivation for the thesis lane.
 *
 * The scalp lane buys at the ask because it needs an immediate fill; paying
 * the full spread every round trip is exactly what bled the June run. A
 * days-horizon thesis entry instead posts inside the book and works the fill
 * for a bounded window, so the primitive here is "mid, nudged by the thesis's
 * own offset, never outside the live book, never past the chase ceiling".
 *
 * Every number is computed from the live quote. No model touches this path.
 */
final class ThesisEntryPricer
{
    /** Option prices quote and place on a one-cent tick. */
    public const TICK = 0.01;

    private const EPSILON = 0.000001;

    /**
     * @param  array<string,mixed>  $quote  resolver quote block (bid/ask/mid)
     * @param  float  $offsetBps  thesis entry_limit_offset_bps applied to the mid
     * @param  float|null  $chaseCeiling  absolute price ceiling, or null at assembly
     * @return array{limit: float|null, mid: float|null, bid: float|null, ask: float|null, reason: string|null, chase_ceiling: float|null, offset_bps: float}
     */
    public static function derive(array $quote, float $offsetBps, ?float $chaseCeiling = null): array
    {
        $bid = self::positiveOrNull($quote['bid'] ?? null);
        $ask = self::positiveOrNull($quote['ask'] ?? null);

        $base = [
            'limit' => null,
            'mid' => null,
            'bid' => $bid,
            'ask' => $ask,
            'reason' => null,
            'chase_ceiling' => $chaseCeiling,
            'offset_bps' => $offsetBps,
        ];

        if ($bid === null || $ask === null) {
            return array_merge($base, ['reason' => 'bid_or_ask_missing']);
        }

        $mid = round(($bid + $ask) / 2, 4);
        $base['mid'] = $mid;

        // Post inside the live book: at worst join the bid, at best lift no
        // higher than the ask. A crossed/locked-inverted quote is a data
        // defect, never a price to act on.
        $lower = self::ceilTick($bid);
        $upper = self::floorTick($ask);
        if ($upper + self::EPSILON < $lower) {
            return array_merge($base, ['reason' => 'quote_crossed']);
        }

        if ($chaseCeiling !== null) {
            $ceilingTick = self::floorTick($chaseCeiling);
            if ($ceilingTick + self::EPSILON < $lower) {
                return array_merge($base, ['reason' => 'entry_chase_cap_exceeded']);
            }
            $upper = min($upper, $ceilingTick);
        }

        $target = $mid * (1 + ($offsetBps / 10000));
        $limit = round($target, 2);
        $limit = max($lower, min($upper, $limit));

        if ($limit <= 0.0) {
            return array_merge($base, ['reason' => 'limit_non_positive']);
        }

        return array_merge($base, ['limit' => round($limit, 2)]);
    }

    /** The absolute price ceiling implied by an anchor mid and a chase cap. */
    public static function chaseCeiling(float $anchorMid, float $maxChaseBps): float
    {
        return round($anchorMid * (1 + ($maxChaseBps / 10000)), 6);
    }

    private static function ceilTick(float $price): float
    {
        return round(ceil(round($price / self::TICK, 6)) * self::TICK, 2);
    }

    private static function floorTick(float $price): float
    {
        return round(floor(round($price / self::TICK, 6)) * self::TICK, 2);
    }

    private static function positiveOrNull(mixed $value): ?float
    {
        if (! is_numeric($value) || (float) $value <= 0.0) {
            return null;
        }

        return (float) $value;
    }
}
