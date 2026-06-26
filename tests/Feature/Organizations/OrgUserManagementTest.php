<?php

namespace Tests\Feature\Organizations;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class OrgUserManagementTest extends TestCase
{
    use InteractsWithOrganizations;
    use RefreshDatabase;

    public function test_admin_adds_new_member(): void
    {
        $organization = $this->createOrganization();
        $this->actingAsAdmin($organization);

        $this->post(route('users.store'), [
            'name' => 'New Person',
            'email' => 'new@org.test',
            'password' => 'secret123',
            'role' => Organization::ROLE_MEMBER,
        ])->assertRedirect(route('users.index'));

        $user = User::where('email', 'new@org.test')->firstOrFail();
        $this->assertTrue($user->hasRoleInOrganization($organization, Organization::ROLE_MEMBER));
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_adding_existing_email_links_account(): void
    {
        $organization = $this->createOrganization();
        $existing = User::factory()->create(['email' => 'exists@org.test']);
        $this->actingAsAdmin($organization);

        $this->post(route('users.store'), [
            'name' => 'Ignored',
            'email' => 'exists@org.test',
            'password' => 'secret123',
            'role' => Organization::ROLE_ADMIN,
        ])->assertRedirect();

        $this->assertSame(1, User::where('email', 'exists@org.test')->count());
        $this->assertTrue($existing->fresh()->isAdminOf($organization));
    }

    public function test_remove_detaches_membership_keeps_account(): void
    {
        $organization = $this->createOrganization();
        $member = User::factory()->create();
        $organization->users()->attach($member->id, ['role' => Organization::ROLE_MEMBER]);
        $this->actingAsAdmin($organization);

        $this->delete(route('users.destroy', $member->id))->assertRedirect(route('users.index'));

        $this->assertDatabaseHas('users', ['id' => $member->id]);
        $this->assertDatabaseMissing('organization_user', [
            'organization_id' => $organization->id,
            'user_id' => $member->id,
        ]);
    }

    public function test_member_cannot_manage_users(): void
    {
        $organization = $this->createOrganization();
        $this->actingAsMember($organization);

        $this->get(route('users.index'))->assertForbidden();
    }
}
