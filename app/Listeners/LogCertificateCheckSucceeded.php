<?php

namespace App\Listeners;

use App\Services\MonitorCheckLogService;
use Carbon\Carbon;
use Spatie\UptimeMonitor\Events\CertificateCheckSucceeded;

class LogCertificateCheckSucceeded
{
    public function __construct(protected MonitorCheckLogService $monitorCheckLogService) {}

    public function handle(CertificateCheckSucceeded $event): void
    {
        $monitor = $event->monitor;
        if (! $monitor->certificate_check_enabled) {
            return;
        }

        $this->monitorCheckLogService->logCheck(
            monitor: $monitor,
            checkType: MonitorCheckLogService::CHECK_TYPE_CERTIFICATE,
            status: MonitorCheckLogService::STATUS_SUCCESS,
            checkedAt: Carbon::now()->utc(),
            message: 'Certificate check succeeded.',
            metadata: [
                'issuer' => $monitor->certificate_issuer,
                'certificate_expiration_date' => optional($monitor->certificate_expiration_date)?->toDateString(),
            ],
        );
    }
}
