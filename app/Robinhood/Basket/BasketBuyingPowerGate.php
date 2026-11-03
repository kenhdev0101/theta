<?php

declare(strict_types=1);

namespace App\Robinhood\Basket;

use App\Robinhood\Rails\GateResult;
use App\Robinhood\Rails\Gates\GateInterface;
use App\Robinhood\Rails\OrderIntent;

/** Desk-authorized basket funding; never applies to another strategy. */
final class BasketBuyingPowerGate implements GateInterface
{
    public function name(): string
    {
        return 'buying_power';
    }

    public function evaluate(OrderIntent $intent, array $cfg, array $context): GateResult
    {
        if ($intent->strategyLabel !== 'theta_basket_etf' || data_get($cfg, 'basket.funding_source') !== 'broker_buying_power') {
            return GateResult::block($this->name(), 'basket_funding_not_authorized');
        }
        $buyingPower = $context['broker_buying_power'] ?? null;
        $unleveraged = $context['unleveraged_buying_power'] ?? null;
        if (! is_numeric($buyingPower) || ! is_numeric($unleveraged) || ! is_finite((float) $buyingPower) || ! is_finite((float) $unleveraged)) {
            return GateResult::block($this->name(), 'buying_power_unverified');
        }
        $debit = $intent->estimatedDebit();
        $available = min((float) $buyingPower, (float) $unleveraged) - (float) ($context['open_order_reserved_debit'] ?? 0);
        if ($debit === null || $debit <= 0 || $debit > $available) {
            return GateResult::block($this->name(), 'buying_power_insufficient', ['available' => $available, 'debit' => $debit]);
        }

        return GateResult::allow($this->name(), ['funding_source' => 'broker_buying_power', 'available' => $available, 'debit' => $debit]);
    }
}
