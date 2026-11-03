<?php

declare(strict_types=1);

namespace App\Robinhood\Basket;

/**
 * Deterministic rebalance diff + water-fill + fractionability gate (thesis
 * §11.4/§11.6). No model on this path; pure arrays in → plan out, so the
 * economics are unit-testable without a broker (mirrors BrokerTruthReconciler).
 *
 *   total_equity       = settled_cash + basket_MV            (broker truth)
 *   target_notional_i  = w_i × total_equity                  (§10 weight is the fraction)
 *   drift_i            = target_notional_i − current_MV_i
 *   buy_i              = min(max(0, drift_i), per_play_cap)   (BUY-ONLY V1; no trim)
 *   water-fill         = scale Σ buy_i down to available settled cash by desired drift
 *
 * Deposit → water-fill falls out: a deposit lands as settled cash and the next
 * pass spreads it across underweight names by weight. Selling is governed by
 * §10.5 only (deferred) — overweight names are held, flagged drift_trim.
 *
 * Gates honored at plan time: settled-cash-only (available is bounded by
 * settled cash, never buying power / unsettled proceeds), long-only (buys
 * only), per-play + per-day caps, and the per-name fractionability gate.
 * Session / review / kill-switch / idempotency gates are enforced by the
 * RailPipeline at arm/placement time — this planner never places.
 */
final class BasketRebalancePlanner
{
    private const EPSILON = 0.005;

    /**
     * @param array{
     *   settled_cash: float|null,
     *   positions?: array<string, array{shares: float, market_value: float}>,
     *   tradability?: array<string, bool|null>,
     *   prior_settled_cash_after?: float|null,
     *   trigger?: string,
     *   per_play_max_debit?: float,
     *   per_day_max_debit?: float,
     *   buy_threshold_usd?: float,
     *   min_leg_notional_usd?: float,
     *   logical_order_prefix?: string,
     *   book_mv?: float|null
     * } $params
     * @return array<string,mixed>
     */
    public function plan(BasketWeights $weights, array $params): array
    {
        $settledCash = $this->floatOrNull($params['settled_cash'] ?? null);
        $fundingSource = (string) ($params['funding_source'] ?? 'settled_cash');
        $funds = array_key_exists('available_funds', $params) ? $this->floatOrNull($params['available_funds']) : $settledCash;
        $positions = (array) ($params['positions'] ?? []);
        $tradability = (array) ($params['tradability'] ?? []);
        $priorAfter = $this->floatOrNull($params['prior_settled_cash_after'] ?? null);
        $trigger = (string) ($params['trigger'] ?? 'manual');
        $perPlayCap = (float) ($params['per_play_max_debit'] ?? 0.0);
        $perDayCap = (float) ($params['per_day_max_debit'] ?? 0.0);
        $buyThreshold = max(0.0, (float) ($params['buy_threshold_usd'] ?? 0.0));
        $minLegNotional = max(0.0, (float) ($params['min_leg_notional_usd'] ?? 0.0));
        $prefix = (string) ($params['logical_order_prefix'] ?? 'basket:');

        $basketTickers = array_flip($weights->tickers());

        // Broker-truth MV snapshot.
        $basketMv = 0.0;
        $bookMv = 0.0;
        foreach ($positions as $symbol => $pos) {
            $mv = (float) ($pos['market_value'] ?? 0.0);
            $bookMv += $mv;
            if (isset($basketTickers[strtoupper((string) $symbol)])) {
                $basketMv += $mv;
            }
        }
        $basketMv = round($basketMv, 2);
        $bookMv = round($bookMv, 2);
        if (isset($params['book_mv']) && is_numeric($params['book_mv'])) {
            $bookMv = round((float) $params['book_mv'], 2);
        }

        // Deposit detection from the ledger history (§11.5): a jump in settled
        // cash versus the prior pass's settled_cash_after is a deposit.
        $depositDetected = false;
        $depositAmount = null;
        if ($settledCash !== null && $priorAfter !== null && $settledCash > $priorAfter + self::EPSILON) {
            $depositDetected = true;
            $depositAmount = round($settledCash - $priorAfter, 2);
        } elseif ($trigger === 'deposit') {
            $depositDetected = true;
        }

        $cashUnverified = $funds === null;
        $totalEquity = $cashUnverified ? null : round($funds + $basketMv, 2);

        // Pass 1 — per-name diff + fractionability gate; mark buy candidates.
        $legs = [];
        $candidates = [];     // index → desired buy (pre water-fill)
        $desiredTotal = 0.0;
        foreach ($weights->rows as $row) {
            $ticker = $row['ticker'];
            $weight = (float) $row['weight'];
            $pos = $positions[$ticker] ?? ($positions[strtoupper($ticker)] ?? []);
            $shares = round((float) ($pos['shares'] ?? 0.0), 6);
            $curMv = round((float) ($pos['market_value'] ?? 0.0), 2);
            $target = $totalEquity === null ? 0.0 : round($weight * $totalEquity, 2);
            $drift = round($target - $curMv, 2);
            $fractionable = array_key_exists($ticker, $tradability)
                ? $this->boolOrNull($tradability[$ticker])
                : (array_key_exists(strtoupper($ticker), $tradability) ? $this->boolOrNull($tradability[strtoupper($ticker)]) : null);

            $leg = [
                'ticker' => $ticker,
                'logical_order_id' => $prefix.$ticker,
                'weight_target' => $weight,
                'notional_target' => $target,
                'position_before_shares' => $shares,
                'position_before_mv' => $curMv,
                'drift_usd' => $drift,
                'planned_buy_usd' => 0.0,
                'action' => 'hold',
                'reason' => 'at_target',
                'fractionable' => $fractionable,
                'plan_notes' => [],
            ];

            if ($cashUnverified) {
                $leg['reason'] = 'at_target';
                $leg['plan_notes'] = ['blocked' => 'settled_cash_unverified'];
                $legs[] = $leg;

                continue;
            }

            if ($fractionable === false) {
                $leg['action'] = 'drop';
                $leg['reason'] = 'dropped_untradable';
                $leg['plan_notes'] = ['fractionable' => false, 'drift_usd' => $drift];
                $legs[] = $leg;

                continue;
            }

            if ($fractionable === null) {
                // Unverified is not a definitive fail, but it is never a silent
                // admit — fail closed (hold), flag it, buy nothing this pass.
                $leg['action'] = 'hold';
                $leg['reason'] = 'at_target';
                $leg['plan_notes'] = ['blocked' => 'fractionability_unverified', 'drift_usd' => $drift];
                $legs[] = $leg;

                continue;
            }

            if ($drift <= $buyThreshold) {
                // Within band → at target. Meaningfully overweight → a trim is
                // indicated (§10.5), but BUY-ONLY V1 holds it.
                if ($drift < -$buyThreshold) {
                    $leg['reason'] = 'drift_trim';
                    $leg['plan_notes'] = ['overweight_hold' => true, 'trim_deferred_to_10_5' => true];
                } else {
                    $leg['plan_notes'] = ['within_buy_threshold' => $buyThreshold];
                }
                $legs[] = $leg;

                continue;
            }

            // Buy candidate. Cap the desired buy at the per-play bound.
            $desired = max(0.0, $drift);
            $perPlayApplied = false;
            if ($perPlayCap > 0.0 && $desired > $perPlayCap) {
                $desired = $perPlayCap;
                $perPlayApplied = true;
            }
            $desired = round($desired, 2);

            $leg['action'] = 'buy';
            $leg['reason'] = $curMv <= self::EPSILON ? 'new_admit' : 'deposit_fill';
            $leg['plan_notes'] = [
                'desired_buy_usd' => $desired,
                'per_play_cap_applied' => $perPlayApplied,
                'per_play_max_debit' => $perPlayCap,
            ];

            $index = count($legs);
            $legs[] = $leg;
            $candidates[$index] = $desired;
            $desiredTotal += $desired;
        }

        // Pass 2 — water-fill the buy candidates down to available settled cash.
        $available = 0.0;
        $waterfillScale = 1.0;
        if (! $cashUnverified) {
            $available = max(0.0, $funds);
            if ($perDayCap >= 0.0) {
                $available = min($available, $perDayCap);
            }
            $available = round($available, 2);

            if ($desiredTotal > self::EPSILON) {
                $waterfillScale = $desiredTotal <= $available ? 1.0 : ($available / $desiredTotal);
            }
        }

        $grossBuys = 0.0;
        foreach ($candidates as $index => $desired) {
            $planned = floor(($desired * $waterfillScale + 1e-9) * 100) / 100;

            // A buy that water-fills below the minimum placeable notional is
            // demoted to a hold — a sub-min fractional order is not placed.
            if ($planned < $minLegNotional - self::EPSILON) {
                $legs[$index]['action'] = 'hold';
                $legs[$index]['reason'] = 'at_target';
                $legs[$index]['plan_notes']['demoted'] = 'below_min_notional';
                $legs[$index]['plan_notes']['min_leg_notional_usd'] = $minLegNotional;
                $legs[$index]['plan_notes']['waterfilled_buy_usd'] = $planned;
                $legs[$index]['planned_buy_usd'] = 0.0;

                continue;
            }

            $legs[$index]['planned_buy_usd'] = $planned;
            $legs[$index]['plan_notes']['waterfill_scale'] = round($waterfillScale, 6);
            $grossBuys += $planned;
        }
        $grossBuys = round($grossBuys, 2);

        $status = $cashUnverified
            ? 'aborted'
            : 'planned';

        return [
            'as_of' => now()->toISOString(),
            'trigger' => $trigger,
            'status' => $status,
            'abort_reason' => $cashUnverified ? ($fundingSource === 'settled_cash' ? 'settled_cash_unverified' : 'buying_power_unverified') : null,
            'deposit_detected' => $depositDetected,
            'deposit_amount' => $depositAmount,
            'settled_cash_before' => $settledCash,
            'basket_mv_before' => $basketMv,
            'book_before' => $bookMv,
            'total_equity' => $totalEquity,
            'weights_source' => $weights->sourceId,
            'weights_payload_hash' => $weights->payloadHash,
            'spec_version' => $weights->version,
            'available_funds_for_pass' => round($available, 2),
            'waterfill_scale' => round($waterfillScale, 6),
            'n_legs' => count($legs),
            'gross_buys_usd' => $grossBuys,
            'gross_sells_usd' => 0.0,
            'cash_deployed' => $grossBuys,
            'settled_cash_after_planned' => $fundingSource === 'settled_cash' && ! $cashUnverified ? round($settledCash - $grossBuys, 2) : null,
            'funding_source' => $fundingSource,
            'funds_before' => $funds,
            'funds_after_planned' => $cashUnverified ? null : round($funds - $grossBuys, 2),
            'legs' => $legs,
        ];
    }

    /**
     * Normalize a get_equity_positions payload (list of rows) into a
     * symbol → {shares, market_value} map, broker truth. Mirrors the key
     * variants CockpitSnapshot handles; market value falls back to
     * shares × current_price when the row has no explicit MV.
     *
     * @param  list<array<string,mixed>>  $brokerPositions
     * @return array<string, array{shares: float, market_value: float}>
     */
    public static function normalizePositions(array $brokerPositions): array
    {
        $out = [];
        foreach ($brokerPositions as $p) {
            if (! is_array($p)) {
                continue;
            }
            $symbol = strtoupper((string) ($p['symbol'] ?? $p['chain_symbol'] ?? $p['ticker'] ?? ''));
            if ($symbol === '') {
                continue;
            }

            $shares = self::num($p, ['quantity', 'qty', 'position', 'shares']);
            $mv = self::numOrNull($p, ['market_value', 'equity', 'value']);
            if ($mv === null) {
                $price = self::numOrNull($p, ['current_price', 'last_price', 'price', 'mark_price']);
                if ($shares > 0 && $price === null) {
                    throw new \RuntimeException('position_market_value_unverified');
                }
                $mv = $price !== null ? round($shares * $price, 2) : 0.0;
            }

            // A symbol may appear once; if it repeats, aggregate shares + MV.
            if (isset($out[$symbol])) {
                $out[$symbol]['shares'] = round($out[$symbol]['shares'] + $shares, 6);
                $out[$symbol]['market_value'] = round($out[$symbol]['market_value'] + $mv, 2);
            } else {
                $out[$symbol] = ['shares' => round($shares, 6), 'market_value' => round($mv, 2)];
            }
        }

        return $out;
    }

    private function floatOrNull(mixed $v): ?float
    {
        return is_numeric($v) ? (float) $v : null;
    }

    private function boolOrNull(mixed $v): ?bool
    {
        if ($v === null) {
            return null;
        }

        return (bool) $v;
    }

    /** @param list<string> $keys */
    private static function num(array $row, array $keys): float
    {
        foreach ($keys as $key) {
            if (isset($row[$key]) && is_numeric($row[$key])) {
                return (float) $row[$key];
            }
        }

        return 0.0;
    }

    /** @param list<string> $keys */
    private static function numOrNull(array $row, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (isset($row[$key]) && is_numeric($row[$key])) {
                return (float) $row[$key];
            }
        }

        return null;
    }
}
