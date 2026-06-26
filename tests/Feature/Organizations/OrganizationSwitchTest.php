<?php

namespace Tests\Feature\Organizations;

use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class OrganizationSwitchTest extends TestCase
{
    use InteractsWithOrganizations;
    use RefreshDatabase;

    public function test_member_can_switch_to_another_membership(): void
    {
        $orgA = $this->createOrganization();
        $orgB = $this->createOrganization();
        $user = $this->actingAsMember($orgA);
        $orgB->users()->attach($user->id, ['role' => Organization::ROLE_MEMBER]);

        $this->post(route('organizations.switch'), ['organization_id' => $orgB->id])
            ->assertRedirect();

        $this->assertSame($orgB->id, session('active_organization_id'));
    }

    public function test_cannot_switch_to_non_member_org(): void
    {
        $orgA = $this->createOrganization();
        $orgB = $this->createOrganization();
        $this->actingAsMember($orgA);

        $this->post(route('organizations.switch'), ['organization_id' => $orgB->id])
            ->assertForbidden();

        $this->assertSame($orgA->id, session('active_organization_id'));
    }

    public function test_super_admin_can_switch_to_any_org(): void
    {
        $org = $this->createOrganization();
        $this->actingAsSuperAdmin();

        $this->post(route('organizations.switch'), ['organization_id' => $org->id])
            ->assertRedirect();

        $this->assertSame($org->id, session('active_organization_id'));
    }
}
