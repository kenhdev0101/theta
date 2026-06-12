<?php

declare(strict_types=1);

namespace App\Robinhood\Rails\Gates;

use App\Robinhood\Rails\GateResult;
use App\Robinhood\Rails\OrderIntent;

/**
 * Settled-cash discipline for the cash account: debit must fit inside
 * settled cash; unsettled proceeds are not reused. Unknown settled cash is
 * never "safe to place" — it blocks with a named verification demand.
 */
final class SettledCashGate implements GateInterface
{
    public function name(): string
    {
        return 'settled_cash';
    }

    public function evaluate(OrderIntent $intent, array $cfg, array $context): GateResult
    {
        if ($intent->side !== 'buy') {
            return GateResult::allow($this->name(), ['side' => $intent->side]);
        }

        $debit = $intent->estimatedDebit();
        if ($debit === null) {
            return GateResult::block($this->name(), 'debit_unbounded');
        }

        $settledCash = $context['settled_cash'] ?? null;
        if ($settledCash === null) {
            return GateResult::block($this->name(), 'settled_cash_unverified');
        }

        $reserved = (float) ($context['open_order_reserved_debit'] ?? 0.0);
        $available = (float) $settledCash - $reserved;

        if ($debit > $available) {
            return GateResult::block($this->name(), 'settled_cash_insufficient', [
                'settled_cash' => $settledCash,
                'open_order_reserved_debit' => $reserved,
                'available' => $available,
                'debit' => $debit,
            ]);
        }

        return GateResult::allow($this->name(), [
            'settled_cash' => $settledCash,
            'open_order_reserved_debit' => $reserved,
            'debit' => $debit,
        ]);
    }
}
