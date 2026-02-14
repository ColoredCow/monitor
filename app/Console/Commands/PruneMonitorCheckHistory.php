<?php

namespace App\Console\Commands;

use App\Models\MonitorCheckLog;
use Carbon\Carbon;
use Illuminate\Console\Command;

class PruneMonitorCheckHistory extends Command
{
    protected $signature = 'monitor:prune-check-history
                            {--older-than-days= : Retention period in days}
                            {--dry-run : Preview delete counts without deleting}';

    protected $description = 'Prune raw monitor check logs older than the retention window';

    public function handle(): int
    {
        $days = (int) ($this->option('older-than-days') ?: config('monitor-history.raw_log_retention_days', 180));
        $days = max(1, $days);

        $cutoff = Carbon::now()->utc()->subDays($days)->startOfDay();
        $query = MonitorCheckLog::query()->where('checked_at', '<', $cutoff);
        $count = (clone $query)->count();

        if ($this->option('dry-run')) {
            $this->info("Dry run: {$count} raw check log(s) older than {$cutoff->toDateTimeString()} UTC would be deleted.");

            return self::SUCCESS;
        }

        $deleted = $query->delete();
        $this->info("Deleted {$deleted} raw check log(s) older than {$cutoff->toDateTimeString()} UTC.");

        return self::SUCCESS;
    }
}
