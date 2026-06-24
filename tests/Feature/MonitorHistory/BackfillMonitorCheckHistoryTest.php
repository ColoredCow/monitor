<?php

namespace Tests\Feature\MonitorHistory;

use App\Models\Monitor;
use App\Models\MonitorCheckLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackfillMonitorCheckHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_writes_one_current_state_snapshot_per_enabled_check_type(): void
    {
        // Backfill approximates history from current monitor state: it writes a single
        // snapshot per enabled check type, not a fabricated multi-day series.
        $monitor = Monitor::create([
            'url' => 'https://example-'.uniqid().'.com',
            'uptime_check_enabled' => true,
            'domain_check_enabled' => true,
            'certificate_check_enabled' => true,
        ]);

        $this->artisan('monitor:backfill-check-history', ['--monitor-id' => $monitor->id])
            ->assertSuccessful();

        $this->assertSame(3, MonitorCheckLog::where('monitor_id', $monitor->id)->count());

        $checkTypes = MonitorCheckLog::where('monitor_id', $monitor->id)
            ->pluck('check_type')
            ->sort()
            ->values()
            ->all();
        $this->assertSame(['certificate', 'domain', 'uptime'], $checkTypes);

        $this->assertSame('backfill', MonitorCheckLog::where('monitor_id', $monitor->id)
            ->first()->metadata['source']);
    }

    public function test_it_skips_check_types_that_are_disabled(): void
    {
        $monitor = Monitor::create([
            'url' => 'https://example-'.uniqid().'.com',
            'uptime_check_enabled' => true,
            'domain_check_enabled' => false,
            'certificate_check_enabled' => false,
        ]);

        $this->artisan('monitor:backfill-check-history', ['--monitor-id' => $monitor->id])
            ->assertSuccessful();

        $this->assertSame(1, MonitorCheckLog::where('monitor_id', $monitor->id)->count());
        $this->assertSame('uptime', MonitorCheckLog::where('monitor_id', $monitor->id)->first()->check_type);
    }

    public function test_dry_run_writes_nothing(): void
    {
        $monitor = Monitor::create([
            'url' => 'https://example-'.uniqid().'.com',
            'uptime_check_enabled' => true,
        ]);

        $this->artisan('monitor:backfill-check-history', [
            '--monitor-id' => $monitor->id,
            '--dry-run' => true,
        ])->assertSuccessful();

        $this->assertSame(0, MonitorCheckLog::where('monitor_id', $monitor->id)->count());
    }
}
