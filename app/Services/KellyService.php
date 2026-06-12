<?php

namespace App\Services;

class KellyService
{
    protected float $fractionMultiplier;

    public function __construct(float $fractionMultiplier = 0.25)
    {
        // Quarter-Kelly by default for conservative sizing
        $this->fractionMultiplier = $fractionMultiplier;
    }

    /**
     * Calculate Kelly Criterion bet size.
     *
     * @param  float  $edge  The estimated edge (e.g., 0.05 for 5%)
     * @param  int  $americanOdds  The American odds (e.g., -110, +150)
     * @param  float  $bankroll  Current bankroll amount
     * @param  float  $maxUnitSize  Maximum unit size cap (default 5.0)
     * @return array{unit_size: float, wager_amount: float, full_kelly: float}
     */
    public function calculate(float $edge, int $americanOdds, float $bankroll, float $maxUnitSize = 5.0): array
    {
        $decimalOdds = $this->americanToDecimal($americanOdds);
        $impliedProb = 1 / $decimalOdds;
        $estimatedProb = $impliedProb + $edge;

        // Kelly formula: f* = (bp - q) / b
        // where b = decimal odds - 1, p = estimated prob of winning, q = 1 - p
        $b = $decimalOdds - 1;
        $p = min(max($estimatedProb, 0.01), 0.99); // clamp
        $q = 1 - $p;

        $fullKelly = ($b * $p - $q) / $b;
        $fractionalKelly = max(0, $fullKelly * $this->fractionMultiplier);

        // Convert to units (1 unit = 1% of bankroll)
        $unitSize = min(round($fractionalKelly * 100, 2), $maxUnitSize);
        $wagerAmount = round($bankroll * $fractionalKelly, 2);

        return [
            'unit_size' => $unitSize,
            'wager_amount' => $wagerAmount,
            'full_kelly' => round($fullKelly * 100, 2),
        ];
    }

    /**
     * Get recommended unit size based on confidence level.
     * Simpler alternative to full Kelly when edge is uncertain.
     */
    public function confidenceToUnits(int $confidence): float
    {
        return match (true) {
            $confidence >= 80 => 3.0,
            $confidence >= 70 => 2.0,
            $confidence >= 60 => 1.5,
            $confidence >= 50 => 1.0,
            $confidence >= 40 => 0.5,
            default => 0.0,
        };
    }

    protected function americanToDecimal(int $odds): float
    {
        if ($odds > 0) {
            return ($odds / 100) + 1;
        }

        return (100 / abs($odds)) + 1;
    }
}
