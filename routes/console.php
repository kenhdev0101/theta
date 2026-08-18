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
