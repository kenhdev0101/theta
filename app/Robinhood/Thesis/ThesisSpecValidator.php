<?php

declare(strict_types=1);

namespace App\Robinhood\Thesis;

use App\Models\ThesisIntent;
use Illuminate\Support\Carbon;

/**
 * Playbook-discipline coherence gate for a JSON thesis spec.
 *
 * Mirrors {@see ThesisIntent::activationBlockers()}: returns named
 * blockers (empty = armable), fail-closed, so an authoring mistake is reported
 * in full and by name rather than discovered one order at a time at the open.
 * Two scopes, deliberately separate:
 *
 *  - structuralBlockers(): config-independent coherence (versions, triggers,
 *    long-only cash-account discipline, sizing sanity). These are hard — a
 *    spec that fails any of them can never arm.
 *  - capBlockers(): authoring-time sanity against lane dollar/leg caps. These
 *    are an early warning only; the real money-moving enforcement is the
 *    execution rails (SizingGate / AllocationGate / SettledCashGate), which
 *    re-check every cap against live broker truth at placement. Share- and
 *    balance-fraction-sized legs cannot be dollar-checked without a live quote
 *    or balance, so they are surfaced as warnings, not blockers.
 *
 * Nothing here reads the broker or the database.
 */
final class ThesisSpecValidator
{
    /** Slug the lane can safely use as an idempotency/logical-order key component. */
    private const THESIS_ID_PATTERN = '/^[a-z0-9][a-z0-9._-]{1,62}$/';

    /**
     * @param  array{per_play_max_usd?: float, per_day_max_usd?: float, max_legs?: int}  $caps
     * @return array{blockers: list<string>, warnings: list<string>}
     */
    public function validate(ThesisSpec $spec, array $caps = []): array
    {
        $blockers = $this->structuralBlockers($spec);

        if ($caps !== []) {
            $blockers = array_merge($blockers, $this->capBlockers($spec, $caps));
        }

        return [
            'blockers' => array_values($blockers),
            'warnings' => $this->warnings($spec),
        ];
    }

    /** @return list<string> */
    public function structuralBlockers(ThesisSpec $spec): array
    {
        $blockers = [];

        if (! in_array($spec->specVersion(), ThesisSpec::SUPPORTED_SPEC_VERSIONS, true)) {
            $blockers[] = 'spec_version_unsupported';
        }

        if ($spec->thesisId() === '') {
            $blockers[] = 'thesis_id_missing';
        } elseif (preg_match(self::THESIS_ID_PATTERN, $spec->thesisId()) !== 1) {
            $blockers[] = 'thesis_id_malformed';
        }

        if (trim($spec->thesis()) === '') {
            $blockers[] = 'thesis_text_missing';
        }

        $legs = $spec->legs();
        if ($legs === []) {
            $blockers[] = 'no_legs';

            return $blockers;
        }

        // First pass: the leg-id set, so a probe can be checked against the
        // sibling it scouts.
        $byId = [];
        foreach ($legs as $leg) {
            $id = $leg->legId();
            if ($id !== '' && isset($byId[$id])) {
                $blockers[] = $this->leg($id, 'duplicate_leg_id');
            }
            $byId[$id] = $leg;
        }

        foreach ($legs as $leg) {
            foreach ($this->legBlockers($leg) as $reason) {
                $blockers[] = $reason;
            }
            foreach ($this->probeBlockers($leg, $byId) as $reason) {
                $blockers[] = $reason;
            }
        }

        return $blockers;
    }

    /** @return list<string> */
    private function legBlockers(ThesisSpecLeg $leg): array
    {
        $id = $leg->legId();
        $out = [];

        if ($leg->symbol() === '') {
            $out[] = $this->leg($id, 'symbol_missing');
        }

        $assetClass = $leg->assetClass();
        if (! in_array($assetClass, [ThesisSpecLeg::ASSET_EQUITY, ThesisSpecLeg::ASSET_OPTION], true)) {
            $out[] = $this->leg($id, 'asset_class_unsupported');
        }

        $direction = $leg->direction();
        if (! in_array($direction, [ThesisSpecLeg::DIRECTION_LONG, ThesisSpecLeg::DIRECTION_SHORT], true)) {
            $out[] = $this->leg($id, 'direction_unsupported');
        } elseif ($leg->isEquity() && $direction === ThesisSpecLeg::DIRECTION_SHORT) {
            // Cash account: no short stock (CLAUDE.md capital firewall). A
            // bearish equity view is expressed long through an inverse ETF, so
            // a short-equity leg is a hard block, not a direction the lane can
            // route.
            $out[] = $this->leg($id, 'short_equity_not_permitted_cash_account');
        }

        foreach ($this->sizingBlockers($leg) as $reason) {
            $out[] = $reason;
        }

        if (! in_array($leg->entryType(), [ThesisSpecLeg::ENTRY_MARKETABLE_LIMIT], true)) {
            $out[] = $this->leg($id, 'entry_type_unsupported');
        }

        if (($chase = $leg->entryMaxChaseBps()) !== null && $chase < 0.0) {
            $out[] = $this->leg($id, 'entry_max_chase_bps_negative');
        }

        if ($leg->entryOffsetBps() < 0.0) {
            $out[] = $this->leg($id, 'entry_offset_bps_negative');
        }

        // A position with no precommitted exit is exactly the discipline the
        // playbook forbids — never hold without a way out.
        if (! $leg->hasAnyInvalidationTrigger()) {
            $out[] = $this->leg($id, 'no_invalidation_trigger');
        }

        foreach ($this->triggerBlockers($leg) as $reason) {
            $out[] = $reason;
        }

        if ($leg->isOption()) {
            foreach ($this->optionBlockers($leg) as $reason) {
                $out[] = $reason;
            }
        }

        return $out;
    }

    /** @return list<string> */
    private function sizingBlockers(ThesisSpecLeg $leg): array
    {
        $id = $leg->legId();
        $mode = $leg->sizingMode();

        return match ($mode) {
            ThesisSpecLeg::SIZING_NOTIONAL => $this->notionalSizingBlockers($leg, $id),
            ThesisSpecLeg::SIZING_SHARES => $this->shareSizingBlockers($leg, $id),
            ThesisSpecLeg::SIZING_BALANCE_FRACTION => $this->balanceFractionBlockers($leg, $id),
            default => [$this->leg($id, 'sizing_mode_unsupported')],
        };
    }

    /** @return list<string> */
    private function notionalSizingBlockers(ThesisSpecLeg $leg, string $id): array
    {
        $out = [];
        $target = $leg->targetUsd();
        if ($target === null || $target <= 0.0) {
            $out[] = $this->leg($id, 'sizing_target_usd_nonpositive');
        }
        $max = $leg->maxUsd();
        if ($max !== null && $target !== null && $max < $target) {
            $out[] = $this->leg($id, 'sizing_max_usd_below_target');
        }

        return $out;
    }

    /** @return list<string> */
    private function shareSizingBlockers(ThesisSpecLeg $leg, string $id): array
    {
        $out = [];
        $shares = $leg->shares();
        if ($shares === null || $shares <= 0.0) {
            $out[] = $this->leg($id, 'sizing_shares_nonpositive');
        }
        $maxShares = $leg->maxShares();
        if ($maxShares !== null && $shares !== null && $maxShares < $shares) {
            $out[] = $this->leg($id, 'sizing_max_shares_below_target');
        }

        return $out;
    }

    /** @return list<string> */
    private function balanceFractionBlockers(ThesisSpecLeg $leg, string $id): array
    {
        $out = [];
        $fraction = $leg->balanceFraction();
        if ($fraction === null || $fraction <= 0.0 || $fraction > 1.0) {
            $out[] = $this->leg($id, 'sizing_balance_fraction_out_of_range');
        }

        return $out;
    }

    /** @return list<string> */
    private function triggerBlockers(ThesisSpecLeg $leg): array
    {
        $id = $leg->legId();
        $out = [];

        if (($priceStop = $leg->priceStop()) !== null && $priceStop <= 0.0) {
            $out[] = $this->leg($id, 'price_stop_nonpositive');
        }

        if (($target = $leg->targetPct()) !== null && $target <= 0.0) {
            $out[] = $this->leg($id, 'target_pct_nonpositive');
        }

        if (($timeStop = $leg->timeStop()) !== null) {
            $date = $this->parseDate($timeStop);
            if ($date === null) {
                $out[] = $this->leg($id, 'time_stop_malformed');
            } elseif ($date->isPast() && ! $date->isToday()) {
                $out[] = $this->leg($id, 'time_stop_in_past');
            }
        }

        return $out;
    }

    /** @return list<string> */
    private function optionBlockers(ThesisSpecLeg $leg): array
    {
        $id = $leg->legId();
        $out = [];

        if (! in_array($leg->optionStructure(), [ThesisSpecLeg::STRUCTURE_LONG_CALL, ThesisSpecLeg::STRUCTURE_LONG_PUT], true)) {
            $out[] = $this->leg($id, 'option_structure_unsupported');
        }

        $min = $leg->dteMin();
        $max = $leg->dteMax();
        if ($min === null || $max === null || $min < 1 || $max < $min) {
            $out[] = $this->leg($id, 'option_dte_band_invalid');
        }

        return $out;
    }

    /** @param array<string,ThesisSpecLeg> $byId
     * @return list<string>
     */
    private function probeBlockers(ThesisSpecLeg $leg, array $byId): array
    {
        $id = $leg->legId();
        $out = [];
        if (! in_array($leg->role(), [ThesisSpecLeg::ROLE_CORE, ThesisSpecLeg::ROLE_PROBE], true)) {
            $out[] = $this->leg($id, 'role_unsupported');
        }
        if ($leg->isProbe() && ! $leg->isOption()) {
            $out[] = $this->leg($id, 'probe_requires_option_leg');
        }
        if ($leg->scouts() !== null) {
            $sibling = $byId[$leg->scouts()] ?? null;
            if (! $leg->isProbe() || ! $sibling instanceof ThesisSpecLeg || $sibling->role() !== ThesisSpecLeg::ROLE_CORE) {
                $out[] = $this->leg($id, 'scouts_requires_core_sibling');
            }
        }
        if (! $leg->isOption()) {
            return $out;
        }
        $mode = $leg->moneynessMode();
        if (! in_array($mode, [ThesisSpecLeg::MONEYNESS_ATM, ThesisSpecLeg::MONEYNESS_DELTA, ThesisSpecLeg::MONEYNESS_OTM_PCT], true)) {
            $out[] = $this->leg($id, 'moneyness_mode_unsupported');
        }
        if ($mode === ThesisSpecLeg::MONEYNESS_DELTA && (($delta = $leg->targetDelta()) === null || $delta <= 0 || $delta >= 1)) {
            $out[] = $this->leg($id, 'target_delta_invalid');
        }
        if ($mode === ThesisSpecLeg::MONEYNESS_OTM_PCT && (($otm = $leg->otmPct()) === null || $otm <= 0 || $otm >= 100)) {
            $out[] = $this->leg($id, 'otm_pct_invalid');
        }
        if (! $leg->isFringe()) {
            return $out;
        }
        if (! $leg->hasFastExit() || ! $leg->hasFastExitMaxHold()) {
            $out[] = $this->leg($id, 'fringe_requires_fast_exit_max_hold');
        }
        if (($minutes = $leg->fastExitMaxHoldMinutes()) !== null && $minutes <= 0) {
            $out[] = $this->leg($id, 'fast_exit_max_hold_minutes_invalid');
        }
        if (($days = $leg->fastExitMaxHoldDte()) !== null && $days < 0) {
            $out[] = $this->leg($id, 'fast_exit_max_hold_dte_invalid');
        }
        if (($multiple = $leg->fastExitProfitTargetMult()) !== null && $multiple <= 1) {
            $out[] = $this->leg($id, 'fast_exit_profit_target_mult_invalid');
        }
        if (($stop = $leg->fastExitHardStopPct()) !== null && ($stop <= 0 || $stop >= 100)) {
            $out[] = $this->leg($id, 'fast_exit_hard_stop_pct_invalid');
        }

        return $out;
    }

    /**
     * @param  array{per_play_max_usd?: float, per_day_max_usd?: float, max_legs?: int}  $caps
     * @return list<string>
     */
    public function capBlockers(ThesisSpec $spec, array $caps): array
    {
        $out = [];

        $maxLegs = (int) ($caps['max_legs'] ?? 0);
        if ($maxLegs > 0 && count($spec->legs()) > $maxLegs) {
            $out[] = 'legs_exceed_max_open';
        }

        $perPlay = (float) ($caps['per_play_max_usd'] ?? 0.0);
        $perDay = (float) ($caps['per_day_max_usd'] ?? 0.0);
        $dayTotal = 0.0;

        foreach ($spec->legs() as $leg) {
            // Only notional legs carry an unambiguous authoring-time dollar
            // figure. Share- and balance-fraction legs need a live quote or
            // balance, so they are left to the rails and surfaced as warnings.
            $usd = $leg->sizingMode() === ThesisSpecLeg::SIZING_NOTIONAL
                ? ($leg->maxUsd() ?? $leg->targetUsd())
                : null;

            if ($usd === null) {
                continue;
            }

            $dayTotal += $usd;
            if ($perPlay > 0.0 && $usd > $perPlay) {
                $out[] = $this->leg($leg->legId(), 'sizing_exceeds_per_play_cap');
            }
        }

        if ($perDay > 0.0 && $dayTotal > $perDay) {
            $out[] = 'notional_total_exceeds_per_day_cap';
        }

        return $out;
    }

    /** @return list<string> */
    public function warnings(ThesisSpec $spec): array
    {
        $out = [];

        foreach ($spec->legs() as $leg) {
            $id = $leg->legId();

            // A balance-fraction leg with no absolute ceiling scales with the
            // account unbounded — fine while the account is tiny, worth a flag
            // before it is not.
            if ($leg->sizingMode() === ThesisSpecLeg::SIZING_BALANCE_FRACTION && $leg->maxUsd() === null && ! $leg->isProbe()) {
                $out[] = $this->leg($id, 'balance_fraction_without_max_usd_ceiling');
            }

            // Only a time stop and a thesis stop are machine-checkable without a
            // mark; a price stop and target need live quotes. A leg whose only
            // exit is a free-text thesis stop cannot be auto-exited — the held
            // position would be operator-watched only.
            $machineExit = $leg->priceStop() !== null || $leg->targetPct() !== null || $leg->timeStop() !== null;
            if ($leg->hasAnyInvalidationTrigger() && ! $machineExit) {
                $out[] = $this->leg($id, 'only_discretionary_thesis_stop');
            }
        }

        return $out;
    }

    private function leg(string $id, string $reason): string
    {
        return sprintf('leg[%s]:%s', $id === '' ? '?' : $id, $reason);
    }

    private function parseDate(string $value): ?Carbon
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            $date = Carbon::createFromFormat('Y-m-d', $value);
        } catch (\Throwable) {
            return null;
        }

        // createFromFormat rolls invalid components over (2026-13-40 becomes a
        // date in 2027); reject anything that does not round-trip exactly.
        if ($date === null || $date->format('Y-m-d') !== $value) {
            return null;
        }

        return $date->startOfDay();
    }
}
