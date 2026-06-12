<?php

declare(strict_types=1);

namespace App\Robinhood\Mcp;

/**
 * Deterministic extraction helpers over MCP payload shapes. Missing fields
 * return null — never a default that could read as "verified".
 *
 * Live-measured payload shapes (2026-06-12): every tool result nests under
 * a `data` wrapper; get_portfolio exposes string-numeric `cash` and a nested
 * `buying_power.buying_power`; get_equity_orders nests `data.orders` with
 * `state`, `cumulative_quantity`, `average_price`, `fees`,
 * `last_transaction_at`, `placed_agent`.
 */
final class PortfolioReader
{
    /** Unwrap the MCP `data` envelope (associative only — a bare list is payload, not envelope). */
    public static function unwrap(array $payload): array
    {
        while (isset($payload['data']) && is_array($payload['data']) && ! array_is_list($payload['data'])) {
            $payload = $payload['data'];
        }

        return $payload;
    }

    /**
     * Explicit settled-cash truth from a get_portfolio payload. The live
     * surface does not expose a settled/unsettled split, so this returns
     * null there — SettledCashEstimator derives it from cash + order
     * history. Null means unverified; gates block, never assume.
     */
    public static function settledCash(array $portfolio): ?float
    {
        $portfolio = self::unwrap($portfolio);

        foreach (['settled_cash', 'cash_settled', 'settled_funds'] as $key) {
            if (isset($portfolio[$key]) && is_numeric($portfolio[$key])) {
                return (float) $portfolio[$key];
            }
        }

        return null;
    }

    /** Total cash from get_portfolio (includes any unsettled proceeds). */
    public static function cash(array $portfolio): ?float
    {
        $portfolio = self::unwrap($portfolio);

        if (isset($portfolio['cash']) && is_numeric($portfolio['cash'])) {
            return (float) $portfolio['cash'];
        }

        return null;
    }

    /** Buying power from get_portfolio (never get_accounts). */
    public static function buyingPower(array $portfolio): ?float
    {
        $portfolio = self::unwrap($portfolio);

        $value = $portfolio['buying_power'] ?? null;

        // Live shape: buying_power is an object with its own buying_power key.
        if (is_array($value) && isset($value['buying_power']) && is_numeric($value['buying_power'])) {
            return (float) $value['buying_power'];
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        foreach (['equity_buying_power', 'cash_available_for_trading'] as $key) {
            if (isset($portfolio[$key]) && is_numeric($portfolio[$key])) {
                return (float) $portfolio[$key];
            }
        }

        return null;
    }

    /** Unwrap a list payload that may arrive as {data:{orders: []}}, {orders: []}, or a bare list. */
    public static function listFrom(array $payload, array $keys = ['orders', 'results', 'positions', 'items']): array
    {
        $payload = self::unwrap($payload);

        foreach ($keys as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                return array_values($payload[$key]);
            }
        }

        return array_is_list($payload) ? $payload : [];
    }
}
