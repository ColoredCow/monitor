<?php

namespace Tests\Feature\Organizations;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class ActiveOrganizationTest extends TestCase
{
    use InteractsWithOrganizations;
    use RefreshDatabase;

    public function test_login_sets_first_organization_as_active(): void
    {
        $organization = $this->createOrganization(['name' => 'Acme']);
        $user = User::factory()->create();
        $organization->users()->attach($user->id, ['role' => Organization::ROLE_MEMBER]);

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        $this->assertSame($organization->id, session('active_organization_id'));
    }

    public function test_user_without_organization_is_redirected_to_no_org_page(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/monitors')->assertRedirect(route('no-organization'));
    }

    public function test_stale_active_org_falls_back_to_a_membership(): void
    {
        $organization = $this->createOrganization();
        $user = User::factory()->create();
        $organization->users()->attach($user->id, ['role' => Organization::ROLE_MEMBER]);

        $this->actingAs($user)
            ->withSession(['active_organization_id' => 999999])
            ->get('/monitors')
            ->assertOk();

        $this->assertSame($organization->id, session('active_organization_id'));
    }

    public function test_super_admin_switcher_lists_all_organizations(): void
    {
        $this->createOrganization(['name' => 'Alpha']);
        $this->createOrganization(['name' => 'Beta']);
        $this->actingAsSuperAdmin();

        // Super-admin is a member of neither org, but can switch to any —
        // so the shared switcher list must contain both.
        $this->get('/monitors')->assertInertia(fn ($page) => $page
            ->where('auth.isSuperAdmin', true)
            ->has('auth.organizations', 2)
            ->where('auth.organizations.0.name', 'Alpha')
            ->where('auth.organizations.1.name', 'Beta'));
    }

    public function test_member_switcher_lists_only_memberships(): void
    {
        $mine = $this->createOrganization(['name' => 'Mine']);
        $this->createOrganization(['name' => 'Other']);
        $this->actingAsMember($mine);

        $this->get('/monitors')->assertInertia(fn ($page) => $page
            ->has('auth.organizations', 1)
            ->where('auth.organizations.0.name', 'Mine'));
    }

    public function test_is_org_admin_prop_reflects_role_in_active_org(): void
    {
        $organization = $this->createOrganization();

        $this->actingAsMember($organization);
        $this->get('/monitors')->assertInertia(fn ($page) => $page
            ->where('auth.isOrgAdmin', false));

        $this->actingAsAdmin($organization);
        $this->get('/monitors')->assertInertia(fn ($page) => $page
            ->where('auth.isOrgAdmin', true));

        $this->actingAsSuperAdmin();
        $this->get('/monitors')->assertInertia(fn ($page) => $page
            ->where('auth.isOrgAdmin', true));
    }
}
