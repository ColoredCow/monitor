<?php

namespace Tests\Feature\Organizations;

use App\Models\Monitor;
use App\Services\MonitorCheckLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class DeleteMonitorCommandTest extends TestCase
{
    use InteractsWithOrganizations;
    use RefreshDatabase;

    public function test_monitor_delete_command_soft_deletes_and_keeps_history(): void
    {
        $monitor = Monitor::factory()->forOrganization($this->createOrganization())
            ->create(['url' => 'https://cli-delete.test']);
        app(MonitorCheckLogService::class)->logCheck(
            monitor: $monitor,
            checkType: MonitorCheckLogService::CHECK_TYPE_UPTIME,
            status: MonitorCheckLogService::STATUS_SUCCESS,
        );

        $this->artisan('monitor:delete', ['url' => 'https://cli-delete.test'])
            ->expectsConfirmation('Are you sure you want stop monitoring https://cli-delete.test?', 'yes')
            ->assertSuccessful();

        $this->assertSoftDeleted($monitor);
        $this->assertDatabaseCount('monitor_check_logs', 1); // history preserved
    }

    public function test_monitor_delete_command_reports_unknown_url(): void
    {
        $this->artisan('monitor:delete', ['url' => 'https://nope.test'])
            ->expectsOutputToContain('is not configured')
            ->assertFailed();
    }
}
