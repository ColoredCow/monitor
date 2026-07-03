<?php

namespace Tests\Feature\Organizations;

use App\Models\Group;
use App\Models\Monitor;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class RestoreAndLinkTest extends TestCase
{
    use InteractsWithOrganizations;
    use RefreshDatabase;

    public function test_adding_a_trashed_email_restores_and_links_the_account(): void
    {
        $organization = $this->createOrganization();
        $ghost = User::factory()->create(['email' => 'ghost@x.test', 'name' => 'Original Name']);
        $ghost->delete();
        $this->actingAsAdmin($organization);

        $this->post(route('users.store'), [
            'name' => 'Ignored New Name',
            'email' => 'ghost@x.test',
            'password' => 'secret123',
            'role' => Organization::ROLE_MEMBER,
        ])->assertRedirect(route('users.index'));

        $this->assertSame(1, User::withTrashed()->where('email', 'ghost@x.test')->count());
        $restored = User::where('email', 'ghost@x.test')->firstOrFail();
        $this->assertSame('Original Name', $restored->name); // identity untouched
        $this->assertTrue($restored->hasRoleInOrganization($organization, Organization::ROLE_MEMBER));
    }

    public function test_onboarding_with_a_trashed_admin_email_restores_and_links(): void
    {
        $ghost = User::factory()->create(['email' => 'admin@x.test']);
        $ghost->delete();
        $this->actingAsSuperAdmin();

        $this->post(route('organizations.store'), [
            'name' => 'Fresh Org',
            'admin_name' => 'Ignored',
            'admin_email' => 'admin@x.test',
            'admin_password' => 'secret123',
        ])->assertRedirect(route('organizations.index'));

        $this->assertSame(1, User::withTrashed()->where('email', 'admin@x.test')->count());
        $organization = Organization::where('name', 'Fresh Org')->firstOrFail();
        $this->assertTrue($ghost->fresh()->isAdminOf($organization));
        $this->assertNotSoftDeleted($ghost->fresh());
    }

    public function test_deleting_a_group_with_live_monitors_is_refused(): void
    {
        $organization = $this->createOrganization();
        $group = Group::factory()->forOrganization($organization)->create();
        Monitor::factory()->forOrganization($organization)->create(['group_id' => $group->id]);
        $this->actingAsAdmin($organization);

        $this->delete(route('groups.destroy', $group))->assertSessionHasErrors('group');

        $this->assertNotSoftDeleted($group);
    }

    public function test_deleting_an_empty_group_soft_deletes_it(): void
    {
        $organization = $this->createOrganization();
        $group = Group::factory()->forOrganization($organization)->create();
        $this->actingAsAdmin($organization);

        $this->delete(route('groups.destroy', $group))->assertRedirect(route('groups.index'));

        $this->assertSoftDeleted($group);
    }
}
