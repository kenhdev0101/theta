<?php

declare(strict_types=1);

namespace App\Robinhood\Options;

use App\Models\RobinhoodTradeJournal;
use App\Robinhood\Execution\SettledCashEstimator;
use App\Robinhood\Mcp\PortfolioReader;
use App\Robinhood\Mcp\RobinhoodMcpClient;
use App\Robinhood\Rails\SessionClock;

/**
 * Assembles the broker/account truth snapshot the option rails consume.
 * Fields that cannot be verified stay null so the corresponding gate
 * blocks with a named reason instead of assuming safety.
 */
final class OptionBrokerContext
{
    public static function assemble(RobinhoodMcpClient $client, array $cfg): array
    {
        $portfolio = $client->getPortfolio();
        // Settlement truth spans both lanes: equity sells fund option buys.
        $equityOrders = PortfolioReader::listFrom($client->getEquityOrders());
        $openOptionOrders = PortfolioReader::listFrom($client->getOptionOrders());
        $cashEstimate = (new SettledCashEstimator)->fromBrokerTruth($portfolio, $equityOrders, optionOrders: $openOptionOrders);
        $openOptionPositions = PortfolioReader::listFrom($client->getOptionPositions(nonzero: true));

        $account = null;

        try {
            $account = $client->getAccountCapability();
        } catch (\Throwable) {
            // Stays null -> permission gate blocks with account_capability_unverified.
        }

        $capabilityTools = null;

        try {
            $capabilityTools = array_map(
                fn (array $tool) => (string) ($tool['name'] ?? ''),
                $client->listTools(),
            );
        } catch (\Throwable) {
            // Stays null -> capability presence is simply not asserted.
        }

        $today = now()->toDateString();
        $journalToday = RobinhoodTradeJournal::query()
            ->where('asset_class', 'option')
            ->where('side', 'buy')
            ->whereIn('status', ['submitted', 'open', 'partially_filled', 'filled'])
            ->whereDate('created_at', $today)
            ->get(['underlying_symbol', 'dollar_amount']);

        $spentToday = 0.0;
        foreach ($journalToday as $row) {
            $debit = (float) ($row->dollar_amount ?? 0);
            $spentToday += $debit;
        }

        return [
            'account' => $account,
            'capability_tools' => $capabilityTools,
            'session_open' => (new SessionClock)->isRegularHoursOpen(),
            'settled_cash' => $cashEstimate['settled_cash'],
            'settled_cash_assumptions' => $cashEstimate['assumptions'],
            'buying_power' => PortfolioReader::buyingPower($portfolio),
            'options_spent_today_debit' => $spentToday,
            'underlying_open_debit' => self::underlyingOpenDebitFromPositions($openOptionPositions),
            'options_open_debit' => array_sum(self::underlyingOpenDebitFromPositions($openOptionPositions)),
            'open_option_positions' => $openOptionPositions,
            'open_option_orders' => $openOptionOrders,
        ];
    }

    private static function underlyingOpenDebitFromPositions(array $positions): array
    {
        $debits = [];
        foreach ($positions as $position) {
            if (! is_array($position)) {
                continue;
            }

            $symbol = strtoupper((string) (
                data_get($position, 'underlying_symbol')
                ?? data_get($position, 'chain_symbol')
                ?? data_get($position, 'symbol')
                ?? data_get($position, 'option.chain_symbol')
                ?? ''
            ));
            $quantity = self::numeric($position, ['quantity', 'quantity_available', 'open_quantity']);
            $averagePrice = self::averageContractPrice($position);
            $multiplier = self::numeric($position, ['trade_value_multiplier', 'multiplier', 'contract_multiplier']) ?: 100.0;

            if ($symbol === '' || $quantity <= 0.0 || $averagePrice <= 0.0) {
                continue;
            }

            $debits[$symbol] = round(($debits[$symbol] ?? 0.0) + ($quantity * $averagePrice * $multiplier), 2);
        }

        return $debits;
    }

    private static function averageContractPrice(array $position): float
    {
        $price = self::numeric($position, ['average_price', 'average_open_price', 'average_cost', 'avg_price']);
        if ($price <= 0.0) {
            return 0.0;
        }

        // Some broker position payloads report total premium dollars per
        // contract (e.g. 63.00), while quotes are per-share option prices
        // (e.g. 0.63). Normalize the total-premium shape for cap math.
        return $price > 20.0 ? round($price / 100.0, 8) : $price;
    }

    private static function numeric(array $row, array $keys): float
    {
        foreach ($keys as $key) {
            $value = data_get($row, $key);
            if (is_numeric($value)) {
                return (float) $value;
            }
        }

        return 0.0;
    }
}
