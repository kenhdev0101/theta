<?php

declare(strict_types=1);

namespace App\Robinhood\Execution;

/**
 * Anything the OrderRefStore can persist an idempotency ref for: one
 * deterministic key per logical order per trading day.
 */
interface ReservableIntent
{
    public function intentKey(string $tradingDay): string;

    /** Display symbol for the ref row (underlying or contract label). */
    public function refSymbol(): string;

    /** buy|sell */
    public function refSide(): string;

    public function toArray(): array;
}
