<?php

namespace Tests\Feature\Organizations;

use App\Models\Monitor;
use App\Models\Organization;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackfillMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_organization_id_is_required_on_monitors(): void
    {
        $this->expectException(QueryException::class);

        // No organization bound and none provided -> NOT NULL violation.
        Monitor::query()->create(['url' => 'https://needs-org.test', 'name' => 'NoOrg']);
    }

    public function test_monitor_with_organization_saves(): void
    {
        $monitor = Monitor::factory()->forOrganization(Organization::factory()->create())->create();

        $this->assertNotNull($monitor->organization_id);
    }
}
