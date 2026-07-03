<?php

namespace Tests\Feature\MonitorHistory;

use App\Models\Monitor;
use App\Models\MonitorCheckLog;
use App\Models\Organization;
use App\Services\MonitorCheckLogService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonitorCheckLogServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeMonitor(): Monitor
    {
        return Monitor::create([
            'url' => 'https://example-'.uniqid().'.com',
            'uptime_check_enabled' => true,
            'domain_check_enabled' => true,
            'organization_id' => Organization::factory()->create()->id,
        ]);
    }

    public function test_it_persists_a_check_log_for_a_monitor(): void
    {
        $monitor = $this->makeMonitor();

        app(MonitorCheckLogService::class)->logCheck(
            monitor: $monitor,
            checkType: MonitorCheckLogService::CHECK_TYPE_UPTIME,
            status: MonitorCheckLogService::STATUS_SUCCESS,
            checkedAt: Carbon::parse('2026-02-14 10:00:00'),
            message: 'Uptime check succeeded.',
        );

        $this->assertDatabaseCount('monitor_check_logs', 1);
        $log = MonitorCheckLog::first();
        $this->assertSame($monitor->id, $log->monitor_id);
        $this->assertSame('uptime', $log->check_type);
        $this->assertSame('success', $log->status);
    }

    public function test_same_check_in_the_same_second_is_logged_once_even_if_metadata_differs(): void
    {
        $monitor = $this->makeMonitor();
        $service = app(MonitorCheckLogService::class);
        $checkedAt = Carbon::parse('2026-02-14 10:00:00');

        $service->logCheck(
            monitor: $monitor,
            checkType: MonitorCheckLogService::CHECK_TYPE_DOMAIN,
            status: MonitorCheckLogService::STATUS_SUCCESS,
            checkedAt: $checkedAt,
            message: 'Domain expires in 40 day(s).',
            metadata: ['days_until_expiration' => 40],
        );

        // Same monitor/type/status/second, but recomputed message + metadata (a quick retry).
        $service->logCheck(
            monitor: $monitor,
            checkType: MonitorCheckLogService::CHECK_TYPE_DOMAIN,
            status: MonitorCheckLogService::STATUS_SUCCESS,
            checkedAt: $checkedAt,
            message: 'Domain expires in 39 day(s).',
            metadata: ['days_until_expiration' => 39],
        );

        $this->assertDatabaseCount('monitor_check_logs', 1);
    }

    public function test_distinct_checks_are_logged_separately(): void
    {
        $monitor = $this->makeMonitor();
        $service = app(MonitorCheckLogService::class);

        // Different second.
        $service->logCheck(
            monitor: $monitor,
            checkType: MonitorCheckLogService::CHECK_TYPE_UPTIME,
            status: MonitorCheckLogService::STATUS_SUCCESS,
            checkedAt: Carbon::parse('2026-02-14 10:00:00'),
        );
        $service->logCheck(
            monitor: $monitor,
            checkType: MonitorCheckLogService::CHECK_TYPE_UPTIME,
            status: MonitorCheckLogService::STATUS_SUCCESS,
            checkedAt: Carbon::parse('2026-02-14 10:01:00'),
        );
        // Same second, different status.
        $service->logCheck(
            monitor: $monitor,
            checkType: MonitorCheckLogService::CHECK_TYPE_UPTIME,
            status: MonitorCheckLogService::STATUS_FAILED,
            checkedAt: Carbon::parse('2026-02-14 10:01:00'),
        );

        $this->assertDatabaseCount('monitor_check_logs', 3);
    }
}
