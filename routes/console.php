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
