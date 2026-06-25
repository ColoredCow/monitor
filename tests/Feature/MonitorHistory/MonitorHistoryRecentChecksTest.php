<?php

namespace Tests\Feature\MonitorHistory;

use App\Models\Monitor;
use App\Models\User;
use App\Services\MonitorCheckLogService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonitorHistoryRecentChecksTest extends TestCase
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
            'domain_check_enabled' => true,
            'certificate_check_enabled' => false,
        ], $attributes));
    }

    private function seedLog(Monitor $monitor, string $checkType, string $status, string $checkedAt, ?int $responseTimeMs = null): void
    {
        app(MonitorCheckLogService::class)->logCheck(
            monitor: $monitor,
            checkType: $checkType,
            status: $status,
            checkedAt: Carbon::parse($checkedAt),
            responseTimeMs: $responseTimeMs,
        );
    }

    public function test_recent_checks_pagination_shape_uses_page_size_25(): void
    {
        $user = User::factory()->create();
        $monitor = $this->makeMonitor();

        for ($i = 0; $i < 30; $i++) {
            $this->seedLog(
                $monitor,
                MonitorCheckLogService::CHECK_TYPE_UPTIME,
                MonitorCheckLogService::STATUS_SUCCESS,
                Carbon::parse('2026-03-10 00:00:00')->addMinutes($i)->toDateTimeString(),
                120,
            );
        }

        $response = $this->actingAs($user)->get(route('monitors.show', [
            'monitor' => $monitor->id,
            'preset' => 'custom',
            'from' => '2026-03-01',
            'to' => '2026-03-31',
        ]));

        $response->assertInertia(fn ($page) => $page
            ->component('Monitors/Show')
            ->where('recentChecks.type', 'uptime')
            ->has('recentChecks.data', 25)
            ->where('recentChecks.pagination.per_page', 25)
            ->where('recentChecks.pagination.current_page', 1)
            ->where('recentChecks.pagination.last_page', 2)
            ->where('recentChecks.pagination.total', 30)
            ->where('recentChecks.data.0.response_time_ms', 120)
        );
    }

    public function test_recent_checks_respect_recent_page(): void
    {
        $user = User::factory()->create();
        $monitor = $this->makeMonitor();

        for ($i = 0; $i < 30; $i++) {
            $this->seedLog(
                $monitor,
                MonitorCheckLogService::CHECK_TYPE_UPTIME,
                MonitorCheckLogService::STATUS_SUCCESS,
                Carbon::parse('2026-03-10 00:00:00')->addMinutes($i)->toDateTimeString(),
            );
        }

        $response = $this->actingAs($user)->get(route('monitors.show', [
            'monitor' => $monitor->id,
            'preset' => 'custom',
            'from' => '2026-03-01',
            'to' => '2026-03-31',
            'recent_page' => 2,
        ]));

        $response->assertInertia(fn ($page) => $page
            ->component('Monitors/Show')
            ->where('recentChecks.pagination.current_page', 2)
            ->has('recentChecks.data', 5)
        );
    }

    public function test_recent_checks_filter_by_recent_type(): void
    {
        $user = User::factory()->create();
        $monitor = $this->makeMonitor();

        $this->seedLog($monitor, MonitorCheckLogService::CHECK_TYPE_UPTIME, MonitorCheckLogService::STATUS_SUCCESS, '2026-03-10 10:00:00');
        $this->seedLog($monitor, MonitorCheckLogService::CHECK_TYPE_DOMAIN, MonitorCheckLogService::STATUS_WARNING, '2026-03-11 10:00:00');

        $response = $this->actingAs($user)->get(route('monitors.show', [
            'monitor' => $monitor->id,
            'preset' => 'custom',
            'from' => '2026-03-01',
            'to' => '2026-03-31',
            'recent_type' => 'domain',
        ]));

        $response->assertInertia(fn ($page) => $page
            ->component('Monitors/Show')
            ->where('recentChecks.type', 'domain')
            ->has('recentChecks.data', 1)
            ->where('recentChecks.data.0.check_type', 'domain')
            ->where('recentChecks.data.0.status', MonitorCheckLogService::STATUS_WARNING)
        );
    }

    public function test_recent_checks_default_type_is_uptime(): void
    {
        $user = User::factory()->create();
        $monitor = $this->makeMonitor();

        $this->seedLog($monitor, MonitorCheckLogService::CHECK_TYPE_UPTIME, MonitorCheckLogService::STATUS_SUCCESS, '2026-03-10 10:00:00');

        $response = $this->actingAs($user)->get(route('monitors.show', [
            'monitor' => $monitor->id,
            'preset' => 'custom',
            'from' => '2026-03-01',
            'to' => '2026-03-31',
        ]));

        $response->assertInertia(fn ($page) => $page
            ->component('Monitors/Show')
            ->where('recentChecks.type', 'uptime')
        );
    }

    public function test_recent_checks_reject_an_unsupported_type_and_fall_back_to_uptime(): void
    {
        $user = User::factory()->create();
        $monitor = $this->makeMonitor();

        $this->seedLog($monitor, MonitorCheckLogService::CHECK_TYPE_UPTIME, MonitorCheckLogService::STATUS_SUCCESS, '2026-03-10 10:00:00');
        $this->seedLog($monitor, MonitorCheckLogService::CHECK_TYPE_DOMAIN, MonitorCheckLogService::STATUS_WARNING, '2026-03-11 10:00:00');

        // 'certificate' is a real check type but NOT a selectable recent tab —
        // an out-of-whitelist value must fall back to uptime, never echo through.
        $response = $this->actingAs($user)->get(route('monitors.show', [
            'monitor' => $monitor->id,
            'preset' => 'custom',
            'from' => '2026-03-01',
            'to' => '2026-03-31',
            'recent_type' => MonitorCheckLogService::CHECK_TYPE_CERTIFICATE,
        ]));

        $response->assertInertia(fn ($page) => $page
            ->component('Monitors/Show')
            ->where('recentChecks.type', 'uptime')
            ->has('recentChecks.data', 1)
            ->where('recentChecks.data.0.check_type', 'uptime')
        );
    }

    public function test_recent_checks_respect_the_selected_range(): void
    {
        $user = User::factory()->create();
        $monitor = $this->makeMonitor();

        $this->seedLog($monitor, MonitorCheckLogService::CHECK_TYPE_UPTIME, MonitorCheckLogService::STATUS_SUCCESS, '2026-03-10 10:00:00');
        $this->seedLog($monitor, MonitorCheckLogService::CHECK_TYPE_UPTIME, MonitorCheckLogService::STATUS_FAILED, '2025-01-01 10:00:00');

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
}
