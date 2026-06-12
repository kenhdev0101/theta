<?php

declare(strict_types=1);

namespace App\Robinhood\Rails\Gates;

use App\Robinhood\Rails\GateResult;
use App\Robinhood\Rails\OrderIntent;

/**
 * Per-play debit bound. A debit that cannot be deterministically computed
 * (e.g. a naked market buy with no dollar bound) blocks here — marketable
 * limits are the policy default for exactly this reason.
 */
final class SizingGate implements GateInterface
{
    public function name(): string
    {
        return 'sizing';
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
        if ($debit <= 0) {
            return GateResult::block($this->name(), 'debit_not_positive', ['debit' => $debit]);
        }

        $perPlay = (float) ($cfg['allocation']['per_play_max_debit'] ?? 0.0);
        if ($perPlay <= 0) {
            return GateResult::block($this->name(), 'per_play_cap_unconfigured');
        }
        if ($debit > $perPlay) {
            return GateResult::block($this->name(), 'per_play_cap_exceeded', [
                'debit' => $debit,
                'per_play_max_debit' => $perPlay,
            ]);
        }

        return GateResult::allow($this->name(), ['debit' => $debit, 'per_play_max_debit' => $perPlay]);
    }
}
