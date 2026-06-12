<?php

declare(strict_types=1);

namespace App\Robinhood\Rails;

/**
 * Typed, immutable order intent — the contract between the decision layer
 * and the deterministic rail/execution layers. The decision layer may
 * narrate why this intent exists; it never computes these numbers.
 */
final class OrderIntent
{
    public function __construct(
        public readonly string $symbol,
        public readonly string $side,
        public readonly string $orderType = 'limit',
        public readonly ?float $quantity = null,
        public readonly ?float $dollarAmount = null,
        public readonly ?float $limitPrice = null,
        public readonly ?float $stopPrice = null,
        public readonly string $timeInForce = 'gfd',
        public readonly string $marketHours = 'regular_hours',
        public readonly string $assetClass = 'equity',
        public readonly string $source = 'manual_probe',
    ) {}

    /**
     * Estimated cash debit for a buy. Null means the debit cannot be
     * deterministically bounded — gates treat that as a block, never as zero.
     */
    public function estimatedDebit(): ?float
    {
        if ($this->side !== 'buy') {
            return 0.0;
        }

        if ($this->dollarAmount !== null) {
            return $this->dollarAmount;
        }

        if ($this->quantity !== null && $this->limitPrice !== null) {
            return round($this->quantity * $this->limitPrice, 2);
        }

        return null;
    }

    /**
     * Deterministic idempotency key for one logical order on one trading day.
     * The persisted client_ref_id is keyed by this, so transport retries and
     * command re-runs reuse the same ref instead of double-submitting.
     */
    public function intentKey(string $tradingDay): string
    {
        return hash('sha256', implode('|', [
            $tradingDay,
            $this->assetClass,
            strtoupper($this->symbol),
            $this->side,
            $this->orderType,
            $this->quantity === null ? '' : rtrim(rtrim(number_format($this->quantity, 8, '.', ''), '0'), '.'),
            $this->dollarAmount === null ? '' : number_format($this->dollarAmount, 2, '.', ''),
            $this->limitPrice === null ? '' : rtrim(rtrim(number_format($this->limitPrice, 8, '.', ''), '0'), '.'),
            $this->source,
        ]));
    }

    public function toArray(): array
    {
        return [
            'symbol' => strtoupper($this->symbol),
            'side' => $this->side,
            'order_type' => $this->orderType,
            'quantity' => $this->quantity,
            'dollar_amount' => $this->dollarAmount,
            'limit_price' => $this->limitPrice,
            'stop_price' => $this->stopPrice,
            'time_in_force' => $this->timeInForce,
            'market_hours' => $this->marketHours,
            'asset_class' => $this->assetClass,
            'source' => $this->source,
        ];
    }
}
