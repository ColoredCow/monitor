<?php

namespace Tests\Feature\MonitorHistory;

use App\Models\Monitor;
use App\Models\Organization;
use App\Services\MonitorCheckLogService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class MonitorHistorySummaryTest extends TestCase
{
    use InteractsWithOrganizations;
    use RefreshDatabase;

    protected Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();
        config(['monitor-history.enabled' => true]);
        $this->organization = Organization::factory()->create();
    }

    private function makeMonitor(array $attributes = []): Monitor
    {
        return Monitor::create(array_merge([
            'url' => 'https://example-'.uniqid().'.com',
            'uptime_check_enabled' => true,
            'domain_check_enabled' => false,
            'certificate_check_enabled' => false,
            'organization_id' => $this->organization->id,
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
        $this->actingAsMember($this->organization);
        $monitor = $this->makeMonitor();

        $response = $this->get(route('monitors.show', [
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
        $this->actingAsMember($this->organization);
        $monitor = $this->makeMonitor();

        $this->seedUptimeLog($monitor, MonitorCheckLogService::STATUS_SUCCESS, '2026-03-10 10:00:00');
        $this->seedUptimeLog($monitor, MonitorCheckLogService::STATUS_FAILED, '2025-01-01 10:00:00');

        $response = $this->get(route('monitors.show', [
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
        $this->actingAsMember($this->organization);
        $monitor = $this->makeMonitor();

        $this->seedUptimeLog($monitor, MonitorCheckLogService::STATUS_SUCCESS, '2025-01-01 10:00:00');
        $this->seedUptimeLog($monitor, MonitorCheckLogService::STATUS_SUCCESS, '2026-03-10 10:00:00');

        $response = $this->get(route('monitors.show', $monitor->id));

        $response->assertInertia(fn ($page) => $page
            ->component('Monitors/Show')
            ->where('summary.first_checked_at', '2025-01-01 10:00:00')
        );
    }

    public function test_summary_first_checked_at_is_null_when_no_logs(): void
    {
        $this->actingAsMember($this->organization);
        $monitor = $this->makeMonitor();

        $response = $this->get(route('monitors.show', $monitor->id));

        $response->assertInertia(fn ($page) => $page
            ->component('Monitors/Show')
            ->where('summary.first_checked_at', null)
        );
    }

    public function test_never_monitored_monitor_returns_a_zeroed_empty_state_payload(): void
    {
        $this->actingAsMember($this->organization);
        $monitor = $this->makeMonitor();

        $response = $this->get(route('monitors.show', $monitor->id));

        // Pins the empty-state contract the SummaryStats / RecentChecks UI relies on
        // to distinguish "never monitored" from "no checks in range": the props are
        // present and zeroed, not null and not absent.
        $response->assertInertia(fn ($page) => $page
            ->component('Monitors/Show')
            ->where('summary.first_checked_at', null)
            ->where('summary.all_time.total_checks', 0)
            ->where('summary.selected_range.total_checks', 0)
            ->where('graph.series.uptime.summary.total_checks', 0)
            ->has('graph.series.uptime.daily_metrics', 0)
            ->where('recentChecks.pagination.total', 0)
            ->has('recentChecks.data', 0)
        );
    }
}
