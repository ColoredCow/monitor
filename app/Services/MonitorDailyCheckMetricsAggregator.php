<?php

namespace App\Services;

use App\Models\MonitorDailyCheckMetric;
use App\Models\MonitorCheckLog;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class MonitorDailyCheckMetricsAggregator
{
    public function aggregate(CarbonInterface $fromDate, CarbonInterface $toDate, string $timezone = 'UTC'): int
    {
        $fromLocal = Carbon::instance($fromDate)->timezone($timezone)->startOfDay();
        $toLocal = Carbon::instance($toDate)->timezone($timezone)->endOfDay();

        $fromUtc = $fromLocal->copy()->utc();
        $toUtc = $toLocal->copy()->utc();

        $logs = MonitorCheckLog::query()
            ->whereBetween('checked_at', [$fromUtc, $toUtc])
            ->orderBy('checked_at')
            ->get();

        $groupedLogs = $logs->groupBy(function (MonitorCheckLog $log) use ($timezone) {
            $localDate = $log->checked_at->copy()->timezone($timezone)->toDateString();

            return "{$log->monitor_id}|{$log->check_type}|{$localDate}";
        });

        foreach ($groupedLogs as $compositeKey => $dayLogs) {
            [$monitorId, $checkType, $date] = explode('|', $compositeKey);
            $metrics = $this->buildMetrics($dayLogs);

            MonitorDailyCheckMetric::updateOrCreate(
                [
                    'monitor_id' => (int) $monitorId,
                    'check_type' => $checkType,
                    'date' => $date,
                    'timezone' => $timezone,
                ],
                [
                    'total_checks' => $metrics['total_checks'],
                    'successful_checks' => $metrics['successful_checks'],
                    'warning_checks' => $metrics['warning_checks'],
                    'failed_checks' => $metrics['failed_checks'],
                    'success_ratio' => $metrics['success_ratio'],
                    'worst_status' => $metrics['worst_status'],
                    'avg_response_time_ms' => $metrics['avg_response_time_ms'],
                    'p95_response_time_ms' => $metrics['p95_response_time_ms'],
                    'computed_at' => Carbon::now()->utc(),
                ]
            );
        }

        return $groupedLogs->count();
    }

    protected function buildMetrics(Collection $logs): array
    {
        $totalChecks = $logs->count();
        $successfulChecks = $logs->where('status', MonitorCheckLogService::STATUS_SUCCESS)->count();
        $warningChecks = $logs->where('status', MonitorCheckLogService::STATUS_WARNING)->count();
        $failedChecks = $logs->where('status', MonitorCheckLogService::STATUS_FAILED)->count();

        $responseTimes = $logs
            ->pluck('response_time_ms')
            ->filter(fn ($responseTime) => $responseTime !== null)
            ->map(fn ($responseTime) => (int) $responseTime)
            ->values()
            ->all();

        return [
            'total_checks' => $totalChecks,
            'successful_checks' => $successfulChecks,
            'warning_checks' => $warningChecks,
            'failed_checks' => $failedChecks,
            'success_ratio' => $totalChecks > 0 ? round(($successfulChecks / $totalChecks) * 100, 2) : 0,
            'worst_status' => $this->resolveWorstStatus($successfulChecks, $warningChecks, $failedChecks),
            'avg_response_time_ms' => count($responseTimes) ? (int) round(array_sum($responseTimes) / count($responseTimes)) : null,
            'p95_response_time_ms' => $this->calculatePercentile($responseTimes, 0.95),
        ];
    }

    protected function resolveWorstStatus(int $successfulChecks, int $warningChecks, int $failedChecks): string
    {
        if ($failedChecks > 0) {
            return MonitorCheckLogService::STATUS_FAILED;
        }

        if ($warningChecks > 0) {
            return MonitorCheckLogService::STATUS_WARNING;
        }

        if ($successfulChecks > 0) {
            return MonitorCheckLogService::STATUS_SUCCESS;
        }

        return MonitorCheckLogService::STATUS_UNKNOWN;
    }

    protected function calculatePercentile(array $values, float $percentile): ?int
    {
        if (empty($values)) {
            return null;
        }

        sort($values);
        $index = (int) ceil($percentile * count($values)) - 1;
        $index = max(0, min($index, count($values) - 1));

        return (int) $values[$index];
    }
}
