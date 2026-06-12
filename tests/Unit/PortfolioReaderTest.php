<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Robinhood\Mcp\PortfolioReader;
use PHPUnit\Framework\TestCase;

/**
 * Pinned to the live-measured get_portfolio / get_equity_orders shapes
 * (2026-06-12): `data` envelope, string-numeric cash, nested buying_power.
 */
class PortfolioReaderTest extends TestCase
{
    private function livePortfolioShape(): array
    {
        return [
            'data' => [
                'total_value' => '301.08',
                'equity_value' => '55.25',
                'cash' => '245.83',
                'pending_deposits' => '0',
                'currency' => 'USD',
                'buying_power' => [
                    'buying_power' => '245.8300',
                    'unleveraged_buying_power' => '245.8300',
                    'display_currency' => 'USD',
                ],
            ],
            'guide' => 'model-facing guidance text — never parsed',
        ];
    }

    public function test_cash_and_buying_power_extract_from_live_shape(): void
    {
        $portfolio = $this->livePortfolioShape();

        $this->assertSame(245.83, PortfolioReader::cash($portfolio));
        $this->assertSame(245.83, PortfolioReader::buyingPower($portfolio));
    }

    public function test_settled_cash_is_null_on_live_shape_not_guessed_from_cash(): void
    {
        $this->assertNull(PortfolioReader::settledCash($this->livePortfolioShape()));
    }

    public function test_explicit_settled_cash_passes_through(): void
    {
        $this->assertSame(12.5, PortfolioReader::settledCash(['data' => ['settled_cash' => '12.50']]));
    }

    public function test_list_from_unwraps_data_envelope(): void
    {
        $payload = ['data' => ['orders' => [['id' => 'a'], ['id' => 'b']]]];

        $this->assertCount(2, PortfolioReader::listFrom($payload));
        $this->assertSame('a', PortfolioReader::listFrom($payload)[0]['id']);
    }

    public function test_list_from_handles_unwrapped_and_bare_shapes(): void
    {
        $this->assertCount(1, PortfolioReader::listFrom(['orders' => [['id' => 'x']]]));
        $this->assertCount(1, PortfolioReader::listFrom(['positions' => [['symbol' => 'GE']]]));
        $this->assertCount(2, PortfolioReader::listFrom([['id' => 1], ['id' => 2]]));
        $this->assertSame([], PortfolioReader::listFrom(['data' => ['unrelated' => true]]));
    }

    public function test_missing_fields_stay_null_never_zero(): void
    {
        $this->assertNull(PortfolioReader::cash(['data' => []]));
        $this->assertNull(PortfolioReader::buyingPower(['data' => []]));
        $this->assertNull(PortfolioReader::settledCash([]));
    }
}
