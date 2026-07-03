<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
|
| This file is where you may define all of your Closure based console
| commands. Each Closure is bound to a command instance allowing a
| simple approach to interacting with each command's IO methods.
|
*/

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

use Illuminate\Support\Facades\Schedule;

// Core uptime monitoring always runs, regardless of the history feature flag.
Schedule::command('monitor:check-uptime')->everyMinute();
Schedule::command('monitor:check-certificate')->daily();
Schedule::command('monitor:check-domain-expiration')->daily();

// Monitor-history maintenance only runs when the feature is enabled, so a
// "default off" deployment performs no aggregation or pruning. withoutOverlapping()
// prevents a slow aggregation run from stacking on the next hourly tick.
$historyEnabled = fn () => (bool) config('monitor-history.enabled');

Schedule::command('monitor:aggregate-check-metrics')
    ->hourly()
    ->when($historyEnabled)
    ->withoutOverlapping();

Schedule::command('monitor:prune-check-history')
    ->dailyAt('01:00')
    ->when($historyEnabled)
    ->withoutOverlapping();

// Organization retention: hard-purge organizations soft-deleted beyond the
// configurable window (organizations.purge_after_days).
Schedule::command('organizations:purge-deleted')
    ->dailyAt('02:30')
    ->withoutOverlapping();
