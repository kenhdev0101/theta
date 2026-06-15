<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Robinhood\Execution\SettledCashEstimator;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

class SettledCashEstimatorTest extends TestCase
{
    private SettledCashEstimator $estimator;

    protected function setUp(): void
    {
        $this->estimator = new SettledCashEstimator;
    }

    private function sell(string $tradedAt, string $qty = '1', string $price = '100', string $fees = '0'): array
    {
        return [
            'id' => 'o-'.md5($tradedAt.$qty.$price),
            'symbol' => 'GE',
            'side' => 'sell',
            'state' => 'filled',
            'cumulative_quantity' => $qty,
            'average_price' => $price,
            'fees' => $fees,
            'last_transaction_at' => $tradedAt,
        ];
    }

    private function optionSell(string $tradedAt, string $qty = '1', string $price = '0.35', string $fees = '0'): array
    {
        return [
            'id' => 'opt-'.md5($tradedAt.$qty.$price),
            'chain_symbol' => 'SPY',
            'asset_class' => 'option',
            'state' => 'filled',
            'processed_quantity' => $qty,
            'average_price' => $price,
            'trade_value_multiplier' => '100',
            'fees' => $fees,
            'last_transaction_at' => $tradedAt,
            'legs' => [[
                'side' => 'sell',
                'position_effect' => 'close',
                'option_id' => 'opt-spy',
            ]],
        ];
    }

    public function test_settled_sells_do_not_reduce_settled_cash(): void
    {
        // Trade Wednesday 06-10 -> settles Thursday 06-11; as of Friday 06-12 it is settled.
        $result = $this->estimator->estimate(245.83, [$this->sell('2026-06-10T18:32:04Z')], Carbon::parse('2026-06-12'));

        $this->assertSame(245.83, $result['settled_cash']);
        $this->assertSame(0.0, $result['unsettled_sell_proceeds']);
    }

    public function test_same_day_sell_proceeds_are_unsettled(): void
    {
        $result = $this->estimator->estimate(245.83, [$this->sell('2026-06-12T14:00:00Z', '1', '45.50')], Carbon::parse('2026-06-12'));

        $this->assertSame(200.33, $result['settled_cash']);
        $this->assertSame(45.5, $result['unsettled_sell_proceeds']);
        $this->assertCount(1, $result['assumptions']['unsettled_rows']);
    }

    public function test_settlement_date_counts_as_settled(): void
    {
        // Trade Thursday 06-11 -> settles Friday 06-12; settled as of 06-12.
        $result = $this->estimator->estimate(100.0, [$this->sell('2026-06-11T15:00:00Z')], Carbon::parse('2026-06-12'));

        $this->assertSame(100.0, $result['settled_cash']);
    }

    public function test_friday_sell_settles_monday_not_over_the_weekend(): void
    {
        // 2026-06-12 is a Friday; T+1 business day is Monday 06-15.
        $fridaySell = [$this->sell('2026-06-12T19:00:00Z', '2', '50')];

        $sunday = $this->estimator->estimate(150.0, $fridaySell, Carbon::parse('2026-06-14'));
        $this->assertSame(50.0, $sunday['settled_cash']);
        $this->assertSame(100.0, $sunday['unsettled_sell_proceeds']);

        $monday = $this->estimator->estimate(150.0, $fridaySell, Carbon::parse('2026-06-15'));
        $this->assertSame(150.0, $monday['settled_cash']);
    }

    public function test_same_day_option_sell_proceeds_are_unsettled(): void
    {
        $result = $this->estimator->estimate(245.83, [$this->optionSell('2026-06-12T18:00:00Z', '1', '0.35')], Carbon::parse('2026-06-12'));

        $this->assertSame(210.83, $result['settled_cash']);
        $this->assertSame(35.0, $result['unsettled_sell_proceeds']);
        $this->assertSame('option', $result['assumptions']['unsettled_rows'][0]['asset_class']);
    }

    public function test_friday_option_sell_settles_monday_not_over_the_weekend(): void
    {
        $fridaySell = [$this->optionSell('2026-06-12T19:00:00Z', '2', '0.50')];

        $sunday = $this->estimator->estimate(150.0, $fridaySell, Carbon::parse('2026-06-14'));
        $this->assertSame(50.0, $sunday['settled_cash']);
        $this->assertSame(100.0, $sunday['unsettled_sell_proceeds']);

        $monday = $this->estimator->estimate(150.0, $fridaySell, Carbon::parse('2026-06-15'));
        $this->assertSame(150.0, $monday['settled_cash']);
    }

    public function test_unverified_cash_stays_unverified(): void
    {
        $result = $this->estimator->estimate(null, [$this->sell('2026-06-12T14:00:00Z')], Carbon::parse('2026-06-12'));

        $this->assertNull($result['settled_cash']);
        $this->assertTrue($result['assumptions']['cash_unverified']);
    }

    public function test_sell_with_unknown_trade_date_is_treated_as_unsettled(): void
    {
        $sell = $this->sell('2026-06-01T00:00:00Z');
        unset($sell['last_transaction_at']);

        $result = $this->estimator->estimate(200.0, [$sell], Carbon::parse('2026-06-12'));

        $this->assertSame(100.0, $result['settled_cash']);
    }

    public function test_buys_and_unfilled_sells_are_ignored(): void
    {
        $buy = $this->sell('2026-06-12T14:00:00Z');
        $buy['side'] = 'buy';

        $pending = $this->sell('2026-06-12T14:00:00Z');
        $pending['state'] = 'queued';

        $result = $this->estimator->estimate(100.0, [$buy, $pending], Carbon::parse('2026-06-12'));

        $this->assertSame(100.0, $result['settled_cash']);
    }

    public function test_fees_reduce_unsettled_proceeds_and_floor_is_zero(): void
    {
        $result = $this->estimator->estimate(10.0, [$this->sell('2026-06-12T14:00:00Z', '1', '100', '0.02')], Carbon::parse('2026-06-12'));

        $this->assertSame(0.0, $result['settled_cash']);
        $this->assertSame(99.98, $result['unsettled_sell_proceeds']);
    }

    public function test_from_broker_truth_prefers_explicit_settled_cash(): void
    {
        $result = $this->estimator->fromBrokerTruth(
            ['data' => ['settled_cash' => '77.70', 'cash' => '245.83']],
            [$this->sell('2026-06-12T14:00:00Z')],
            Carbon::parse('2026-06-12'),
        );

        $this->assertSame(77.7, $result['settled_cash']);
        $this->assertSame('broker_explicit_settled_cash', $result['assumptions']['source']);
    }

    public function test_from_broker_truth_estimates_from_live_shape(): void
    {
        $portfolio = ['data' => ['cash' => '245.83', 'buying_power' => ['buying_power' => '245.8300']]];

        $result = $this->estimator->fromBrokerTruth($portfolio, [$this->sell('2026-06-12T14:00:00Z', '1', '45.50')], Carbon::parse('2026-06-12'));

        $this->assertSame(200.33, $result['settled_cash']);
        $this->assertSame('t_plus_1_business_days', $result['assumptions']['model']);
    }

    public function test_from_broker_truth_includes_option_orders(): void
    {
        $portfolio = ['data' => ['cash' => '245.83', 'buying_power' => ['buying_power' => '245.8300']]];

        $result = $this->estimator->fromBrokerTruth(
            $portfolio,
            [],
            Carbon::parse('2026-06-12'),
            [$this->optionSell('2026-06-12T18:00:00Z', '1', '0.35')],
        );

        $this->assertSame(210.83, $result['settled_cash']);
        $this->assertSame(35.0, $result['unsettled_sell_proceeds']);
    }
}
