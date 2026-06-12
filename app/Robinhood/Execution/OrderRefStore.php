<?php

declare(strict_types=1);

namespace App\Robinhood\Execution;

use App\Models\HarnessOrderRef;
use App\Robinhood\Rails\OrderIntent;
use Illuminate\Support\Str;

/**
 * Persists the idempotency ref for a logical order BEFORE placement.
 * Re-running the same logical intent on the same trading day returns the
 * already-persisted ref (same client_ref_id), never a fresh one.
 */
final class OrderRefStore
{
    public function reserve(OrderIntent $intent, ?string $tradingDay = null): HarnessOrderRef
    {
        $tradingDay ??= now()->toDateString();

        return HarnessOrderRef::query()->firstOrCreate(
            ['intent_key' => $intent->intentKey($tradingDay)],
            [
                'client_ref_id' => (string) Str::uuid(),
                'symbol' => strtoupper($intent->symbol),
                'side' => $intent->side,
                'status' => 'reserved',
                'order_payload' => $intent->toArray(),
            ],
        );
    }

    public function markPlaced(HarnessOrderRef $ref, ?string $brokerOrderId): void
    {
        $ref->forceFill([
            'status' => 'placed',
            'broker_order_id' => $brokerOrderId,
            'placed_at' => now(),
        ])->save();
    }

    public function markFailed(HarnessOrderRef $ref, string $reason): void
    {
        $payload = $ref->order_payload ?? [];
        $payload['failure_reason'] = $reason;

        $ref->forceFill([
            'status' => 'failed',
            'order_payload' => $payload,
        ])->save();
    }
}
