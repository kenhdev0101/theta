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
