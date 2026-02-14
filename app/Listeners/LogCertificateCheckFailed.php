<?php

namespace App\Listeners;

use App\Services\MonitorCheckLogService;
use Carbon\Carbon;
use Spatie\UptimeMonitor\Events\CertificateCheckFailed;

class LogCertificateCheckFailed
{
    public function __construct(protected MonitorCheckLogService $monitorCheckLogService)
    {
    }

    public function handle(CertificateCheckFailed $event): void
    {
        $monitor = $event->monitor;
        if (! $monitor->certificate_check_enabled) {
            return;
        }

        $this->monitorCheckLogService->logCheck(
            monitor: $monitor,
            checkType: MonitorCheckLogService::CHECK_TYPE_CERTIFICATE,
            status: MonitorCheckLogService::STATUS_FAILED,
            checkedAt: Carbon::now()->utc(),
            message: 'Certificate check failed.',
            failureReason: $event->reason,
            metadata: [
                'certificate_expiration_date' => optional($monitor->certificate_expiration_date)?->toDateString(),
            ],
        );
    }
}
