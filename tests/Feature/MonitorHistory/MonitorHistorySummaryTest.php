<?php

namespace Tests\Feature\MonitorHistory;

use App\Models\Monitor;
use App\Models\User;
use App\Services\MonitorCheckLogService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonitorHistorySummaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['monitor-history.enabled' => true]);
    }

    private function makeMonitor(array $attributes = []): Monitor
    {
        return Monitor::create(array_merge([
            'url' => 'https://example-'.uniqid().'.com',
            'uptime_check_enabled' => true,
            'domain_check_enabled' => false,
            'certificate_check_enabled' => false,
        ], $attributes));
    }

    private function seedUptimeLog(Monitor $monitor, string $status, string $checkedAt): void
    {
        app(MonitorCheckLogService::class)->logCheck(
            monitor: $monitor,
            checkType: MonitorCheckLogService::CHECK_TYPE_UPTIME,
            status: $status,
            checkedAt: Carbon::parse($checkedAt),
        );
    }

    public function test_filters_prop_reports_resolved_preset_and_range(): void
    {
        $user = User::factory()->create();
        $monitor = $this->makeMonitor();

        $response = $this->actingAs($user)->get(route('monitors.show', [
            'monitor' => $monitor->id,
            'preset' => 'custom',
            'from' => '2026-03-01',
            'to' => '2026-03-31',
        ]));

        $response->assertInertia(fn ($page) => $page
            ->component('Monitors/Show')
            ->where('filters.preset', 'custom')
            ->where('filters.from', '2026-03-01')
            ->where('filters.to', '2026-03-31')
            ->has('filters.timezone')
        );
    }

    public function test_summary_selected_range_differs_from_all_time(): void
    {
        $user = User::factory()->create();
        $monitor = $this->makeMonitor();

        $this->seedUptimeLog($monitor, MonitorCheckLogService::STATUS_SUCCESS, '2026-03-10 10:00:00');
        $this->seedUptimeLog($monitor, MonitorCheckLogService::STATUS_FAILED, '2025-01-01 10:00:00');

        $response = $this->actingAs($user)->get(route('monitors.show', [
            'monitor' => $monitor->id,
            'preset' => 'custom',
            'from' => '2026-03-01',
            'to' => '2026-03-31',
        ]));

        $response->assertInertia(fn ($page) => $page
            ->component('Monitors/Show')
            ->where('summary.all_time.total_checks', 2)
            ->where('summary.selected_range.total_checks', 1)
            ->where('summary.selected_range.by_type.uptime.total_checks', 1)
        );
    }

    public function test_summary_first_checked_at_is_earliest_log_in_timezone(): void
    {
        $user = User::factory()->create();
        $monitor = $this->makeMonitor();

        $this->seedUptimeLog($monitor, MonitorCheckLogService::STATUS_SUCCESS, '2025-01-01 10:00:00');
        $this->seedUptimeLog($monitor, MonitorCheckLogService::STATUS_SUCCESS, '2026-03-10 10:00:00');

        $response = $this->actingAs($user)->get(route('monitors.show', $monitor->id));

        $response->assertInertia(fn ($page) => $page
            ->component('Monitors/Show')
            ->where('summary.first_checked_at', '2025-01-01 10:00:00')
        );
    }

    public function test_summary_first_checked_at_is_null_when_no_logs(): void
    {
        $user = User::factory()->create();
        $monitor = $this->makeMonitor();

        $response = $this->actingAs($user)->get(route('monitors.show', $monitor->id));

        $response->assertInertia(fn ($page) => $page
            ->component('Monitors/Show')
            ->where('summary.first_checked_at', null)
        );
    }
}
