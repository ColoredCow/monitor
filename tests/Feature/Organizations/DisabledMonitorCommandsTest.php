<?php

namespace Tests\Feature\Organizations;

use App\Models\Monitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class DisabledMonitorCommandsTest extends TestCase
{
    use InteractsWithOrganizations;
    use RefreshDatabase;

    public function test_monitor_create_is_disabled(): void
    {
        $this->artisan('monitor:create', ['url' => 'https://nope.test'])
            ->assertFailed();

        $this->assertDatabaseMissing('monitors', ['url' => 'https://nope.test']);
    }

    public function test_monitor_sync_file_is_disabled_and_deletes_nothing(): void
    {
        $monitor = Monitor::factory()->forOrganization($this->createOrganization())->create();

        $this->artisan('monitor:sync-file', ['file' => 'monitors.json', '--delete-missing' => true])
            ->assertFailed();

        // The destructive vendor behaviour never runs — the monitor survives.
        $this->assertDatabaseHas('monitors', ['id' => $monitor->id, 'deleted_at' => null]);
    }
}
