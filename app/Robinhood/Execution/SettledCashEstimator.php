<?php

declare(strict_types=1);

namespace App\Robinhood\Execution;

use App\Robinhood\Mcp\PortfolioReader;
use Illuminate\Support\Carbon;

/**
 * Deterministic settled-cash estimate for the cash account.
 *
 * The live get_portfolio surface exposes total `cash` but no
 * settled/unsettled split, so settled cash is derived from broker truth:
 *
 *   settled = cash − pending deposits − Σ(filled sell proceeds whose T+1
 *                                        settlement date is still in the future)
 *
 * Equity and listed option settlement is T+1 (next business day after the
 * trade date).
 * Market holidays are not modeled; on a holiday-shortened week this
 * overstates settledness by one day, bounded by the per-play/per-day caps.
 * Every estimate carries its assumptions so the journal can audit it.
 */
final class SettledCashEstimator
{
    private const FILLED_STATES = ['filled', 'partially_filled'];

    /**
     * Preferred entry point: use the broker's explicit settled-cash field
     * when the surface exposes one; otherwise estimate from cash + orders.
     *
     * @param  list<array>  $brokerOrders
     * @param  list<array>  $optionOrders
     * @return array{settled_cash: float|null, unsettled_sell_proceeds: float, assumptions: array}
     */
    public function fromBrokerTruth(array $portfolio, array $brokerOrders, ?Carbon $today = null, array $optionOrders = []): array
    {
        $explicit = PortfolioReader::settledCash($portfolio);
        if ($explicit !== null) {
            return [
                'settled_cash' => $explicit,
                'unsettled_sell_proceeds' => 0.0,
                'assumptions' => ['source' => 'broker_explicit_settled_cash'],
            ];
        }

        $unwrapped = PortfolioReader::unwrap($portfolio);
        $pending = $unwrapped['pending_deposits'] ?? 0;
        if (! is_numeric($pending) || (float) $pending < 0) {
            return [
                'settled_cash' => null,
                'unsettled_sell_proceeds' => 0.0,
                'assumptions' => ['pending_deposits_unverified' => true],
            ];
        }

        return $this->estimate(PortfolioReader::cash($portfolio), array_merge($brokerOrders, $optionOrders), $today, (float) $pending);
    }

    /**
     * @param  float|null  $cash  total cash from get_portfolio (null = unverified)
     * @param  list<array>  $brokerOrders  rows from get_equity_orders/get_option_orders(placed_agent='agentic')
     * @return array{settled_cash: float|null, unsettled_sell_proceeds: float, assumptions: array}
     */
    public function estimate(?float $cash, array $brokerOrders, ?Carbon $today = null, float $pendingDeposits = 0.0): array
    {
        $today = ($today ?? now())->copy()->startOfDay();

        if ($cash === null) {
            return [
                'settled_cash' => null,
                'unsettled_sell_proceeds' => 0.0,
                'assumptions' => ['cash_unverified' => true],
            ];
        }

        $unsettled = 0.0;
        $unsettledRows = [];

        foreach ($brokerOrders as $order) {
            if ($this->side($order) !== 'sell') {
                continue;
            }

            $state = strtolower((string) ($order['state'] ?? $order['status'] ?? ''));
            if (! in_array($state, self::FILLED_STATES, true)) {
                continue;
            }

            $proceeds = $this->proceeds($order);
            if ($proceeds <= 0) {
                continue;
            }

            $tradedAt = $order['last_transaction_at'] ?? $order['filled_at'] ?? $order['created_at'] ?? null;
            if ($tradedAt === null) {
                // Unknown trade date: treat the proceeds as unsettled (fail closed).
                $settlesAt = $today->copy()->addDay();
            } else {
                $settlesAt = $this->nextBusinessDay(Carbon::parse((string) $tradedAt)->startOfDay());
            }

            if ($settlesAt->greaterThan($today)) {
                $unsettled += $proceeds;
                $unsettledRows[] = [
                    'order_id' => $order['id'] ?? $order['order_id'] ?? null,
                    'symbol' => $order['symbol'] ?? $order['chain_symbol'] ?? null,
                    'proceeds' => $proceeds,
                    'settles_at' => $settlesAt->toDateString(),
                    'asset_class' => $this->assetClass($order),
                ];
            }
        }

        return [
            'settled_cash' => round(max(0.0, $cash - $pendingDeposits - $unsettled), 2),
            'unsettled_sell_proceeds' => round($unsettled, 2),
            'assumptions' => [
                'model' => 't_plus_1_business_days',
                'holidays_modeled' => false,
                'as_of' => $today->toDateString(),
                'pending_deposits_excluded' => round($pendingDeposits, 2),
                'unsettled_rows' => $unsettledRows,
            ],
        ];
    }

    private function nextBusinessDay(Carbon $tradeDate): Carbon
    {
        $day = $tradeDate->copy()->addDay();
        while ($day->isWeekend()) {
            $day->addDay();
        }

        return $day;
    }

    private function numeric(array $row, array $keys): float
    {
        foreach ($keys as $key) {
            if (isset($row[$key]) && is_numeric($row[$key])) {
                return (float) $row[$key];
            }
        }

        return 0.0;
    }

    private function proceeds(array $order): float
    {
        $quantity = $this->numeric($order, ['cumulative_quantity', 'filled_quantity', 'filled_qty', 'processed_quantity']);
        $price = $this->numeric($order, ['average_price', 'average_fill_price', 'fill_price', 'processed_premium_per_contract']);

        if ($price <= 0.0) {
            $execution = $this->firstLeg($order)['executions'][0] ?? null;
            if (is_array($execution)) {
                $price = $this->numeric($execution, ['price']);
            }
        }

        if ($quantity <= 0.0 || $price <= 0.0) {
            $premium = $this->numeric($order, ['processed_premium', 'premium']);

            return $premium > 0.0 ? round($premium - $this->numeric($order, ['fees']), 2) : 0.0;
        }

        $multiplier = $this->assetClass($order) === 'option'
            ? ($this->numeric($order, ['trade_value_multiplier', 'multiplier', 'contract_multiplier']) ?: 100.0)
            : 1.0;

        return round($quantity * $price * $multiplier - $this->numeric($order, ['fees']), 2);
    }

    private function side(array $order): string
    {
        return strtolower((string) ($this->firstLeg($order)['side'] ?? $order['side'] ?? ''));
    }

    private function assetClass(array $order): string
    {
        if (($order['asset_class'] ?? null) === 'option' || isset($order['legs']) || isset($order['option_id']) || isset($order['processed_premium'])) {
            return 'option';
        }

        return 'equity';
    }

    private function firstLeg(array $order): array
    {
        $legs = $order['legs'] ?? null;
        $leg = is_array($legs) ? ($legs[0] ?? null) : null;

        return is_array($leg) ? $leg : [];
    }
}
