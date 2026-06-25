<?php

namespace Tests\Feature\MonitorHistory;

use App\Models\Monitor;
use App\Models\User;
use App\Services\MonitorCheckLogService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonitorHistoryGraphTest extends TestCase
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

    public function test_graph_check_types_exclude_certificate(): void
    {
        $user = User::factory()->create();
        $monitor = $this->makeMonitor([
            'uptime_check_enabled' => true,
            'domain_check_enabled' => true,
            'certificate_check_enabled' => true,
        ]);

        $response = $this->actingAs($user)->get(route('monitors.show', $monitor->id));

        $response->assertInertia(fn ($page) => $page
            ->component('Monitors/Show')
            ->where('graph.check_types', fn ($types) => collect($types)->pluck('type')->all() === ['uptime', 'domain']
                && collect($types)->firstWhere('type', 'uptime')['enabled'] === true
                && collect($types)->firstWhere('type', 'domain')['enabled'] === true
            )
        );
    }

    public function test_graph_year_defaults_to_current_year_when_no_param(): void
    {
        $user = User::factory()->create();
        $monitor = $this->makeMonitor();

        $response = $this->actingAs($user)->get(route('monitors.show', $monitor->id));

        $response->assertInertia(fn ($page) => $page
            ->component('Monitors/Show')
            ->where('graph.year', (int) Carbon::now('UTC')->format('Y'))
        );
    }

    public function test_graph_year_param_overrides_default(): void
    {
        $user = User::factory()->create();
        $monitor = $this->makeMonitor();

        $this->seedUptimeLog($monitor, MonitorCheckLogService::STATUS_SUCCESS, '2024-05-10 10:00:00');

        $response = $this->actingAs($user)->get(route('monitors.show', [
            'monitor' => $monitor->id,
            'year' => 2024,
        ]));

        $response->assertInertia(fn ($page) => $page
            ->component('Monitors/Show')
            ->where('graph.year', 2024)
        );
    }

    public function test_available_years_span_earliest_data_year_through_current_year(): void
    {
        $user = User::factory()->create();
        $monitor = $this->makeMonitor();

        $this->seedUptimeLog($monitor, MonitorCheckLogService::STATUS_SUCCESS, '2024-01-15 10:00:00');

        $currentYear = (int) Carbon::now('UTC')->format('Y');
        $expected = range(2024, $currentYear);

        $response = $this->actingAs($user)->get(route('monitors.show', $monitor->id));

        $response->assertInertia(fn ($page) => $page
            ->component('Monitors/Show')
            ->where('graph.available_years', $expected)
        );
    }

    public function test_available_years_falls_back_to_current_year_when_no_data(): void
    {
        $user = User::factory()->create();
        $monitor = $this->makeMonitor();

        $response = $this->actingAs($user)->get(route('monitors.show', $monitor->id));

        $response->assertInertia(fn ($page) => $page
            ->component('Monitors/Show')
            ->where('graph.available_years', [(int) Carbon::now('UTC')->format('Y')])
        );
    }

    public function test_graph_daily_metrics_are_scoped_to_the_graph_year_not_the_filter_range(): void
    {
        $user = User::factory()->create();
        $monitor = $this->makeMonitor();

        $this->seedUptimeLog($monitor, MonitorCheckLogService::STATUS_SUCCESS, '2024-02-01 10:00:00');
        $this->seedUptimeLog($monitor, MonitorCheckLogService::STATUS_SUCCESS, '2024-11-20 10:00:00');

        $this->artisan('monitor:aggregate-check-metrics', [
            '--from' => '2024-02-01',
            '--to' => '2024-02-01',
        ])->assertSuccessful();
        $this->artisan('monitor:aggregate-check-metrics', [
            '--from' => '2024-11-20',
            '--to' => '2024-11-20',
        ])->assertSuccessful();

        // Filter range (preset/from/to) is narrow and in a different year — graph must ignore it.
        $response = $this->actingAs($user)->get(route('monitors.show', [
            'monitor' => $monitor->id,
            'year' => 2024,
            'preset' => 'custom',
            'from' => '2026-03-01',
            'to' => '2026-03-31',
        ]));

        $response->assertInertia(fn ($page) => $page
            ->component('Monitors/Show')
            ->has('graph.series.uptime.daily_metrics', 2)
            ->where('graph.series.uptime.daily_metrics.0.date', '2024-02-01')
            ->where('graph.series.uptime.daily_metrics.1.date', '2024-11-20')
        );
    }

    public function test_graph_per_type_summary_counts_only_that_years_logs(): void
    {
        $user = User::factory()->create();
        $monitor = $this->makeMonitor();

        $this->seedUptimeLog($monitor, MonitorCheckLogService::STATUS_SUCCESS, '2024-04-10 10:00:00');
        $this->seedUptimeLog($monitor, MonitorCheckLogService::STATUS_FAILED, '2024-04-11 10:00:00');
        $this->seedUptimeLog($monitor, MonitorCheckLogService::STATUS_SUCCESS, '2025-04-10 10:00:00');

        $response = $this->actingAs($user)->get(route('monitors.show', [
            'monitor' => $monitor->id,
            'year' => 2024,
        ]));

        $response->assertInertia(fn ($page) => $page
            ->component('Monitors/Show')
            ->where('graph.series.uptime.summary.total_checks', 2)
            ->where('graph.series.uptime.summary.status_totals.success', 1)
            ->where('graph.series.uptime.summary.status_totals.failed', 1)
            ->where('graph.series.uptime.summary.success_ratio', 50)
        );
    }

    public function test_today_checks_contain_only_todays_rows_newest_first(): void
    {
        $user = User::factory()->create();
        $monitor = $this->makeMonitor();

        $todayMorning = Carbon::now('UTC')->startOfDay()->addHours(8);
        $todayNoon = Carbon::now('UTC')->startOfDay()->addHours(12);
        $yesterday = Carbon::now('UTC')->subDay()->setTime(10, 0);

        $this->seedUptimeLog($monitor, MonitorCheckLogService::STATUS_SUCCESS, $todayMorning->toDateTimeString());
        $this->seedUptimeLog($monitor, MonitorCheckLogService::STATUS_FAILED, $todayNoon->toDateTimeString());
        $this->seedUptimeLog($monitor, MonitorCheckLogService::STATUS_SUCCESS, $yesterday->toDateTimeString());

        $currentYear = (int) Carbon::now('UTC')->format('Y');

        $response = $this->actingAs($user)->get(route('monitors.show', [
            'monitor' => $monitor->id,
            'year' => $currentYear,
        ]));

        $response->assertInertia(fn ($page) => $page
            ->component('Monitors/Show')
            ->has('graph.series.uptime.today_checks', 2)
            ->where('graph.series.uptime.today_checks.0.status', MonitorCheckLogService::STATUS_FAILED)
            ->where('graph.series.uptime.today_checks.1.status', MonitorCheckLogService::STATUS_SUCCESS)
        );
    }
}
