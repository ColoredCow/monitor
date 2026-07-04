<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Services\CreditMeteringService;
use App\Services\MonitorCheckLogService;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Psr\Http\Message\ResponseInterface;
use Spatie\SslCertificate\SslCertificate;
use Spatie\UptimeMonitor\Models\Enums\UptimeStatus;
use Spatie\UptimeMonitor\Models\Monitor as SpatieMonitor;

class Monitor extends SpatieMonitor
{
    use BelongsToOrganization;
    use SoftDeletes;

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->casts = array_merge($this->casts, [
            'domain_expires_at' => 'datetime',
        ]);
    }

    /**
     * Overrides the vendor scope. Spatie's MonitorRepository funnels every
     * check-selection query through Monitor::enabled() (resolved via config
     * uptime-monitor.monitor_model), so the balance gate here pauses uptime
     * AND certificate checks for organizations that are out of credits.
     * whereHas('organization') also drops monitors of soft-deleted orgs.
     */
    public function scopeEnabled($query)
    {
        return $query
            ->where(function ($q) {
                $q->where('uptime_check_enabled', true)
                    ->orWhere('certificate_check_enabled', true);
            })
            ->whereHas('organization', fn ($q) => $q->where('credit_balance', '>', 0));
    }

    public function scopeDomainCheckEnabled(Builder $query): Collection
    {
        return $query
            ->where('domain_check_enabled', true)
            ->whereHas('organization', fn ($q) => $q->where('credit_balance', '>', 0))
            ->get();
    }

    public function uptimeRequestSucceeded(ResponseInterface $response): void
    {
        app(CreditMeteringService::class)->recordCheck($this, MonitorCheckLogService::CHECK_TYPE_UPTIME);

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

        app(MonitorCheckLogService::class)->logCheckIfEnabled(
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
        app(CreditMeteringService::class)->recordCheck($this, MonitorCheckLogService::CHECK_TYPE_UPTIME);

        parent::uptimeRequestFailed($reason);

        if (! $this->uptime_check_enabled) {
            return;
        }

        $updatedMonitor = $this->fresh();
        if (! $updatedMonitor) {
            return;
        }

        app(MonitorCheckLogService::class)->logCheckIfEnabled(
            monitor: $updatedMonitor,
            checkType: MonitorCheckLogService::CHECK_TYPE_UPTIME,
            status: MonitorCheckLogService::STATUS_FAILED,
            checkedAt: $updatedMonitor->uptime_last_check_date,
            message: 'Uptime check failed.',
            failureReason: $reason,
        );
    }

    /**
     * Vendor checkCertificate() branches into exactly one of these two per
     * executed certificate check — that makes them the single metering
     * point for certificate billing.
     */
    public function setCertificate(SslCertificate $certificate): void
    {
        app(CreditMeteringService::class)->recordCheck($this, MonitorCheckLogService::CHECK_TYPE_CERTIFICATE);

        parent::setCertificate($certificate);
    }

    public function setCertificateException(Exception $exception): void
    {
        app(CreditMeteringService::class)->recordCheck($this, MonitorCheckLogService::CHECK_TYPE_CERTIFICATE);

        parent::setCertificateException($exception);
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
