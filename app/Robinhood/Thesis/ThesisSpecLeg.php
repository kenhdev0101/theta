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

    public const ENTRY_BOUNDED_RESTING_LIMIT = 'bounded_resting_limit';

    public const STRUCTURE_LONG_CALL = 'single_leg_long_call';

    public const STRUCTURE_LONG_PUT = 'single_leg_long_put';

    /** The conventional near-the-money expression. */
    public const ROLE_CORE = 'core';

    /** A short-dated / far-OTM fringe leg that scouts a sibling core leg. */
    public const ROLE_PROBE = 'probe';

    /** Strike selection is by target option delta (the convexity-band primitive). */
    public const MONEYNESS_DELTA = 'delta';

    /** Strike selection is by percent out-of-the-money from spot. */
    public const MONEYNESS_OTM_PCT = 'otm_pct';

    /** Strike nearest spot (the default when moneyness is unspecified). */
    public const MONEYNESS_ATM = 'atm';

    /**
     * |delta| at or below which a long option leg counts as fringe — the
     * negative-mean-carry, high-convexity-per-premium region that requires the
     * fast-exit + time-stop discipline. The convex sweet spot is ~0.15-0.30
     * (dollar-gamma, not gamma-per-premium, which keeps rising into the dead
     * tail); 0.35 is the outer weekly/event band.
     */
    public const FRINGE_DELTA_CEILING = 0.35;

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

    // --- fringe / probe (amplifier) fields ----------------------------------

    public function role(): string
    {
        $role = strtolower(trim((string) ($this->raw['role'] ?? '')));

        return $role !== '' ? $role : self::ROLE_CORE;
    }

    public function isProbe(): bool
    {
        return $this->role() === self::ROLE_PROBE;
    }

    /** @return array<string,mixed> */
    public function moneyness(): array
    {
        return (array) ($this->raw['moneyness'] ?? []);
    }

    public function moneynessMode(): string
    {
        $mode = strtolower(trim((string) ($this->moneyness()['mode'] ?? '')));

        return $mode !== '' ? $mode : self::MONEYNESS_ATM;
    }

    public function targetDelta(): ?float
    {
        return $this->numeric($this->moneyness()['target'] ?? $this->moneyness()['delta'] ?? null);
    }

    public function otmPct(): ?float
    {
        return $this->numeric($this->moneyness()['otm_pct'] ?? ($this->moneynessMode() === self::MONEYNESS_OTM_PCT ? $this->moneyness()['target'] ?? null : null));
    }

    /**
     * A long option leg is "fringe" when it sits in the OTM convexity region
     * (by delta or by percent OTM) or is explicitly a probe. Fringe legs carry
     * negative mean carry, so the validator forces the fast-exit + time-stop
     * discipline on them. Equity legs are never fringe.
     */
    public function isFringe(): bool
    {
        if (! $this->isOption()) {
            return false;
        }

        if ($this->isProbe()) {
            return true;
        }

        if ($this->moneynessMode() === self::MONEYNESS_DELTA) {
            $delta = $this->targetDelta();

            return $delta !== null && $delta > 0.0 && $delta <= self::FRINGE_DELTA_CEILING;
        }

        return $this->moneynessMode() === self::MONEYNESS_OTM_PCT && ($this->otmPct() ?? 0.0) > 0.0;
    }

    public function scouts(): ?string
    {
        $value = trim((string) ($this->raw['scouts'] ?? ''));

        return $value !== '' ? $value : null;
    }

    /** @return array<string,mixed> */
    public function fastExit(): array
    {
        return (array) ($this->raw['fast_exit'] ?? []);
    }

    public function hasFastExit(): bool
    {
        return $this->fastExit() !== [];
    }

    public function fastExitProfitTargetMult(): ?float
    {
        return $this->numeric($this->fastExit()['profit_target_mult'] ?? null);
    }

    public function fastExitScaleOutPct(): ?float
    {
        return $this->numeric($this->fastExit()['scale_out_pct'] ?? null);
    }

    public function fastExitTrailFromPeakPct(): ?float
    {
        return $this->numeric($this->fastExit()['trail_from_peak_pct'] ?? null);
    }

    public function fastExitMaxHoldDte(): ?int
    {
        $value = $this->numeric($this->fastExit()['max_hold_dte'] ?? null);

        return $value === null ? null : (int) $value;
    }

    public function fastExitMaxHoldMinutes(): ?float
    {
        return $this->numeric($this->fastExit()['max_hold_minutes'] ?? null);
    }

    public function fastExitDeltaTake(): ?float
    {
        return $this->numeric($this->fastExit()['delta_take'] ?? null);
    }

    public function fastExitDeltaClose(): ?float
    {
        return $this->numeric($this->fastExit()['delta_close'] ?? null);
    }

    public function fastExitHardStopPct(): ?float
    {
        return $this->numeric($this->fastExit()['hard_stop_pct'] ?? null);
    }

    public function hasFastExitMaxHold(): bool
    {
        return $this->fastExitMaxHoldDte() !== null || $this->fastExitMaxHoldMinutes() !== null;
    }

    /** The authoring-time dollar figure for this leg, when unambiguous (notional only). */
    public function notionalUsd(): ?float
    {
        if ($this->sizingMode() !== self::SIZING_NOTIONAL) {
            return null;
        }

        return $this->maxUsd() ?? $this->targetUsd();
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
