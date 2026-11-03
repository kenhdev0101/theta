<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ─────────────────────────────────────────────────────────────────────────
// CONVEX THESIS FAMILY POSTURE (2026-10-07).
// This box runs only the convex thesis family. The matrix ingest stays read-only;
// the separate convex-auto loop may place bounded option orders when its
// explicit entry gate is enabled. The live general option trading family
// (intent-producer / autopilot / reconcile) is OFF here; the cockpit snapshot/
// monitor jobs are OFF too (not thesis-family). The existing kill switch and
// broker rails still govern every order; this scheduler does not change them.
// ─────────────────────────────────────────────────────────────────────────
// Schedule::command('theta:option-intent-producer')  // DISABLED — live order producer
//     ->weekdays()->everyMinute()->withoutOverlapping();
// Schedule::command('theta:option-autopilot')        // DISABLED — live order placement
//     ->weekdays()->everyMinute()->withoutOverlapping();
// Schedule::command('theta:option-reconcile')        // DISABLED — trading-lane reconcile
//     ->weekdays()->everyMinute()->withoutOverlapping();
// Schedule::command('theta:cockpit-snapshot')        // DISABLED — not thesis-family (read-only)
//     ->weekdays()->everyMinute()->withoutOverlapping();
// Schedule::command('theta:cockpit-monitor --heartbeat')  // DISABLED — not thesis-family
//     ->weekdays()->timezone('America/New_York')->everyThirtyMinutes()->between('9:30', '12:00')->withoutOverlapping();
// Schedule::command('theta:cockpit-monitor')         // DISABLED — not thesis-family (grant-expiry net)
//     ->timezone('America/New_York')->dailyAt('8:00')->withoutOverlapping();

// Machine-owned convex stewardship runs continuously. New entries require
// convex.auto_entry_enabled; the existing broker kill switch and rails remain.
Schedule::command('theta:convex-auto')
    ->everyTenSeconds()
    ->runInBackground(); // ConvexLoopLock serializes workers and releases on crash.
Schedule::command('theta:convex-reconcile')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->runInBackground();
// Only the optional evidence stream expires; broker journals/matrix stay durable.
Schedule::command('theta:convex-evidence --prune')
    ->timezone('UTC')->dailyAt('00:15')->withoutOverlapping(10)->runInBackground();
// Fringe-convexity data-plane ingestion (READ-ONLY). Registration is
// unconditional; the command self-disarms when harness.convex.enabled is false
// (scheduler arm only — a manual `theta:convex-ingest --once` always sweeps).
// RTH only; the feed is live option truth, which does not quote pre-open.
Schedule::command('theta:convex-ingest')
    ->weekdays()
    ->timezone('America/New_York')
    ->everyFiveMinutes()
    ->between('9:30', '16:00')
    ->withoutOverlapping(10)
    ->runInBackground();

// The optional thesis lane is installed without changing this box's live
// Convex posture. Registration requires its explicit configuration gate.
if ((bool) config('harness.thesis.enabled', false)) {
    // Thesis-position lane. Registration is unconditional: both commands
    // self-disarm when harness.thesis.enabled is false, matching the scalp lane's
    // pattern. Neither reads harness.mode — the lane is approval-first by
    // construction (activating a ThesisIntent is the operator's approval).
    //
    // Pre-open assembly derives contracts and limits against live quotes ~45 min
    // ahead of the open, so the operator never has to be awake for the derivation.
    Schedule::command('theta:thesis-assemble')
        ->weekdays()
        ->timezone('America/New_York')
        ->dailyAt('8:45')
        ->withoutOverlapping();

    // Post-open fallback. Options do not trade pre-open, so whether Robinhood
    // serves a usable option quote at 08:45 ET is unmeasured; if it returns no
    // bid/ask, the 08:45 tick skips with a named reason and the day has no slate
    // at all. Assembly is idempotent (hasLivePlay + hasLivePlayForUnderlying), so
    // a second tick just after the bell is a no-op on a normal day and the safety
    // net on an abnormal one. The 08:45 run stays authoritative — it is the one
    // that leaves the operator a pre-open window to act on what it derived.
    Schedule::command('theta:thesis-assemble')
        ->weekdays()
        ->timezone('America/New_York')
        ->dailyAt('9:31')
        ->withoutOverlapping();

    // Open executor: works the entry from the bell through the window close
    // (harness.thesis.entry.window_close_et, default 10:15 ET), then sweeps.
    //
    // COUPLED TO harness.thesis.entry.window_close_et. These bounds are mirrored
    // in ThesisLaneConfig::SCHEDULE_OPEN_ET / SCHEDULE_SWEEP_CLOSE_ET, which clamp
    // a configured close that would land past this sweep window — otherwise a
    // resting gfd entry order gets zero cancel ticks and rests until 16:00.
    // Change the cron and those constants together, never one alone.
    //
    // withoutOverlapping only guards the scheduler against itself; a manual
    // `artisan theta:thesis-execute` can still run alongside a tick, which is why
    // the command holds its own atomic claim over the cancel-then-replace path.
    Schedule::command('theta:thesis-execute')
        ->weekdays()
        ->timezone('America/New_York')
        ->everyMinute()
        ->between('9:30', '10:20')
        ->withoutOverlapping();

    Schedule::command('theta:option-reconcile --strategy=thesis_position')
        ->weekdays()->everyMinute()->withoutOverlapping();
}

// Execute only the explicitly selected initial basket event; never create a
// fresh daily batch. The command owns the same account lock as Convex.
if ((bool) config('harness.basket.enabled', false)) {
    Schedule::command('theta:basket-rebalance --arm --trigger=scheduled')
        ->weekdays()->timezone('America/New_York')->everyMinute()
        ->between('9:30', '16:10')->withoutOverlapping(5)->runInBackground();
}
