<?php

declare(strict_types=1);

namespace App\Robinhood\Rails;

/**
 * Full gating ledger for one intent. Every gate reports, allow or block —
 * a blocked verdict still carries the complete ledger so readbacks can name
 * the first ineligible reason and every other gate's state.
 */
final class RailVerdict
{
    /** @param list<GateResult> $results */
    public function __construct(
        public readonly OrderIntent $intent,
        public readonly array $results,
    ) {}

    public function allowed(): bool
    {
        foreach ($this->results as $result) {
            if (! $result->ok) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> named reasons, e.g. "settled_cash:settled_cash_unverified" */
    public function blockReasons(): array
    {
        $reasons = [];
        foreach ($this->results as $result) {
            if (! $result->ok) {
                $reasons[] = $result->gate.':'.$result->reason;
            }
        }

        return $reasons;
    }

    public function firstBlock(): ?GateResult
    {
        foreach ($this->results as $result) {
            if (! $result->ok) {
                return $result;
            }
        }

        return null;
    }

    public function toArray(): array
    {
        return [
            'allowed' => $this->allowed(),
            'block_reasons' => $this->blockReasons(),
            'intent' => $this->intent->toArray(),
            'ledger' => array_map(fn (GateResult $r) => $r->toArray(), $this->results),
        ];
    }
}
