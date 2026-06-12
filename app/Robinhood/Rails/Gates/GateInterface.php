<?php

declare(strict_types=1);

namespace App\Robinhood\Rails\Gates;

use App\Robinhood\Rails\GateResult;
use App\Robinhood\Rails\OrderIntent;

interface GateInterface
{
    public function name(): string;

    /**
     * @param  array  $cfg  full harness config assembly (command-propagated)
     * @param  array  $context  broker/account truth snapshot: settled_cash,
     *                          spent_today_debit, symbol_open_debit, open_orders,
     *                          open_positions, session_open, tradability, review
     */
    public function evaluate(OrderIntent $intent, array $cfg, array $context): GateResult;
}
