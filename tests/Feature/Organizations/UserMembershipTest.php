<?php

namespace Tests\Feature\Organizations;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class UserMembershipTest extends TestCase
{
    use InteractsWithOrganizations;
    use RefreshDatabase;

    public function test_role_helpers(): void
    {
        $organization = $this->createOrganization();
        $admin = User::factory()->create();
        $member = User::factory()->create();
        $organization->users()->attach($admin->id, ['role' => Organization::ROLE_ADMIN]);
        $organization->users()->attach($member->id, ['role' => Organization::ROLE_MEMBER]);

        $this->assertTrue($admin->isAdminOf($organization));
        $this->assertFalse($member->isAdminOf($organization));
        $this->assertTrue($member->hasRoleInOrganization($organization, Organization::ROLE_MEMBER));
    }

    public function test_super_admin_flag(): void
    {
        $this->assertFalse(User::factory()->create()->isSuperAdmin());
        $this->assertTrue(User::factory()->superAdmin()->create()->isSuperAdmin());
    }
}
