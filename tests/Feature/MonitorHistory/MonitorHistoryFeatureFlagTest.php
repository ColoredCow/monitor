<?php

namespace Tests\Feature\MonitorHistory;

use App\Models\Monitor;
use App\Services\MonitorCheckLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonitorHistoryFeatureFlagTest extends TestCase
{
    use RefreshDatabase;

    private function makeMonitor(): Monitor
    {
        return Monitor::create([
            'url' => 'https://example-'.uniqid().'.com',
            'uptime_check_enabled' => true,
            'domain_check_enabled' => true,
            'certificate_check_enabled' => true,
        ]);
    }

    public function test_automatic_ingestion_writes_nothing_when_the_feature_flag_is_off(): void
    {
        config(['monitor-history.enabled' => false]);
        $monitor = $this->makeMonitor();

        $result = app(MonitorCheckLogService::class)->logCheckIfEnabled(
            monitor: $monitor,
            checkType: MonitorCheckLogService::CHECK_TYPE_UPTIME,
            status: MonitorCheckLogService::STATUS_SUCCESS,
        );

        $this->assertNull($result);
        $this->assertDatabaseCount('monitor_check_logs', 0);
    }

    public function test_automatic_ingestion_writes_when_the_feature_flag_is_on(): void
    {
        config(['monitor-history.enabled' => true]);
        $monitor = $this->makeMonitor();

        $result = app(MonitorCheckLogService::class)->logCheckIfEnabled(
            monitor: $monitor,
            checkType: MonitorCheckLogService::CHECK_TYPE_UPTIME,
            status: MonitorCheckLogService::STATUS_SUCCESS,
        );

        $this->assertNotNull($result);
        $this->assertDatabaseCount('monitor_check_logs', 1);
    }

    public function test_explicit_log_check_still_writes_when_the_flag_is_off(): void
    {
        // Operator-driven paths (backfill, seeder) intentionally bypass the flag so
        // history can be pre-staged before the read UI is switched on.
        config(['monitor-history.enabled' => false]);
        $monitor = $this->makeMonitor();

        app(MonitorCheckLogService::class)->logCheck(
            monitor: $monitor,
            checkType: MonitorCheckLogService::CHECK_TYPE_UPTIME,
            status: MonitorCheckLogService::STATUS_SUCCESS,
        );

        $this->assertDatabaseCount('monitor_check_logs', 1);
    }

    public function test_uptime_hook_does_not_log_when_the_flag_is_off(): void
    {
        config(['monitor-history.enabled' => false]);
        $monitor = $this->makeMonitor();

        $monitor->uptimeRequestFailed('Connection timed out.');

        $this->assertDatabaseCount('monitor_check_logs', 0);
    }

    public function test_uptime_hook_logs_when_the_flag_is_on(): void
    {
        config(['monitor-history.enabled' => true]);
        $monitor = $this->makeMonitor();

        $monitor->uptimeRequestFailed('Connection timed out.');

        $this->assertDatabaseCount('monitor_check_logs', 1);
    }
}
