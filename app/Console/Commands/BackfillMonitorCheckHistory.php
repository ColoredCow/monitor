<?php

namespace App\Console\Commands;

use App\Models\Monitor;
use App\Services\MonitorCheckLogService;
use App\Services\MonitorDailyCheckMetricsAggregator;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Spatie\UptimeMonitor\Models\Enums\CertificateStatus;
use Spatie\UptimeMonitor\Models\Enums\UptimeStatus;

class BackfillMonitorCheckHistory extends Command
{
    protected $signature = 'monitor:backfill-check-history
                            {--monitor-id= : Backfill only one monitor by id}
                            {--days=30 : Size of the aggregation window (in days) rolled up after the snapshot; does NOT fabricate per-day history}
                            {--timezone= : Timezone used for daily aggregation}
                            {--dry-run : Preview rows without writing}';

    protected $description = 'Seed one synthetic check log per enabled check type from current monitor state (approximate, not real history)';

    public function handle(
        MonitorCheckLogService $logService,
        MonitorDailyCheckMetricsAggregator $aggregator
    ): int {
        $timezone = $this->resolveTimezone();
        $days = max(1, (int) $this->option('days'));
        $isDryRun = (bool) $this->option('dry-run');

        $query = Monitor::query()->orderBy('id');
        if ($this->option('monitor-id')) {
            $query->whereKey((int) $this->option('monitor-id'));
        }

        $monitors = $query->get();
        if ($monitors->isEmpty()) {
            $this->warn('No monitors found for backfill.');

            return self::SUCCESS;
        }

        $now = Carbon::now()->utc();
        $backfilledLogs = 0;

        foreach ($monitors as $monitor) {
            if ($monitor->uptime_check_enabled) {
                $payload = $this->buildUptimePayload($monitor, $now);
                $backfilledLogs += $this->writeOrPreview($isDryRun, $logService, $monitor, $payload);
            }

            if ($monitor->domain_check_enabled) {
                $payload = $this->buildDomainPayload($monitor, $now);
                $backfilledLogs += $this->writeOrPreview($isDryRun, $logService, $monitor, $payload);
            }

            if ($monitor->certificate_check_enabled) {
                $payload = $this->buildCertificatePayload($monitor, $now);
                $backfilledLogs += $this->writeOrPreview($isDryRun, $logService, $monitor, $payload);
            }
        }

        if ($isDryRun) {
            $this->info("Dry run complete. {$backfilledLogs} synthetic check logs would be written.");

            return self::SUCCESS;
        }

        $from = Carbon::now($timezone)->subDays($days)->startOfDay();
        $to = Carbon::now($timezone)->endOfDay();
        $aggregatedRows = $aggregator->aggregate($from, $to, $timezone);

        $this->info("Backfill complete. Wrote {$backfilledLogs} synthetic check logs.");
        $this->info("Aggregated {$aggregatedRows} day bucket(s) for timezone {$timezone}.");

        return self::SUCCESS;
    }

    protected function writeOrPreview(bool $isDryRun, MonitorCheckLogService $logService, Monitor $monitor, array $payload): int
    {
        if ($isDryRun) {
            $this->line(sprintf(
                'Monitor #%d [%s] -> %s (%s)',
                $monitor->id,
                $payload['check_type'],
                $payload['status'],
                $payload['checked_at']->toDateTimeString()
            ));

            return 1;
        }

        $logService->logCheck(
            monitor: $monitor,
            checkType: $payload['check_type'],
            status: $payload['status'],
            checkedAt: $payload['checked_at'],
            message: $payload['message'],
            failureReason: $payload['failure_reason'],
            metadata: $payload['metadata'],
        );

        return 1;
    }

    protected function buildUptimePayload(Monitor $monitor, Carbon $now): array
    {
        $status = match ($monitor->uptime_status) {
            UptimeStatus::UP => MonitorCheckLogService::STATUS_SUCCESS,
            UptimeStatus::DOWN => MonitorCheckLogService::STATUS_FAILED,
            default => MonitorCheckLogService::STATUS_UNKNOWN,
        };

        return [
            'check_type' => MonitorCheckLogService::CHECK_TYPE_UPTIME,
            'status' => $status,
            'checked_at' => $monitor->uptime_last_check_date ?? $monitor->updated_at ?? $now,
            'message' => 'Synthetic uptime state backfill.',
            'failure_reason' => $status === MonitorCheckLogService::STATUS_FAILED
                ? ($monitor->uptime_check_failure_reason ?: 'Backfilled from current monitor state.')
                : null,
            'metadata' => [
                'source' => 'backfill',
            ],
        ];
    }

    protected function buildDomainPayload(Monitor $monitor, Carbon $now): array
    {
        if (! $monitor->domain_expires_at) {
            return [
                'check_type' => MonitorCheckLogService::CHECK_TYPE_DOMAIN,
                'status' => MonitorCheckLogService::STATUS_UNKNOWN,
                'checked_at' => $monitor->updated_at ?? $now,
                'message' => 'Synthetic domain state backfill (no expiration date available).',
                'failure_reason' => null,
                'metadata' => [
                    'source' => 'backfill',
                    'days_until_expiration' => null,
                    'expiration_date' => null,
                ],
            ];
        }

        $daysUntilExpiration = Carbon::now()->startOfDay()
            ->diffInDays($monitor->domain_expires_at->copy()->startOfDay(), false);

        $status = match (true) {
            $daysUntilExpiration < 0 => MonitorCheckLogService::STATUS_FAILED,
            $daysUntilExpiration <= 30 => MonitorCheckLogService::STATUS_WARNING,
            default => MonitorCheckLogService::STATUS_SUCCESS,
        };

        return [
            'check_type' => MonitorCheckLogService::CHECK_TYPE_DOMAIN,
            'status' => $status,
            'checked_at' => $monitor->updated_at ?? $now,
            'message' => 'Synthetic domain state backfill.',
            'failure_reason' => $status === MonitorCheckLogService::STATUS_FAILED
                ? 'Domain is already expired.'
                : null,
            'metadata' => [
                'source' => 'backfill',
                'days_until_expiration' => $daysUntilExpiration,
                'expiration_date' => $monitor->domain_expires_at->toDateString(),
            ],
        ];
    }

    protected function buildCertificatePayload(Monitor $monitor, Carbon $now): array
    {
        $status = match ($monitor->certificate_status) {
            CertificateStatus::VALID => MonitorCheckLogService::STATUS_SUCCESS,
            CertificateStatus::INVALID => MonitorCheckLogService::STATUS_FAILED,
            default => MonitorCheckLogService::STATUS_UNKNOWN,
        };

        return [
            'check_type' => MonitorCheckLogService::CHECK_TYPE_CERTIFICATE,
            'status' => $status,
            'checked_at' => $monitor->updated_at ?? $now,
            'message' => 'Synthetic certificate state backfill.',
            'failure_reason' => $status === MonitorCheckLogService::STATUS_FAILED
                ? ($monitor->certificate_check_failure_reason ?: 'Backfilled from current monitor state.')
                : null,
            'metadata' => [
                'source' => 'backfill',
                'certificate_expiration_date' => optional($monitor->certificate_expiration_date)?->toDateString(),
                'certificate_issuer' => $monitor->certificate_issuer,
            ],
        ];
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
