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
}
