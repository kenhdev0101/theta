<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ─────────────────────────────────────────────────────────────────────────
// CONVEX PAPER-CUT POSTURE (2026-10-07, ops/claude).
// This box runs the read-only convex thesis family ONLY. Every cron that is
// not theta:convex-ingest is disabled so the box places no broker orders and
// the paper-cut readbacks stay clean. The live option trading family
// (intent-producer / autopilot / reconcile) is OFF here; the cockpit snapshot/
// monitor jobs are OFF too (not thesis-family). Re-enable by reverting this
// block. Defense-in-depth: harness.kill_switch is also forced on (no placement)
// in config/harness.php, so even a replayed queued job cannot place.
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

// The convex broker probe command is installed but deliberately unscheduled
// on this box; the existing default-on kill switch remains unchanged.
// Fringe-convexity data-plane ingestion (READ-ONLY). Registration is
// unconditional; the command self-disarms when harness.convex.enabled is false
// (scheduler arm only — a manual `theta:convex-ingest --once` always sweeps).
// RTH only; the feed is live option truth, which does not quote pre-open.
Schedule::command('theta:convex-ingest')
    ->weekdays()
    ->timezone('America/New_York')
    ->everyFiveMinutes()
    ->between('9:30', '16:00')
    ->withoutOverlapping();
