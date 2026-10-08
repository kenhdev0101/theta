<?php

declare(strict_types=1);

namespace App\Robinhood\Convex;

/** Bounds a resting convex buy by the matrix reprice envelope and the thesis loss budget. */
final class ConvexEntryPricing
{
    /** @return array{limit?:float,blocker?:string,immediate_exit_loss_pct?:float,entry_cost_bps?:float,exit_cost_bps?:float,round_trip_cost_bps?:float,round_trip_budget_bps?:float} */
    public static function quote(float $bid, float $ask, float $hardStopPct, float $repegCapFrac, ?float $profitTargetMult = null, ?float $costBudgetFraction = null): array
    {
        if ($bid <= 0 || $ask < $bid || $hardStopPct <= 0 || $hardStopPct >= 100
            || $repegCapFrac < 0 || $repegCapFrac > 1) {
            return ['blocker' => 'convex_entry_risk_unverified'];
        }

        $mid = ($bid + $ask) / 2;
        $modelCap = floor(($mid + ($ask - $mid) * $repegCapFrac) * 100 + 0.000001) / 100;
        // Leave positive room before the executable bid itself reaches the
        // hard stop. Equality would open a contract already due for exit.
        $lossCap = floor(($bid / (1 - $hardStopPct / 100)) * 100 - 0.00000001) / 100;
        $limit = min($modelCap, $lossCap);
        if ($limit < ceil($mid * 100 - 0.000001) / 100) {
            return ['blocker' => 'convex_exit_spread_consumes_thesis_risk'];
        }

        $budgetBps = null;
        if ($profitTargetMult !== null || $costBudgetFraction !== null) {
            if ($profitTargetMult === null || $profitTargetMult <= 1 || $costBudgetFraction === null
                || $costBudgetFraction <= 0 || $costBudgetFraction > 1) {
                return ['blocker' => 'convex_entry_cost_budget_unverified'];
            }
            $budgetBps = min($hardStopPct, ($profitTargetMult - 1) * 100) * 100 * $costBudgetFraction;
            // The spread is a round-trip cost: proposed entry debit versus
            // an immediate executable bid exit, both in premium BPS.
            $budgetCap = floor(($bid / (1 - min($budgetBps, 9999) / 10000)) * 100 + 0.00000001) / 100;
            $limit = min($limit, $budgetCap);
            if ($limit < ceil($mid * 100 - 0.000001) / 100) {
                return ['blocker' => 'convex_round_trip_cost_exceeds_budget', 'round_trip_budget_bps' => round($budgetBps, 2)];
            }
        }

        $entryCostBps = max(0, ($limit - $mid) / $limit * 10000);
        $exitCostBps = ($mid - $bid) / $limit * 10000;

        return [
            'limit' => $limit,
            'immediate_exit_loss_pct' => round(($limit - $bid) / $limit * 100, 2),
            'entry_cost_bps' => round($entryCostBps, 2),
            'exit_cost_bps' => round($exitCostBps, 2),
            'round_trip_cost_bps' => round(($limit - $bid) / $limit * 10000, 2),
            'round_trip_budget_bps' => $budgetBps === null ? null : round($budgetBps, 2),
        ];
    }

    public static function limitAllowed(float $bid, float $ask, float $limit, float $hardStopPct, float $repegCapFrac, ?float $profitTargetMult = null, ?float $costBudgetFraction = null): bool
    {
        $priced = self::quote($bid, $ask, $hardStopPct, $repegCapFrac, $profitTargetMult, $costBudgetFraction);

        return isset($priced['limit']) && $limit >= ceil((($bid + $ask) / 2) * 100 - 0.000001) / 100
            && $limit <= $priced['limit'] + 0.000001;
    }
}
