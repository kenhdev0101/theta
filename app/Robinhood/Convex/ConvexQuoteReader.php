<?php

declare(strict_types=1);

namespace App\Robinhood\Convex;

use App\Robinhood\Mcp\PortfolioReader;
use App\Robinhood\Mcp\RobinhoodMcpClient;
use DateTimeImmutable;
use Illuminate\Support\Carbon;

/** Exact broker option quote with an absolute, validated observation time. */
final class ConvexQuoteReader
{
    public function __construct(private readonly RobinhoodMcpClient $client, private readonly array $cfg) {}

    public function forOptionId(string $optionId): ?array
    {
        $results = PortfolioReader::listFrom($this->client->getOptionQuotes([$optionId]), ['results']);
        foreach ($results as $result) {
            $quote = is_array($result) ? ($result['quote'] ?? null) : null;
            if (! is_array($quote) || (string) ($quote['instrument_id'] ?? '') !== $optionId
                || ! is_numeric($quote['bid_price'] ?? null) || ! is_numeric($quote['ask_price'] ?? null)
                || ! is_string($quote['updated_at'] ?? null)) {
                continue;
            }
            $bid = (float) $quote['bid_price'];
            $ask = (float) $quote['ask_price'];
            if ($bid <= 0 || $ask < $bid) {
                continue;
            }
            $stamp = $quote['updated_at'];
            if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,9})?(?:Z|[+-]\d{2}:\d{2})$/', $stamp) !== 1) {
                continue;
            }
            try {
                $parsed = new DateTimeImmutable($stamp);
                $errors = DateTimeImmutable::getLastErrors();
                if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
                    continue;
                }
                $age = abs(Carbon::instance($parsed)->diffInSeconds(now()));
            } catch (\Throwable) {
                continue;
            }
            $mid = ($bid + $ask) / 2;

            return [
                'bid' => $bid, 'ask' => $ask, 'mid' => $mid,
                'spread_bps' => round(($ask - $bid) / $mid * 10000, 2),
                'updated_at' => $quote['updated_at'], 'age_seconds' => $age,
                'stale' => $age > (int) data_get($this->cfg, 'options.min_quote_fresh_seconds', 10),
            ];
        }

        return null;
    }
}
