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
        $cashEstimate = (new SettledCashEstimator)->fromBrokerTruth($portfolio, $equityOrders);

        $openOptionOrders = PortfolioReader::listFrom($client->getOptionOrders());
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
        $underlyingOpenDebit = [];
        foreach ($journalToday as $row) {
            $debit = (float) ($row->dollar_amount ?? 0);
            $spentToday += $debit;
            $key = strtoupper((string) $row->underlying_symbol);
            $underlyingOpenDebit[$key] = ($underlyingOpenDebit[$key] ?? 0.0) + $debit;
        }

        return [
            'account' => $account,
            'capability_tools' => $capabilityTools,
            'session_open' => (new SessionClock)->isRegularHoursOpen(),
            'settled_cash' => $cashEstimate['settled_cash'],
            'settled_cash_assumptions' => $cashEstimate['assumptions'],
            'options_spent_today_debit' => $spentToday,
            'underlying_open_debit' => $underlyingOpenDebit,
            'open_option_positions' => $openOptionPositions,
            'open_option_orders' => $openOptionOrders,
        ];
    }
}
