<?php

declare(strict_types=1);

namespace App\Robinhood\Rails\Gates;

use App\Robinhood\Rails\GateResult;
use App\Robinhood\Rails\OrderIntent;

final class KillSwitchGate implements GateInterface
{
    public function name(): string
    {
        return 'kill_switch';
    }

    public function evaluate(OrderIntent $intent, array $cfg, array $context): GateResult
    {
        $engaged = (bool) ($cfg['kill_switch'] ?? true);

        if ($engaged) {
            return GateResult::block($this->name(), 'kill_switch_engaged');
        }

        return GateResult::allow($this->name());
    }
}
