<?php

declare(strict_types=1);

namespace App\Robinhood\Thesis;

/**
 * One leg of a thesis spec — a single durable position the lane will open and
 * hold against precommitted invalidation triggers.
 *
 * This is a lenient parse of decoded JSON: every accessor coerces defensively
 * and returns null where a field is absent or unusable. It NEVER throws on a
 * malformed field — coherence is ThesisSpecValidator's job, so the validator
 * can report every problem at once with a named blocker rather than dying on
 * the first bad key. The leg carries no broker state and derives no price; the
 * concrete limit is derived at execution against the live quote (the equity
 * analogue of the options rule "strikes are never pre-derived").
 */
final class ThesisSpecLeg
{
    public const ASSET_EQUITY = 'equity';

    public const ASSET_OPTION = 'option';

    public const DIRECTION_LONG = 'long';

    public const DIRECTION_SHORT = 'short';

    /** Fixed USD exposure, fractional shares (notional dollar order). */
    public const SIZING_NOTIONAL = 'notional';

    /** Explicit (possibly fractional) share quantity. */
    public const SIZING_SHARES = 'shares';

    /** A fraction of settled balance — the size scales with the account. */
    public const SIZING_BALANCE_FRACTION = 'balance_fraction';

    public const ENTRY_MARKETABLE_LIMIT = 'marketable_limit';

    public const STRUCTURE_LONG_CALL = 'single_leg_long_call';

    public const STRUCTURE_LONG_PUT = 'single_leg_long_put';

    /** @param array<string,mixed> $raw */
    public function __construct(private readonly array $raw) {}

    /** @param array<string,mixed> $raw */
    public static function fromArray(array $raw): self
    {
        return new self($raw);
    }

    /** Stable id for this leg; falls back to the symbol so blockers are nameable. */
    public function legId(): string
    {
        $id = trim((string) ($this->raw['leg_id'] ?? ''));

        return $id !== '' ? $id : $this->symbol();
    }

    public function assetClass(): string
    {
        return strtolower(trim((string) ($this->raw['asset_class'] ?? '')));
    }

    public function symbol(): string
    {
        return strtoupper(trim((string) ($this->raw['symbol'] ?? '')));
    }

    public function direction(): string
    {
        return strtolower(trim((string) ($this->raw['direction'] ?? '')));
    }

    public function sizingMode(): string
    {
        return strtolower(trim((string) ($this->sizing()['mode'] ?? '')));
    }

    /** @return array<string,mixed> */
    public function sizing(): array
    {
        return (array) ($this->raw['sizing'] ?? []);
    }

    public function targetUsd(): ?float
    {
        return $this->numeric($this->sizing()['target_usd'] ?? null);
    }

    public function maxUsd(): ?float
    {
        return $this->numeric($this->sizing()['max_usd'] ?? null);
    }

    public function shares(): ?float
    {
        return $this->numeric($this->sizing()['shares'] ?? null);
    }

    public function maxShares(): ?float
    {
        return $this->numeric($this->sizing()['max_shares'] ?? null);
    }

    public function balanceFraction(): ?float
    {
        return $this->numeric($this->sizing()['fraction'] ?? null);
    }

    /** @return array<string,mixed> */
    public function entry(): array
    {
        return (array) ($this->raw['entry'] ?? []);
    }

    public function entryType(): string
    {
        $type = strtolower(trim((string) ($this->entry()['type'] ?? '')));

        // An unspecified entry defaults to the lane primitive, not a blocker:
        // marketable-limit is the firm's equity default (CLAUDE.md equities lane).
        return $type !== '' ? $type : self::ENTRY_MARKETABLE_LIMIT;
    }

    public function entryOffsetBps(): float
    {
        return (float) ($this->numeric($this->entry()['offset_bps'] ?? null) ?? 0.0);
    }

    public function entryMaxChaseBps(): ?float
    {
        return $this->numeric($this->entry()['max_chase_bps'] ?? null);
    }

    /** @return array<string,mixed> */
    public function invalidation(): array
    {
        return (array) ($this->raw['invalidation'] ?? []);
    }

    public function priceStop(): ?float
    {
        return $this->numeric($this->invalidation()['price_stop'] ?? null);
    }

    public function timeStop(): ?string
    {
        $value = trim((string) ($this->invalidation()['time_stop'] ?? ''));

        return $value !== '' ? $value : null;
    }

    public function targetPct(): ?float
    {
        return $this->numeric($this->invalidation()['target_pct'] ?? null);
    }

    public function thesisStop(): ?string
    {
        $value = trim((string) ($this->invalidation()['thesis_stop'] ?? ''));

        return $value !== '' ? $value : null;
    }

    /** True when the leg precommits at least one machine- or human-evaluable exit. */
    public function hasAnyInvalidationTrigger(): bool
    {
        return $this->priceStop() !== null
            || $this->timeStop() !== null
            || $this->targetPct() !== null
            || $this->thesisStop() !== null;
    }

    public function isEquity(): bool
    {
        return $this->assetClass() === self::ASSET_EQUITY;
    }

    public function isOption(): bool
    {
        return $this->assetClass() === self::ASSET_OPTION;
    }

    // --- option-only fields -------------------------------------------------

    public function optionStructure(): string
    {
        return strtolower(trim((string) ($this->raw['structure'] ?? '')));
    }

    public function dteMin(): ?int
    {
        $value = $this->numeric(($this->raw['dte'] ?? [])['min'] ?? null);

        return $value === null ? null : (int) $value;
    }

    public function dteMax(): ?int
    {
        $value = $this->numeric(($this->raw['dte'] ?? [])['max'] ?? null);

        return $value === null ? null : (int) $value;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->raw;
    }

    private function numeric(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
