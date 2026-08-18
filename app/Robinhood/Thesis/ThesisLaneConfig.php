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

    /** Claimed by an executor tick (double-processing guard). */
    public const STATUS_CLAIMED = 'thesis_claimed';

    /** The lane refuses to act further without an operator/reconcile answer. */
    public const STATUS_ENTRY_BLOCKED = 'thesis_entry_blocked';

    /** Entry window shut with no fill. Terminal for the day. */
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
        self::STATUS_ENTRY_BLOCKED,
        'submitted',
        'open',
        'partially_filled',
        'filled',
    ];

    /** Rows the executor may place from. @var list<string> */
    public const PLACEABLE_STATUSES = [self::STATUS_ASSEMBLED];

    /**
     * Rows with a live entry order at the broker. `submitted` is what the
     * executor writes; `theta:option-reconcile` promotes it to `open` /
     * `partially_filled` from broker truth, so all three are "working".
     *
     * @var list<string>
     */
    public const WORKING_STATUSES = ['submitted', 'open', 'partially_filled'];

    /** Broker order states that prove a cancel landed. @var list<string> */
    public const CANCELLED_ORDER_STATES = ['cancelled', 'canceled', 'rejected'];

    /** Broker order states that prove the order is done and filled. @var list<string> */
    public const FILLED_ORDER_STATES = ['filled'];

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

    public static function windowCloseEt(array $cfg): string
    {
        $value = trim((string) (self::entry($cfg)['window_close_et'] ?? ''));

        return $value === '' ? '10:15' : $value;
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
     * True while the Eastern wall clock is before the configured window close.
     * A malformed value fails closed (window already shut) rather than
     * silently working an entry all session.
     */
    public static function withinEntryWindow(array $cfg, ?Carbon $now = null): bool
    {
        $et = ($now ?? Carbon::now())->copy()->setTimezone('America/New_York');
        $close = self::minutesFromEtTime(self::windowCloseEt($cfg));

        if ($close === null) {
            return false;
        }

        return ($et->hour * 60 + $et->minute) < $close;
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
