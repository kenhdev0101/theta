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
 *   settled = cash − Σ(filled sell proceeds whose T+1 settlement date
 *                      is still in the future)
 *
 * Equity settlement is T+1 (next business day after the trade date).
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
     * @return array{settled_cash: float|null, unsettled_sell_proceeds: float, assumptions: array}
     */
    public function fromBrokerTruth(array $portfolio, array $brokerOrders, ?Carbon $today = null): array
    {
        $explicit = PortfolioReader::settledCash($portfolio);
        if ($explicit !== null) {
            return [
                'settled_cash' => $explicit,
                'unsettled_sell_proceeds' => 0.0,
                'assumptions' => ['source' => 'broker_explicit_settled_cash'],
            ];
        }

        return $this->estimate(PortfolioReader::cash($portfolio), $brokerOrders, $today);
    }

    /**
     * @param  float|null  $cash  total cash from get_portfolio (null = unverified)
     * @param  list<array>  $brokerOrders  rows from get_equity_orders(placed_agent='agentic')
     * @return array{settled_cash: float|null, unsettled_sell_proceeds: float, assumptions: array}
     */
    public function estimate(?float $cash, array $brokerOrders, ?Carbon $today = null): array
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
            if (strtolower((string) ($order['side'] ?? '')) !== 'sell') {
                continue;
            }

            $state = strtolower((string) ($order['state'] ?? $order['status'] ?? ''));
            if (! in_array($state, self::FILLED_STATES, true)) {
                continue;
            }

            $qty = $this->numeric($order, ['cumulative_quantity', 'filled_quantity', 'filled_qty']);
            $price = $this->numeric($order, ['average_price', 'average_fill_price', 'fill_price']);
            if ($qty <= 0 || $price <= 0) {
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
                $proceeds = round($qty * $price - $this->numeric($order, ['fees']), 2);
                $unsettled += $proceeds;
                $unsettledRows[] = [
                    'order_id' => $order['id'] ?? $order['order_id'] ?? null,
                    'symbol' => $order['symbol'] ?? null,
                    'proceeds' => $proceeds,
                    'settles_at' => $settlesAt->toDateString(),
                ];
            }
        }

        return [
            'settled_cash' => round(max(0.0, $cash - $unsettled), 2),
            'unsettled_sell_proceeds' => round($unsettled, 2),
            'assumptions' => [
                'model' => 't_plus_1_business_days',
                'holidays_modeled' => false,
                'as_of' => $today->toDateString(),
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
}
