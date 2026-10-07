<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('theta:option-intent-producer')
    ->weekdays()
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('theta:option-autopilot')
    ->weekdays()
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('theta:option-reconcile')
    ->weekdays()
    ->everyMinute()
    ->withoutOverlapping();

// Warm the cockpit's broker-truth snapshot cache (read-only). Runs weekdays
// to mirror the live loop; off-hours/weekends the cockpit lazily refreshes on
// first view under a lock, so a single read serves all dashboard tabs.
Schedule::command('theta:cockpit-snapshot')
    ->weekdays()
    ->everyMinute()
    ->withoutOverlapping();

// Behaviour monitor: every 30 min from market open through midday (Eastern),
// pushing a digest each cycle (--heartbeat). Eastern timezone so the window
// tracks the session across DST.
Schedule::command('theta:cockpit-monitor --heartbeat')
    ->weekdays()
    ->timezone('America/New_York')
    ->everyThirtyMinutes()
    ->between('9:30', '12:00')
    ->withoutOverlapping();

// Grant-expiry safety net: a daily pre-market check that pushes only when
// something needs attention (e.g. the OAuth grant is within its warn window).
Schedule::command('theta:cockpit-monitor')
    ->timezone('America/New_York')
    ->dailyAt('8:00')
    ->withoutOverlapping();

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
