<?php

declare(strict_types=1);

namespace App\Robinhood\Rails\Gates;

use App\Robinhood\Rails\GateResult;
use App\Robinhood\Rails\OrderIntent;

/**
 * Session/tradability gate. Unknown session state blocks — a stale or
 * missing session read is a data-plane condition, never "assume open".
 */
final class SessionGate implements GateInterface
{
    public function name(): string
    {
        return 'session';
    }

    public function evaluate(OrderIntent $intent, array $cfg, array $context): GateResult
    {
        $sessionOpen = $context['session_open'] ?? null;
        if ($sessionOpen !== true) {
            return GateResult::block(
                $this->name(),
                $sessionOpen === false ? 'session_closed' : 'session_state_unverified',
            );
        }

        $tradability = $context['tradability'] ?? null;
        if ($tradability === null) {
            return GateResult::block($this->name(), 'tradability_unverified');
        }
        if (! in_array($tradability, ['tradable', 'all_tradable'], true)) {
            return GateResult::block($this->name(), 'not_tradable', ['tradability' => $tradability]);
        }

        // Fractional/dollar orders route regular hours only (broker constraint).
        if ($intent->dollarAmount !== null && $intent->marketHours !== 'regular_hours') {
            return GateResult::block($this->name(), 'dollar_order_outside_regular_hours', [
                'market_hours' => $intent->marketHours,
            ]);
        }

        return GateResult::allow($this->name(), ['tradability' => $tradability]);
    }
}
