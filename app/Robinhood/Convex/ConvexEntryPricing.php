<?php

declare(strict_types=1);

namespace App\Robinhood\Convex;

/** Bounds a resting convex buy by the matrix reprice envelope and the thesis loss budget. */
final class ConvexEntryPricing
{
    /** @return array{limit?:float,blocker?:string,immediate_exit_loss_pct?:float} */
    public static function quote(float $bid, float $ask, float $hardStopPct, float $repegCapFrac): array
    {
        if ($bid <= 0 || $ask < $bid || $hardStopPct <= 0 || $hardStopPct >= 100
            || $repegCapFrac < 0 || $repegCapFrac > 1) {
            return ['blocker' => 'convex_entry_risk_unverified'];
        }

        $mid = ($bid + $ask) / 2;
        $modelCap = floor(($mid + ($ask - $mid) * $repegCapFrac) * 100 + 0.000001) / 100;
        $lossCap = floor(($bid / (1 - $hardStopPct / 100)) * 100 + 0.000001) / 100;
        $limit = min($modelCap, $lossCap);
        if ($limit < ceil($mid * 100 - 0.000001) / 100) {
            return ['blocker' => 'convex_exit_spread_consumes_thesis_risk'];
        }

        return [
            'limit' => $limit,
            'immediate_exit_loss_pct' => round(($limit - $bid) / $limit * 100, 2),
        ];
    }

    public static function limitAllowed(float $bid, float $ask, float $limit, float $hardStopPct, float $repegCapFrac): bool
    {
        $priced = self::quote($bid, $ask, $hardStopPct, $repegCapFrac);

        return isset($priced['limit']) && $limit >= ceil((($bid + $ask) / 2) * 100 - 0.000001) / 100
            && $limit <= $priced['limit'] + 0.000001;
    }
}
