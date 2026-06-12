<?php

declare(strict_types=1);

namespace App\Robinhood\Mcp;

/**
 * Deterministic extraction helpers over MCP payload shapes. Missing fields
 * return null — never a default that could read as "verified".
 */
final class PortfolioReader
{
    /**
     * Settled cash truth from a get_portfolio payload. Null means
     * unverified, which downstream gates treat as a block, not as zero.
     */
    public static function settledCash(array $portfolio): ?float
    {
        foreach (['settled_cash', 'cash_settled', 'settled_funds'] as $key) {
            if (isset($portfolio[$key]) && is_numeric($portfolio[$key])) {
                return (float) $portfolio[$key];
            }
        }

        // Nested account/cash blocks seen on portfolio-style payloads.
        foreach (['cash', 'account', 'portfolio'] as $block) {
            $nested = $portfolio[$block] ?? null;
            if (is_array($nested)) {
                $value = self::settledCash($nested);
                if ($value !== null) {
                    return $value;
                }
            }
        }

        return null;
    }

    /** Buying power from get_portfolio (never get_accounts). */
    public static function buyingPower(array $portfolio): ?float
    {
        foreach (['buying_power', 'equity_buying_power', 'cash_available_for_trading'] as $key) {
            if (isset($portfolio[$key]) && is_numeric($portfolio[$key])) {
                return (float) $portfolio[$key];
            }
        }

        return null;
    }

    /** Unwrap a list payload that may arrive as {orders: []}, {results: []}, or a bare list. */
    public static function listFrom(array $payload, array $keys = ['orders', 'results', 'positions', 'data', 'items']): array
    {
        foreach ($keys as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                return array_values($payload[$key]);
            }
        }

        return array_is_list($payload) ? $payload : [];
    }
}
