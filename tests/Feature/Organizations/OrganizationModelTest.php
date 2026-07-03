<?php

namespace Tests\Feature\Organizations;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_organization_has_users_with_roles(): void
    {
        $organization = Organization::factory()->create(['name' => 'Acme']);
        $admin = User::factory()->create();
        $member = User::factory()->create();

        $organization->users()->attach($admin->id, ['role' => Organization::ROLE_ADMIN]);
        $organization->users()->attach($member->id, ['role' => Organization::ROLE_MEMBER]);

        $this->assertNotEmpty($organization->slug);
        $this->assertCount(2, $organization->users);
        $this->assertSame(
            Organization::ROLE_ADMIN,
            $organization->users()->whereKey($admin->id)->first()->pivot->role
        );
    }
}
