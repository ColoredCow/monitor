<?php

namespace Tests\Feature\MonitorHistory;

use App\Models\Monitor;
use App\Models\MonitorCheckLog;
use App\Models\MonitorDailyCheckMetric;
use Database\Seeders\MonitorHistorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonitorHistorySeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_monitors_with_logs_and_aggregated_daily_metrics(): void
    {
        config(['monitor-history.seed_days' => 10]);

        $this->seed(MonitorHistorySeeder::class);

        $this->assertGreaterThan(0, Monitor::count());
        $this->assertGreaterThan(0, MonitorCheckLog::count());
        $this->assertGreaterThan(0, MonitorDailyCheckMetric::count());

        $uptimeMetric = MonitorDailyCheckMetric::where('check_type', 'uptime')->first();
        $this->assertNotNull($uptimeMetric);
        $this->assertGreaterThan(0, $uptimeMetric->total_checks);

        // There should be at least one unhealthy day so the heatmap shows colour variety.
        $this->assertTrue(
            MonitorDailyCheckMetric::where('worst_status', 'failed')->exists(),
            'Expected at least one failed day in the seeded history.'
        );
    }

    public function test_re_running_the_seeder_does_not_duplicate_monitors(): void
    {
        config(['monitor-history.seed_days' => 5]);

        $this->seed(MonitorHistorySeeder::class);
        $countAfterFirst = Monitor::count();

        $this->seed(MonitorHistorySeeder::class);

        $this->assertSame($countAfterFirst, Monitor::count());
    }
}
