<?php

namespace Tests\Concerns;

use App\Models\Organization;
use App\Models\User;

trait InteractsWithOrganizations
{
    protected function createOrganization(array $attributes = []): Organization
    {
        return Organization::factory()->create($attributes);
    }

    protected function actingAsAdmin(Organization $organization): User
    {
        return $this->actingAsMemberWithRole($organization, Organization::ROLE_ADMIN);
    }

    protected function actingAsMember(Organization $organization): User
    {
        return $this->actingAsMemberWithRole($organization, Organization::ROLE_MEMBER);
    }

    protected function actingAsSuperAdmin(): User
    {
        $user = User::factory()->superAdmin()->create();
        $this->actingAs($user);

        return $user;
    }

    private function actingAsMemberWithRole(Organization $organization, string $role): User
    {
        $user = User::factory()->create();
        $organization->users()->attach($user->id, ['role' => $role]);
        $this->actingAs($user);
        session(['active_organization_id' => $organization->id]);

        return $user;
    }
}
