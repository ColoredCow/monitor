<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Overrides spatie/laravel-uptime-monitor's monitor:delete, which hardcodes
 * the base Spatie model and issues a HARD delete (destroying check history
 * and bypassing the soft-delete retention window). This version resolves the
 * configured monitor model, so delete() is a soft delete.
 */
class DeleteMonitor extends Command
{
    protected $signature = 'monitor:delete {url}';

    protected $description = 'Soft-delete a monitor (restorable until the retention purge)';

    public function handle(): int
    {
        $modelClass = config('uptime-monitor.monitor_model');
        $url = $this->argument('url');

        $monitor = $modelClass::where('url', $url)->first();

        if (! $monitor) {
            $this->error("Monitor {$url} is not configured");

            return self::FAILURE;
        }

        if ($this->confirm("Are you sure you want stop monitoring {$monitor->url}?")) {
            $monitor->delete();

            $this->warn("{$monitor->url} will not be monitored anymore");
        }

        return self::SUCCESS;
    }
}
