<?php

declare(strict_types=1);

namespace App\Robinhood\Rails\Gates;

use App\Robinhood\Rails\GateResult;
use App\Robinhood\Rails\OrderIntent;

/**
 * Duplicate/orphan prevention: an open agentic order on the same symbol and
 * side, or a ref this harness has already attempted (placed, failed,
 * cancelled — anything past 'reserved'), blocks a second submission. A
 * failed attempt is ambiguous until broker truth says otherwise, so the
 * retry path is reconcile-first, never resubmit-first.
 */
final class IdempotencyGate implements GateInterface
{
    private const OPEN_STATES = ['queued', 'unconfirmed', 'confirmed', 'placed', 'pending', 'partially_filled', 'open', 'new', 'accepted'];

    public function name(): string
    {
        return 'idempotency';
    }

    public function evaluate(OrderIntent $intent, array $cfg, array $context): GateResult
    {
        $existingRef = $context['existing_ref'] ?? null;
        if (is_array($existingRef)) {
            $refStatus = $existingRef['status'] ?? null;

            if ($refStatus === 'placed') {
                return GateResult::block($this->name(), 'ref_already_placed', [
                    'client_ref_id' => $existingRef['client_ref_id'] ?? null,
                    'broker_order_id' => $existingRef['broker_order_id'] ?? null,
                ]);
            }

            if ($refStatus !== null && $refStatus !== 'reserved') {
                return GateResult::block($this->name(), 'ref_attempted_requires_reconcile', [
                    'client_ref_id' => $existingRef['client_ref_id'] ?? null,
                    'ref_status' => $refStatus,
                ]);
            }
        }

        $symbol = strtoupper($intent->symbol);
        foreach ((array) ($context['open_orders'] ?? []) as $order) {
            $orderSymbol = strtoupper((string) ($order['symbol'] ?? ''));
            $orderSide = strtolower((string) ($order['side'] ?? ''));
            $orderState = strtolower((string) ($order['state'] ?? $order['status'] ?? ''));

            if ($orderSymbol === $symbol
                && $orderSide === $intent->side
                && in_array($orderState, self::OPEN_STATES, true)) {
                return GateResult::block($this->name(), 'duplicate_open_order', [
                    'symbol' => $symbol,
                    'side' => $intent->side,
                    'existing_order' => [
                        'id' => $order['id'] ?? $order['order_id'] ?? null,
                        'state' => $orderState,
                    ],
                ]);
            }
        }

        return GateResult::allow($this->name());
    }
}
