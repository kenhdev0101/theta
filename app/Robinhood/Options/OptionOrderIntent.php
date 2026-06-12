<?php

declare(strict_types=1);

namespace App\Robinhood\Options;

use App\Robinhood\Execution\ReservableIntent;

/**
 * Typed single-leg option intent — Level 2 scope only: buy-to-open long
 * calls/puts, sell-to-close to exit. The decision layer may narrate why
 * this intent exists; it never computes these numbers and never selects
 * the contract.
 */
final class OptionOrderIntent implements ReservableIntent
{
    public function __construct(
        public readonly string $underlyingSymbol,
        public readonly string $optionType,
        public readonly string $expirationDate,
        public readonly float $strikePrice,
        public readonly string $side,
        public readonly string $positionEffect,
        public readonly int $quantity,
        public readonly ?float $limitPrice = null,
        public readonly ?string $optionId = null,
        public readonly ?string $chainId = null,
        public readonly ?string $underlyingType = 'equity',
        public readonly string $orderType = 'limit',
        public readonly string $timeInForce = 'gfd',
        public readonly string $marketHours = 'regular_hours',
        public readonly float $contractMultiplier = 100.0,
        public readonly string $source = 'manual_probe',
    ) {}

    /**
     * Deterministic max debit for a buy: contracts x limit x multiplier.
     * Null means unbounded — gates block, never assume.
     */
    public function estimatedDebit(): ?float
    {
        if ($this->side !== 'buy') {
            return 0.0;
        }

        if ($this->limitPrice === null || $this->limitPrice <= 0) {
            return null;
        }

        return round($this->quantity * $this->limitPrice * $this->contractMultiplier, 2);
    }

    public function isOpening(): bool
    {
        return $this->positionEffect === 'open';
    }

    /** Human-readable contract label (display only — never used to derive broker IDs). */
    public function contractLabel(): string
    {
        return sprintf(
            '%s %s %s %s',
            strtoupper($this->underlyingSymbol),
            $this->expirationDate,
            rtrim(rtrim(number_format($this->strikePrice, 4, '.', ''), '0'), '.'),
            strtoupper(substr($this->optionType, 0, 1)),
        );
    }

    public function intentKey(string $tradingDay): string
    {
        return hash('sha256', implode('|', [
            $tradingDay,
            'option',
            strtoupper($this->underlyingSymbol),
            $this->optionType,
            $this->expirationDate,
            number_format($this->strikePrice, 4, '.', ''),
            $this->side,
            $this->positionEffect,
            (string) $this->quantity,
            $this->limitPrice === null ? '' : number_format($this->limitPrice, 2, '.', ''),
            $this->source,
        ]));
    }

    public function refSymbol(): string
    {
        return strtoupper($this->underlyingSymbol);
    }

    public function refSide(): string
    {
        return $this->side;
    }

    public function toArray(): array
    {
        return [
            'asset_class' => 'option',
            'underlying_symbol' => strtoupper($this->underlyingSymbol),
            'option_id' => $this->optionId,
            'chain_id' => $this->chainId,
            'option_type' => $this->optionType,
            'expiration_date' => $this->expirationDate,
            'strike_price' => $this->strikePrice,
            'side' => $this->side,
            'position_effect' => $this->positionEffect,
            'quantity' => $this->quantity,
            'limit_price' => $this->limitPrice,
            'order_type' => $this->orderType,
            'time_in_force' => $this->timeInForce,
            'market_hours' => $this->marketHours,
            'contract_multiplier' => $this->contractMultiplier,
            'source' => $this->source,
        ];
    }

    public function withContract(string $optionId, ?string $chainId, ?string $underlyingType = null): self
    {
        return new self(
            underlyingSymbol: $this->underlyingSymbol,
            optionType: $this->optionType,
            expirationDate: $this->expirationDate,
            strikePrice: $this->strikePrice,
            side: $this->side,
            positionEffect: $this->positionEffect,
            quantity: $this->quantity,
            limitPrice: $this->limitPrice,
            optionId: $optionId,
            chainId: $chainId,
            underlyingType: $underlyingType ?? $this->underlyingType,
            orderType: $this->orderType,
            timeInForce: $this->timeInForce,
            marketHours: $this->marketHours,
            contractMultiplier: $this->contractMultiplier,
            source: $this->source,
        );
    }
}
