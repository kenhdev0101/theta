<?php

declare(strict_types=1);

namespace App\Robinhood\Execution;

use App\Models\HarnessOrderRef;
use Illuminate\Support\Str;

/**
 * Persists the idempotency ref for a logical order BEFORE placement.
 * Re-running the same logical intent on the same trading day returns the
 * already-persisted ref (same client_ref_id), never a fresh one.
 */
final class OrderRefStore
{
    public function reserve(ReservableIntent $intent, ?string $tradingDay = null): HarnessOrderRef
    {
        $tradingDay ??= now()->toDateString();

        return HarnessOrderRef::query()->firstOrCreate(
            ['intent_key' => $intent->intentKey($tradingDay)],
            [
                'client_ref_id' => (string) Str::uuid(),
                'symbol' => $intent->refSymbol(),
                'side' => $intent->refSide(),
                'status' => 'reserved',
                'order_payload' => $intent->toArray(),
            ],
        );
    }

    /** Look up the existing ref for this logical intent without creating one. */
    public function peek(ReservableIntent $intent, ?string $tradingDay = null): ?HarnessOrderRef
    {
        $tradingDay ??= now()->toDateString();

        return HarnessOrderRef::query()
            ->where('intent_key', $intent->intentKey($tradingDay))
            ->first();
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
