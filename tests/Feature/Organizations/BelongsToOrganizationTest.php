<?php

namespace Tests\Feature\Organizations;

use App\Models\Monitor;
use App\Models\Organization;
use App\Support\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BelongsToOrganizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_scope_filters_by_organization(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        Monitor::factory()->forOrganization($orgA)->create();
        Monitor::factory()->forOrganization($orgB)->create();

        $this->assertCount(1, Monitor::forOrganization($orgA->id)->get());
    }

    public function test_creating_hook_fills_bound_organization(): void
    {
        $organization = Organization::factory()->create();
        app(CurrentOrganization::class)->set($organization);

        $monitor = Monitor::create(['url' => 'https://hooked.test', 'name' => 'Hooked']);

        $this->assertSame($organization->id, $monitor->organization_id);
    }

    public function test_no_global_scope_leaks_into_console_context(): void
    {
        // No CurrentOrganization bound (simulates console/scheduler).
        Monitor::factory()->forOrganization(Organization::factory()->create())->create();
        Monitor::factory()->forOrganization(Organization::factory()->create())->create();

        $this->assertCount(2, Monitor::all());
    }
}
