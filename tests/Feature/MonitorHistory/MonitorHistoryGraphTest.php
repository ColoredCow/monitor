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
}
