<?php

namespace App\Models;

use App\Services\MonitorCheckLogService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Psr\Http\Message\ResponseInterface;
use Spatie\UptimeMonitor\Models\Enums\UptimeStatus;
use Spatie\UptimeMonitor\Models\Monitor as SpatieMonitor;

class Monitor extends SpatieMonitor
{
    public function __construct()
    {
        parent::__construct();
        $this->casts = array_merge($this->casts, [
            'domain_expires_at' => 'datetime',
        ]);
    }

    public function scopeDomainCheckEnabled(Builder $query): Collection
    {
        return $query
            ->where('domain_check_enabled', true)
            ->get();
    }

    public function uptimeRequestSucceeded(ResponseInterface $response): void
    {
        parent::uptimeRequestSucceeded($response);

        if (! $this->uptime_check_enabled) {
            return;
        }

        $updatedMonitor = $this->fresh();
        if (! $updatedMonitor) {
            return;
        }

        $status = $this->mapUptimeStatusToCheckStatus($updatedMonitor->uptime_status);
        $failureReason = $status === MonitorCheckLogService::STATUS_FAILED
            ? $updatedMonitor->uptime_check_failure_reason
            : null;

        app(MonitorCheckLogService::class)->logCheck(
            monitor: $updatedMonitor,
            checkType: MonitorCheckLogService::CHECK_TYPE_UPTIME,
            status: $status,
            checkedAt: $updatedMonitor->uptime_last_check_date,
            message: $status === MonitorCheckLogService::STATUS_SUCCESS ? 'Uptime check succeeded.' : 'Uptime check failed.',
            failureReason: $failureReason,
        );
    }

    public function uptimeRequestFailed(string $reason): void
    {
        parent::uptimeRequestFailed($reason);

        if (! $this->uptime_check_enabled) {
            return;
        }

        $updatedMonitor = $this->fresh();
        if (! $updatedMonitor) {
            return;
        }

        app(MonitorCheckLogService::class)->logCheck(
            monitor: $updatedMonitor,
            checkType: MonitorCheckLogService::CHECK_TYPE_UPTIME,
            status: MonitorCheckLogService::STATUS_FAILED,
            checkedAt: $updatedMonitor->uptime_last_check_date,
            message: 'Uptime check failed.',
            failureReason: $reason,
        );
    }

    public function group()
    {
        return $this->belongsTo(Group::class);
    }

    public function checkLogs(): HasMany
    {
        return $this->hasMany(MonitorCheckLog::class);
    }

    public function dailyCheckMetrics(): HasMany
    {
        return $this->hasMany(MonitorDailyCheckMetric::class);
    }

    protected function mapUptimeStatusToCheckStatus(string $uptimeStatus): string
    {
        return match ($uptimeStatus) {
            UptimeStatus::UP => MonitorCheckLogService::STATUS_SUCCESS,
            UptimeStatus::DOWN => MonitorCheckLogService::STATUS_FAILED,
            default => MonitorCheckLogService::STATUS_UNKNOWN,
        };
    }
}
