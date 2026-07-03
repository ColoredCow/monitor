<?php

namespace Tests\Feature\MonitorHistory;

use App\Models\Monitor;
use App\Models\MonitorDailyCheckMetric;
use App\Models\Organization;
use App\Services\MonitorCheckLogService;
use App\Services\MonitorDailyCheckMetricsAggregator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonitorDailyCheckMetricsAggregatorTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->organization = Organization::factory()->create();
    }

    public function test_it_computes_daily_totals_ratio_and_worst_status(): void
    {
        $monitor = Monitor::create([
            'url' => 'https://example-'.uniqid().'.com',
            'uptime_check_enabled' => true,
            'organization_id' => $this->organization->id,
        ]);

        $service = app(MonitorCheckLogService::class);
        $statuses = [
            ['success', '2026-03-01 10:00:00'],
            ['success', '2026-03-01 10:01:00'],
            ['success', '2026-03-01 10:02:00'],
            ['failed', '2026-03-01 10:03:00'],
        ];
        foreach ($statuses as [$status, $time]) {
            $service->logCheck(
                monitor: $monitor,
                checkType: MonitorCheckLogService::CHECK_TYPE_UPTIME,
                status: $status,
                checkedAt: Carbon::parse($time),
            );
        }

        $rows = app(MonitorDailyCheckMetricsAggregator::class)->aggregate(
            Carbon::parse('2026-03-01'),
            Carbon::parse('2026-03-01'),
            'UTC'
        );

        $this->assertSame(1, $rows);

        $metric = MonitorDailyCheckMetric::where('monitor_id', $monitor->id)
            ->where('check_type', 'uptime')
            ->where('date', '2026-03-01')
            ->firstOrFail();

        $this->assertSame(4, $metric->total_checks);
        $this->assertSame(3, $metric->successful_checks);
        $this->assertSame(1, $metric->failed_checks);
        $this->assertSame(75.0, (float) $metric->success_ratio);
        $this->assertSame('failed', $metric->worst_status);
    }

    public function test_re_running_aggregation_is_idempotent(): void
    {
        $monitor = Monitor::create([
            'url' => 'https://example-'.uniqid().'.com',
            'uptime_check_enabled' => true,
            'organization_id' => $this->organization->id,
        ]);

        app(MonitorCheckLogService::class)->logCheck(
            monitor: $monitor,
            checkType: MonitorCheckLogService::CHECK_TYPE_UPTIME,
            status: MonitorCheckLogService::STATUS_SUCCESS,
            checkedAt: Carbon::parse('2026-03-01 10:00:00'),
        );

        $aggregator = app(MonitorDailyCheckMetricsAggregator::class);
        $aggregator->aggregate(Carbon::parse('2026-03-01'), Carbon::parse('2026-03-01'), 'UTC');
        $aggregator->aggregate(Carbon::parse('2026-03-01'), Carbon::parse('2026-03-01'), 'UTC');

        $this->assertDatabaseCount('monitor_daily_check_metrics', 1);
    }
}
