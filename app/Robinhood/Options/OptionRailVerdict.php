<?php

declare(strict_types=1);

namespace App\Robinhood\Options;

/**
 * Full options gating ledger. verdict() is ALLOW or the first block's
 * category; the complete ledger always travels with it so readbacks can
 * name every gate's state, not just the first refusal.
 */
final class OptionRailVerdict
{
    public const ALLOW = 'ALLOW';

    /** @param list<OptionGateResult> $results */
    public function __construct(
        public readonly OptionOrderIntent $intent,
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

    public function verdict(): string
    {
        foreach ($this->results as $result) {
            if (! $result->ok) {
                return $result->category ?? OptionRailPipeline::BLOCK_UNKNOWN;
            }
        }

        return self::ALLOW;
    }

    /** @return list<string> e.g. "quote_freshness:quote_stale" */
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

    public function toArray(): array
    {
        return [
            'verdict' => $this->verdict(),
            'allowed' => $this->allowed(),
            'block_reasons' => $this->blockReasons(),
            'intent' => $this->intent->toArray(),
            'ledger' => array_map(fn (OptionGateResult $r) => $r->toArray(), $this->results),
        ];
    }
}
