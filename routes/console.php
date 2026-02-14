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

Schedule::command('monitor:check-uptime')->everyMinute();
Schedule::command('monitor:check-certificate')->daily();
Schedule::command('monitor:check-domain-expiration')->daily();
Schedule::command('monitor:aggregate-check-metrics')->hourly();
Schedule::command('monitor:prune-check-history')->dailyAt('01:00');
