<?php

declare(strict_types=1);

namespace App\Robinhood\Basket;

use App\Robinhood\Execution\SettledCashEstimator;
use App\Robinhood\Mcp\PortfolioReader;
use App\Robinhood\Mcp\RobinhoodMcpClient;
use Illuminate\Support\Carbon;
use RuntimeException;

/** Complete account reads; no missing pages or missing valuations become zero. */
final class BasketBrokerTruth
{
    public function read(RobinhoodMcpClient $client, array $tickers): array
    {
        $portfolio = $client->getPortfolio();
        $buyingPower = PortfolioReader::buyingPower($portfolio);
        $rawUnleveraged = data_get(PortfolioReader::unwrap($portfolio), 'buying_power.unleveraged_buying_power');
        $unleveraged = is_numeric($rawUnleveraged) ? (float) $rawUnleveraged : null;
        $positions = $this->pages(fn ($filters) => $client->getEquityPositions($filters));
        $equities = $this->pages(fn ($filters) => $client->getAllEquityOrders($filters));
        $options = $this->pages(fn ($filters) => $client->getAllOptionOrders($filters));
        $cash = (new SettledCashEstimator)->fromBrokerTruth($portfolio, $equities, null, $options);
        $symbols = [];
        foreach ($positions as $position) {
            if ((float) ($position['quantity'] ?? $position['shares'] ?? 0) <= 0) {
                continue;
            }
            $symbol = strtoupper((string) ($position['symbol'] ?? $position['ticker'] ?? ''));
            if ($symbol === '') {
                throw new RuntimeException('position_symbol_unverified');
            }
            $symbols[] = $symbol;
        }
        $quotes = [];
        foreach (array_chunk(array_unique($symbols), 10) as $chunk) {
            foreach ($this->rows($client->getEquityQuotes($chunk), ['quotes', 'results']) as $row) {
                $symbol = strtoupper((string) ($row['symbol'] ?? ''));
                $bid = $row['bid_price'] ?? null;
                $ask = $row['ask_price'] ?? null;
                $stamp = $row['updated_at'] ?? null;
                if (is_numeric($bid) && is_numeric($ask) && (float) $bid > 0 && (float) $ask >= (float) $bid && is_string($stamp)
                    && abs(now()->getTimestamp() - Carbon::parse($stamp)->getTimestamp()) <= 120) {
                    $quotes[$symbol] = ((float) $bid + (float) $ask) / 2;
                }
            }
        }
        foreach ($positions as &$position) {
            $shares = (float) ($position['quantity'] ?? $position['shares'] ?? 0);
            if ($shares <= 0) {
                continue;
            }
            $symbol = strtoupper((string) ($position['symbol'] ?? $position['ticker'] ?? ''));
            if (isset($quotes[$symbol])) {
                $position['market_value'] = round($shares * $quotes[$symbol], 2);
            } elseif (! is_numeric($position['market_value'] ?? null)) {
                throw new RuntimeException('fresh_position_value_unverified');
            }
        }
        unset($position);
        $tradability = array_fill_keys($tickers, null);
        foreach (array_chunk($tickers, 10) as $chunk) {
            foreach ($this->rows($client->getEquityTradability($chunk), ['results', 'tradability', 'instruments']) as $row) {
                $symbol = strtoupper((string) ($row['symbol'] ?? ''));
                if (array_key_exists($symbol, $tradability)) {
                    $tradability[$symbol] = ($row['tradeable'] ?? null) === true && ($row['state'] ?? null) === 'active'
                        && ($row['fractional_tradability'] ?? null) === 'tradable';
                }
            }
        }

        return ['source' => 'live', 'broker_buying_power' => $buyingPower, 'unleveraged_buying_power' => $unleveraged, 'settled_cash' => $cash['settled_cash'], 'positions' => BasketRebalancePlanner::normalizePositions($positions),
            'positions_raw' => $positions, 'orders' => array_merge($equities, $options), 'tradability' => $tradability];
    }

    private function pages(callable $read): array
    {
        $rows = [];
        $filters = [];
        $seen = [];
        for ($page = 0; $page < 20; $page++) {
            $payload = PortfolioReader::unwrap($read($filters));
            $rows = array_merge($rows, $this->rows($payload));
            $next = $payload['next_cursor'] ?? $payload['next'] ?? data_get($payload, 'pagination.next_cursor');
            if ($next === null || $next === '') {
                return $rows;
            }
            if (! is_string($next)) {
                throw new RuntimeException('pagination_cursor_unverified');
            }
            if (str_contains($next, '://')) {
                parse_str((string) parse_url($next, PHP_URL_QUERY), $query);
                $next = $query['cursor'] ?? null;
            }
            if (! is_string($next) || $next === '' || isset($seen[$next])) {
                throw new RuntimeException('pagination_incomplete');
            }
            $seen[$next] = true;
            $filters = ['cursor' => $next];
        }
        throw new RuntimeException('pagination_limit_exceeded');
    }

    private function rows(array $payload, array $keys = ['orders', 'results', 'positions', 'items']): array
    {
        $payload = PortfolioReader::unwrap($payload);
        if (array_is_list($payload)) {
            return $payload;
        }
        foreach ($keys as $key) {
            if (isset($payload[$key]) && is_array($payload[$key]) && array_is_list($payload[$key])) {
                return $payload[$key];
            }
        }
        throw new RuntimeException('broker_list_shape_unverified');
    }
}
