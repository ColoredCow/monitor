<?php

namespace App\Policies;

use App\Models\Group;
use App\Models\User;
use App\Support\CurrentOrganization;

class GroupPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Group $group): bool
    {
        return $this->belongsToActiveOrg($group);
    }

    public function create(User $user): bool
    {
        return $this->isActiveOrgAdmin($user);
    }

    public function update(User $user, Group $group): bool
    {
        return $this->isActiveOrgAdmin($user) && $this->belongsToActiveOrg($group);
    }

    public function delete(User $user, Group $group): bool
    {
        return $this->isActiveOrgAdmin($user) && $this->belongsToActiveOrg($group);
    }

    private function isActiveOrgAdmin(User $user): bool
    {
        $organization = app(CurrentOrganization::class)->get();

        return $organization !== null && $user->isAdminOf($organization);
    }

    private function belongsToActiveOrg(Group $group): bool
    {
        $organization = app(CurrentOrganization::class)->get();

        return $organization !== null && (int) $group->organization_id === $organization->id;
    }
}
