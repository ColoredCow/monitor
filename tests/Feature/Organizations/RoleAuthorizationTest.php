<?php

namespace Tests\Feature\Organizations;

use App\Models\Group;
use App\Models\Monitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class RoleAuthorizationTest extends TestCase
{
    use InteractsWithOrganizations;
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Example',
            'url' => 'https://example-new.test',
            'monitorUptime' => true,
            'monitorDomain' => false,
            'uptimeCheckInterval' => 5,
            'monitorGroupId' => null,
        ], $overrides);
    }

    public function test_member_cannot_create_monitor(): void
    {
        $organization = $this->createOrganization();
        $this->actingAsMember($organization);

        $this->post('/monitors', $this->payload())->assertForbidden();
        $this->assertDatabaseCount('monitors', 0);
    }

    public function test_admin_can_create_monitor(): void
    {
        $organization = $this->createOrganization();
        $this->actingAsAdmin($organization);

        $this->post('/monitors', $this->payload())->assertRedirect(route('monitors.index'));
        $this->assertDatabaseHas('monitors', [
            'url' => 'https://example-new.test',
            'organization_id' => $organization->id,
        ]);
    }

    public function test_member_cannot_delete_monitor(): void
    {
        $organization = $this->createOrganization();
        $monitor = Monitor::factory()->forOrganization($organization)->create();
        $this->actingAsMember($organization);

        $this->delete("/monitors/{$monitor->id}")->assertForbidden();
        $this->assertDatabaseHas('monitors', ['id' => $monitor->id]);
    }

    public function test_member_cannot_create_group(): void
    {
        $organization = $this->createOrganization();
        $this->actingAsMember($organization);

        $this->post(route('groups.store'), ['name' => 'X'])->assertForbidden();
        $this->assertDatabaseCount('groups', 0);
    }

    public function test_member_cannot_delete_group(): void
    {
        $organization = $this->createOrganization();
        $group = Group::factory()->forOrganization($organization)->create();
        $this->actingAsMember($organization);

        $this->delete(route('groups.destroy', $group))->assertForbidden();
        $this->assertDatabaseHas('groups', ['id' => $group->id]);
    }
}
