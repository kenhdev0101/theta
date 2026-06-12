<?php

declare(strict_types=1);

namespace App\Robinhood\Rails;

use App\Robinhood\Rails\Gates\AllocationGate;
use App\Robinhood\Rails\Gates\GateInterface;
use App\Robinhood\Rails\Gates\IdempotencyGate;
use App\Robinhood\Rails\Gates\KillSwitchGate;
use App\Robinhood\Rails\Gates\ReviewGate;
use App\Robinhood\Rails\Gates\SessionGate;
use App\Robinhood\Rails\Gates\SettledCashGate;
use App\Robinhood\Rails\Gates\SizingGate;

/**
 * The pre-trade gate chain (architecture §6). Deterministic PHP only —
 * every place call passes the full chain; any block returns a named reason
 * that is logged, never silently dropped. All gates report (full gating
 * ledger), not just the first block.
 */
final class RailPipeline
{
    /** @var list<GateInterface> */
    private readonly array $gates;

    /** @param list<GateInterface>|null $gates */
    public function __construct(?array $gates = null)
    {
        $this->gates = $gates ?? [
            new KillSwitchGate,
            new SessionGate,
            new SizingGate,
            new AllocationGate,
            new SettledCashGate,
            new ReviewGate,
            new IdempotencyGate,
        ];
    }

    public function evaluate(OrderIntent $intent, array $cfg, array $context): RailVerdict
    {
        $results = [];
        foreach ($this->gates as $gate) {
            $results[] = $gate->evaluate($intent, $cfg, $context);
        }

        return new RailVerdict($intent, $results);
    }
}
