<?php

namespace Tests\Feature\MonitorHistory;

use App\Models\Monitor;
use App\Models\User;
use App\Services\MonitorCheckLogService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonitorHistoryShowTest extends TestCase
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

    public function test_guests_cannot_access_monitor_history(): void
    {
        $monitor = $this->makeMonitor();

        $this->get(route('monitors.show', $monitor))->assertRedirect(route('login'));
    }

    public function test_heatmap_metrics_are_returned_even_when_the_client_requests_a_non_utc_timezone(): void
    {
        $user = User::factory()->create();
        $monitor = $this->makeMonitor();

        $this->seedUptimeLog($monitor, MonitorCheckLogService::STATUS_SUCCESS, '2026-03-01 10:00:00');
        $this->seedUptimeLog($monitor, MonitorCheckLogService::STATUS_SUCCESS, '2026-03-01 10:01:00');

        // Aggregate exactly as the scheduler does: no --timezone passed.
        $this->artisan('monitor:aggregate-check-metrics', [
            '--from' => '2026-03-01',
            '--to' => '2026-03-01',
        ])->assertSuccessful();

        $response = $this->actingAs($user)->get(route('monitors.show', [
            'monitor' => $monitor->id,
            'preset' => 'all',
            'timezone' => 'Asia/Kolkata',
            'year' => 2026,
        ]));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Monitors/Show')
            ->has('graph.series.uptime.daily_metrics', 1)
        );
    }

    public function test_payload_advertises_each_check_type_with_its_enabled_flag(): void
    {
        $user = User::factory()->create();
        $monitor = $this->makeMonitor([
            'uptime_check_enabled' => true,
            'domain_check_enabled' => false,
            'certificate_check_enabled' => false,
        ]);

        $response = $this->actingAs($user)->get(route('monitors.show', $monitor->id));

        $response->assertInertia(fn ($page) => $page
            ->component('Monitors/Show')
            ->where('graph.check_types', fn ($types) => collect($types)->pluck('type')->all() === ['uptime', 'domain']
                && collect($types)->firstWhere('type', 'uptime')['enabled'] === true
                && collect($types)->firstWhere('type', 'domain')['enabled'] === false
            )
        );
    }

    public function test_recent_checks_are_limited_to_the_selected_range(): void
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
            ->where('recentChecks.pagination.total', 1)
        );
    }

    public function test_history_props_are_null_when_feature_disabled(): void
    {
        config(['monitor-history.enabled' => false]);

        $user = User::factory()->create();
        $monitor = $this->makeMonitor();

        $response = $this->actingAs($user)->get(route('monitors.show', $monitor->id));

        $response->assertInertia(fn ($page) => $page
            ->component('Monitors/Show')
            ->where('graph', null)
            ->where('filters', null)
            ->where('summary', null)
            ->where('recentChecks', null)
        );
    }
}
