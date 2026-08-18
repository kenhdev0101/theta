<?php

declare(strict_types=1);

namespace App\Robinhood\Thesis;

use Illuminate\Support\Carbon;

/**
 * Thesis-position lane vocabulary and the rails-facing config overlay.
 *
 * Two things live here because both the assembler and the executor need them
 * and neither owns them:
 *
 * 1. The lane's journal statuses. They are deliberately lane-private. The
 *    scalp autopilot selects work with
 *    `RobinhoodTradeJournal::where('asset_class','option')->whereIn('status',['reviewed'])`
 *    and is scheduled every minute all session — so a thesis play written as
 *    `reviewed` would be placed by the scalp lane, outside the thesis entry
 *    window and without the chase discipline. A private status keeps the two
 *    lanes' claim sets disjoint, following the existing precedent of
 *    lane-specific statuses (`autopilot_claimed`, `execution_deferred`,
 *    `scan_suppressed`). Unknown statuses degrade to a humanized label in the
 *    cockpit Lexicon, so no cockpit change is required.
 *
 * 2. The caps overlay. `OptionRailPipeline` reads every cap from
 *    `cfg.options.*`, which is calibrated for the parked intraday scalp lane
 *    ($75 per play, $50 per underlying). A 30-70 DTE thesis position is
 *    several hundred dollars, so the unmodified rails would block the whole
 *    lane as `per_play_cap_exceeded` — a green deploy over an inert policy.
 *    The overlay swaps in the thesis lane's own precommitted caps and leaves
 *    every other rail (spread, quote freshness, permission, duplicate,
 *    review, settled cash, buying power) exactly as it is.
 */
final class ThesisLaneConfig
{
    /** Assembly wrote a derived contract + limit; the executor may place it. */
    public const STATUS_ASSEMBLED = 'thesis_assembled';

    /** Claimed by an executor tick for a FIRST placement (double-processing guard). */
    public const STATUS_CLAIMED = 'thesis_claimed';

    /**
     * Claimed by an executor tick for a cancel-then-replace, held across the
     * whole critical section. `withoutOverlapping` only guards the scheduler
     * against itself, so a manual `artisan theta:thesis-execute` alongside a
     * scheduled tick could otherwise have both invocations cancel order A
     * (the second cancel of an already-cancelled order reads back `cancelled`,
     * i.e. clear-to-replace) and both place — two live buy-to-open orders,
     * one an untracked orphan. This status is the mutex.
     */
    public const STATUS_REPEGGING = 'thesis_repegging';

    /**
     * The lane will not re-peg or re-place this play. It does NOT mean the
     * lane stops watching. A blocked play can still have a live gfd order at
     * the broker — which would otherwise fill at 15:59 on a play the no-chase
     * rule already expired — so blocked rows stay in SUPERVISED_STATUSES and
     * leave the lane only when broker truth proves no live order remains.
     */
    public const STATUS_ENTRY_BLOCKED = 'thesis_entry_blocked';

    /**
     * The entry order filled, so there is no resting order left to supervise
     * and the row leaves SUPERVISED_STATUSES. The lane does NOT claim the
     * terminal write: `theta:option-reconcile` owns the `filled` status,
     * quantity and price from broker truth. This status is the gap between
     * the two, and it exists so supervision stops re-stamping a fill forever.
     */
    public const STATUS_ENTRY_FILLED = 'thesis_entry_filled';

    /** Entry window shut with no live order left. Terminal for the day. */
    public const STATUS_EXPIRED_UNFILLED = 'expired_unfilled';

    public const STRATEGY_LABEL = 'thesis_position';

    /**
     * A thesis with a play in one of these states already owns its slot —
     * assembly must not create a second one.
     *
     * @var list<string>
     */
    public const NON_TERMINAL_PLAY_STATUSES = [
        self::STATUS_ASSEMBLED,
        self::STATUS_CLAIMED,
        self::STATUS_REPEGGING,
        self::STATUS_ENTRY_BLOCKED,
        self::STATUS_ENTRY_FILLED,
        'submitted',
        'open',
        'partially_filled',
        'filled',
    ];

    /** Rows the executor may place from. @var list<string> */
    public const PLACEABLE_STATUSES = [self::STATUS_ASSEMBLED];

    /**
     * Quiescent rows with a live entry order and nobody mid-flight — the only
     * rows a re-peg may claim. `submitted` is what the executor writes;
     * `theta:option-reconcile` promotes it to `open` / `partially_filled` from
     * broker truth.
     *
     * @var list<string>
     */
    public const WORKING_STATUSES = ['submitted', 'open', 'partially_filled'];

    /**
     * Every row the lane must keep watching because a live broker order may
     * still exist. Supervision is what guarantees no order outlives the entry
     * window; it is strictly wider than the set a re-peg may claim.
     *
     * @var list<string>
     */
    public const SUPERVISED_STATUSES = [
        'submitted',
        'open',
        'partially_filled',
        self::STATUS_REPEGGING,
        self::STATUS_ENTRY_BLOCKED,
    ];

    /** Broker order states that prove no exposure remains. @var list<string> */
    public const DEAD_ORDER_STATES = ['cancelled', 'canceled', 'rejected', 'expired'];

    /** Broker order states that prove the order is done and filled. @var list<string> */
    public const FILLED_ORDER_STATES = ['filled'];

    /**
     * Broker order states that mean the order is still working. Orphan
     * adoption requires one of these explicitly rather than "not dead", so an
     * unrecognised state is never adopted and then cancelled.
     *
     * @var list<string>
     */
    public const OPEN_ORDER_STATES = [
        'queued', 'unconfirmed', 'confirmed', 'placed', 'pending',
        'pending_cancel', 'partially_filled', 'open', 'new', 'accepted',
    ];

    /**
     * The scheduled sweep bounds in routes/console.php. `window_close_et` is
     * env-configurable but the schedule is not, so a close past the sweep
     * bound would leave a resting gfd order with ZERO cancel ticks — it would
     * rest to 16:00 ET. resolveWindowClose() clamps against these; change one
     * and you must change the other.
     */
    public const SCHEDULE_OPEN_ET = '9:30';

    public const SCHEDULE_SWEEP_CLOSE_ET = '10:20';

    /** Minimum minutes of sweep ticks that must exist after the window shuts. */
    public const SWEEP_MARGIN_MINUTES = 5;

    public const DEFAULT_WINDOW_CLOSE_ET = '10:15';

    /** The lane block. */
    public static function lane(array $cfg): array
    {
        return (array) ($cfg['thesis'] ?? []);
    }

    /** The lane's entry-window / fill-working block. */
    public static function entry(array $cfg): array
    {
        return (array) (self::lane($cfg)['entry'] ?? []);
    }

    /**
     * Resolve `window_close_et` against the scheduled sweep bound, fail-closed.
     *
     * The entry window is env-configurable; the cron in routes/console.php is
     * not. A configured close at or past SCHEDULE_SWEEP_CLOSE_ET would leave a
     * resting gfd entry order with no cancel tick after it, so it would rest
     * until the 16:00 close and could fill hours after the no-chase rule said
     * the play expired. An out-of-range or unparseable value therefore clamps
     * to DEFAULT_WINDOW_CLOSE_ET and reports why.
     *
     * @return array{value: string, minutes: int, configured: string, clamped: bool, reason: string|null}
     */
    public static function resolveWindowClose(array $cfg): array
    {
        $configured = trim((string) (self::entry($cfg)['window_close_et'] ?? ''));
        $default = self::DEFAULT_WINDOW_CLOSE_ET;
        $defaultMinutes = (int) self::minutesFromEtTime($default);

        $clamp = static fn (string $reason): array => [
            'value' => $default,
            'minutes' => $defaultMinutes,
            'configured' => $configured,
            'clamped' => true,
            'reason' => $reason,
        ];

        $minutes = self::minutesFromEtTime($configured);
        if ($minutes === null) {
            return $clamp($configured === '' ? 'window_close_unconfigured' : 'window_close_unparseable');
        }

        $open = (int) self::minutesFromEtTime(self::SCHEDULE_OPEN_ET);
        if ($minutes <= $open) {
            return $clamp('window_close_at_or_before_session_open');
        }

        $latest = (int) self::minutesFromEtTime(self::SCHEDULE_SWEEP_CLOSE_ET) - self::SWEEP_MARGIN_MINUTES;
        if ($minutes > $latest) {
            return $clamp('window_close_past_scheduled_sweep_bound');
        }

        return [
            'value' => $configured,
            'minutes' => $minutes,
            'configured' => $configured,
            'clamped' => false,
            'reason' => null,
        ];
    }

    public static function windowCloseEt(array $cfg): string
    {
        return self::resolveWindowClose($cfg)['value'];
    }

    public static function repriceSeconds(array $cfg): int
    {
        return max(1, (int) (self::entry($cfg)['reprice_seconds'] ?? 45));
    }

    public static function maxChaseBps(array $cfg): float
    {
        return max(0.0, (float) (self::entry($cfg)['max_chase_bps'] ?? 150.0));
    }

    /**
     * True while the Eastern wall clock is before the resolved window close.
     * A malformed value clamps to the default rather than either running all
     * session or shutting the lane out entirely.
     */
    public static function withinEntryWindow(array $cfg, ?Carbon $now = null): bool
    {
        $et = ($now ?? Carbon::now())->copy()->setTimezone('America/New_York');

        return ($et->hour * 60 + $et->minute) < self::resolveWindowClose($cfg)['minutes'];
    }

    /** Minutes past Eastern midnight for an "H:MM" / "HH:MM" string, or null. */
    public static function minutesFromEtTime(string $value): ?int
    {
        if (preg_match('/^(\d{1,2}):(\d{2})$/', trim($value), $matches) !== 1) {
            return null;
        }

        $hour = (int) $matches[1];
        $minute = (int) $matches[2];

        if ($hour > 23 || $minute > 59) {
            return null;
        }

        return $hour * 60 + $minute;
    }

    /**
     * Concurrency ceiling. Zero (or unconfigured) is a HARD STOP, never
     * "unbounded" — an unset cap must not read as permission to open.
     */
    public static function maxOpenPositions(array $cfg): int
    {
        return max(0, (int) (self::lane($cfg)['max_open_positions'] ?? 0));
    }

    /**
     * Clamp a thesis's own DTE band to the lane's guard rails. Without this
     * the dte_floor/dte_ceiling config is inert: the assembler would honour a
     * thesis band that reaches below the floor into short-dated spread noise
     * (the scalp lane's loss engine) or above the ceiling into LEAPS.
     *
     * @return array{dte_min: int, dte_max: int, floor: int, ceiling: int, clamped: bool, empty: bool}
     */
    public static function clampDteBand(array $cfg, int $dteMin, int $dteMax): array
    {
        $lane = self::lane($cfg);
        $floor = max(0, (int) ($lane['dte_floor'] ?? 0));
        $ceiling = max(0, (int) ($lane['dte_ceiling'] ?? 0));

        $min = $floor > 0 ? max($dteMin, $floor) : $dteMin;
        $max = $ceiling > 0 ? min($dteMax, $ceiling) : $dteMax;

        return [
            'dte_min' => $min,
            'dte_max' => $max,
            'floor' => $floor,
            'ceiling' => $ceiling,
            'clamped' => $min !== $dteMin || $max !== $dteMax,
            'empty' => $max < $min,
        ];
    }

    /** The per-play debit ceiling for one thesis: the tighter of the two precommitments. */
    public static function perPlayMaxDebit(array $cfg, float $thesisMaxDebit): float
    {
        $laneCap = (float) (self::lane($cfg)['per_play_max_debit'] ?? 0.0);
        $candidates = array_values(array_filter(
            [$thesisMaxDebit, $laneCap],
            static fn (float $value): bool => $value > 0.0,
        ));

        return $candidates === [] ? 0.0 : round(min($candidates), 2);
    }

    /**
     * The harness config the thesis lane hands to the resolver and the rails:
     * the full block with the option caps replaced by the lane's own.
     * Everything the overlay does not name keeps its configured value.
     */
    public static function railsConfig(array $cfg, float $perPlayMaxDebit): array
    {
        $options = (array) ($cfg['options'] ?? []);
        $multiplier = (float) ($options['contract_multiplier'] ?? 100.0);
        if ($multiplier <= 0.0) {
            $multiplier = 100.0;
        }

        $lane = self::lane($cfg);
        $perDay = (float) ($lane['per_day_max_debit'] ?? 0.0);

        $options['per_play_max_debit'] = $perPlayMaxDebit;
        // One thesis per underlying in this lane, so the per-underlying
        // ceiling is the per-play ceiling.
        $options['per_underlying_max_debit'] = $perPlayMaxDebit;
        $options['per_day_max_debit'] = $perDay;
        $options['daily_gross_entry_debit'] = $perDay;
        $options['max_open_positions'] = (int) ($lane['max_open_positions'] ?? 0);
        // Keep the contract-ask gate exactly equal to the per-play debit cap
        // so the resolver and the caps rail cannot disagree about the same
        // precommitment.
        $options['max_contract_ask'] = round($perPlayMaxDebit / $multiplier, 4);
        // The premium floor is a scalp-lane noise-band guard; a days-horizon
        // position is bounded by the debit cap, not a minimum tick price.
        $options['min_contract_ask'] = 0.0;

        $cfg['options'] = $options;

        return $cfg;
    }
}
