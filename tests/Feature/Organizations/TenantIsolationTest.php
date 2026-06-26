<?php

namespace Tests\Feature\Organizations;

use App\Models\Group;
use App\Models\Monitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use InteractsWithOrganizations;
    use RefreshDatabase;

    public function test_index_only_shows_active_org_monitors(): void
    {
        $orgA = $this->createOrganization();
        $orgB = $this->createOrganization();
        $mine = Monitor::factory()->forOrganization($orgA)->create(['name' => 'Mine']);
        Monitor::factory()->forOrganization($orgB)->create(['name' => 'Theirs']);

        $this->actingAsMember($orgA);

        $this->get('/monitors')
            ->assertInertia(fn ($page) => $page
                ->component('Monitors/Index')
                ->where('groups.0.monitors.0.name', 'Mine'));
    }

    public function test_cannot_open_another_orgs_monitor(): void
    {
        $orgA = $this->createOrganization();
        $orgB = $this->createOrganization();
        $theirs = Monitor::factory()->forOrganization($orgB)->create();

        $this->actingAsMember($orgA);

        $this->get("/monitors/{$theirs->id}")->assertNotFound();
    }

    public function test_cannot_open_another_orgs_group(): void
    {
        $orgA = $this->createOrganization();
        $orgB = $this->createOrganization();
        $theirs = Group::factory()->forOrganization($orgB)->create();

        $this->actingAsMember($orgA);

        $this->get("/groups/{$theirs->id}")->assertNotFound();
    }
}
