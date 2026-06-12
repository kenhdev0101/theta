<?php

declare(strict_types=1);

namespace App\Robinhood\Rails\Gates;

use App\Robinhood\Rails\GateResult;
use App\Robinhood\Rails\OrderIntent;

/**
 * Per-day, per-symbol, and open-position-count bounds. Spent-today and
 * per-symbol exposure come from broker/journal truth in context — if the
 * caller cannot supply them, the gate blocks rather than assuming zero.
 */
final class AllocationGate implements GateInterface
{
    public function name(): string
    {
        return 'allocation';
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

        $alloc = $cfg['allocation'] ?? [];

        $spentToday = $context['spent_today_debit'] ?? null;
        if ($spentToday === null) {
            return GateResult::block($this->name(), 'spent_today_unverified');
        }
        $perDay = (float) ($alloc['per_day_max_debit'] ?? 0.0);
        if ($perDay <= 0) {
            return GateResult::block($this->name(), 'per_day_cap_unconfigured');
        }
        if ((float) $spentToday + $debit > $perDay) {
            return GateResult::block($this->name(), 'per_day_cap_exceeded', [
                'spent_today_debit' => $spentToday,
                'debit' => $debit,
                'per_day_max_debit' => $perDay,
            ]);
        }

        $symbol = strtoupper($intent->symbol);
        $perSymbol = (float) ($alloc['per_symbol_max_debit'] ?? 0.0);
        if ($perSymbol > 0) {
            $symbolDebits = $context['symbol_open_debit'] ?? null;
            if (! is_array($symbolDebits)) {
                return GateResult::block($this->name(), 'symbol_open_debit_unverified');
            }

            $symbolOpenDebit = (float) ($symbolDebits[$symbol] ?? 0.0);
            if ($symbolOpenDebit + $debit > $perSymbol) {
                return GateResult::block($this->name(), 'per_symbol_cap_exceeded', [
                    'symbol' => $symbol,
                    'symbol_open_debit' => $symbolOpenDebit,
                    'debit' => $debit,
                    'per_symbol_max_debit' => $perSymbol,
                ]);
            }
        }

        $maxOpen = (int) ($alloc['max_open_positions'] ?? 0);
        $openPositions = $context['open_positions'] ?? null;
        if ($maxOpen > 0 && is_array($openPositions) && count($openPositions) >= $maxOpen) {
            return GateResult::block($this->name(), 'max_open_positions_reached', [
                'open_positions' => count($openPositions),
                'max_open_positions' => $maxOpen,
            ]);
        }

        return GateResult::allow($this->name(), [
            'spent_today_debit' => $spentToday,
            'debit' => $debit,
            'per_day_max_debit' => $perDay,
        ]);
    }
}
