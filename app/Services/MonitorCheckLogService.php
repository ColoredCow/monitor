<?php

namespace App\Services;

use App\Models\Monitor;
use App\Models\MonitorCheckLog;
use Carbon\Carbon;
use Carbon\CarbonInterface;

class MonitorCheckLogService
{
    public const CHECK_TYPE_UPTIME = 'uptime';

    public const CHECK_TYPE_DOMAIN = 'domain';

    public const CHECK_TYPE_CERTIFICATE = 'certificate';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_WARNING = 'warning';

    public const STATUS_FAILED = 'failed';

    public const STATUS_UNKNOWN = 'unknown';

    public function logCheck(
        Monitor $monitor,
        string $checkType,
        string $status,
        CarbonInterface|string|null $checkedAt = null,
        ?string $message = null,
        ?string $failureReason = null,
        ?int $responseTimeMs = null,
        array $metadata = []
    ): MonitorCheckLog {
        $checkedAt = $this->normalizeCheckedAt($checkedAt);
        $idempotencyKey = $this->buildIdempotencyKey(
            $monitor->id,
            $checkType,
            $status,
            $checkedAt
        );

        return MonitorCheckLog::firstOrCreate(
            ['idempotency_key' => $idempotencyKey],
            [
                'monitor_id' => $monitor->id,
                'idempotency_key' => $idempotencyKey,
                'check_type' => $checkType,
                'status' => $status,
                'checked_at' => $checkedAt->toDateTimeString(),
                'response_time_ms' => $responseTimeMs,
                'message' => $message,
                'failure_reason' => $failureReason,
                'metadata' => $metadata,
            ]
        );
    }

    protected function normalizeCheckedAt(CarbonInterface|string|null $checkedAt): Carbon
    {
        if ($checkedAt instanceof CarbonInterface) {
            return Carbon::instance($checkedAt)->utc()->startOfSecond();
        }

        if (is_string($checkedAt) && $checkedAt !== '') {
            return Carbon::parse($checkedAt)->utc()->startOfSecond();
        }

        return Carbon::now()->utc()->startOfSecond();
    }

    /**
     * Identity of a single check observation: a given monitor's check of a given
     * type, resolved to a status, at a given second. Message/metadata are derived
     * details of that same observation and are intentionally excluded so that a
     * quick command retry collapses onto the existing row instead of duplicating it.
     */
    protected function buildIdempotencyKey(
        int $monitorId,
        string $checkType,
        string $status,
        Carbon $checkedAt
    ): string {
        return hash('sha256', implode('|', [
            $monitorId,
            $checkType,
            $status,
            $checkedAt->toDateTimeString(),
        ]));
    }
}
