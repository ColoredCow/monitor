<?php

namespace App\Console\Commands;

use App\Services\MonitorDailyCheckMetricsAggregator;
use Carbon\Carbon;
use Illuminate\Console\Command;

class AggregateMonitorCheckMetrics extends Command
{
    protected $signature = 'monitor:aggregate-check-metrics
                            {--from= : Start date (Y-m-d) in selected timezone}
                            {--to= : End date (Y-m-d) in selected timezone}
                            {--timezone= : Timezone to use for daily buckets}
                            {--lookback= : Number of days to aggregate ending today}';

    protected $description = 'Aggregate monitor check logs into daily metrics';

    public function handle(MonitorDailyCheckMetricsAggregator $aggregator): int
    {
        $timezone = $this->resolveTimezone();
        $lookbackDays = (int) ($this->option('lookback') ?: config('monitor-history.aggregation.lookback_days', 7));

        $from = $this->option('from')
            ? Carbon::parse((string) $this->option('from'), $timezone)->startOfDay()
            : Carbon::now($timezone)->subDays($lookbackDays)->startOfDay();

        $to = $this->option('to')
            ? Carbon::parse((string) $this->option('to'), $timezone)->endOfDay()
            : Carbon::now($timezone)->endOfDay();

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        $rows = $aggregator->aggregate($from, $to, $timezone);

        $this->info("Aggregated {$rows} monitor day bucket(s) for timezone {$timezone}.");

        return self::SUCCESS;
    }

    protected function resolveTimezone(): string
    {
        $timezone = (string) ($this->option('timezone') ?: config('app.timezone', 'UTC'));

        if (! in_array($timezone, timezone_identifiers_list(), true)) {
            return 'UTC';
        }

        return $timezone;
    }
}
