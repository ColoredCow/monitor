<?php

namespace App\Policies;

use App\Models\Monitor;
use App\Models\User;
use App\Support\CurrentOrganization;

class MonitorPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Monitor $monitor): bool
    {
        return $this->belongsToActiveOrg($monitor);
    }

    public function create(User $user): bool
    {
        return $this->isActiveOrgAdmin($user);
    }

    public function update(User $user, Monitor $monitor): bool
    {
        return $this->isActiveOrgAdmin($user) && $this->belongsToActiveOrg($monitor);
    }

    public function delete(User $user, Monitor $monitor): bool
    {
        return $this->isActiveOrgAdmin($user) && $this->belongsToActiveOrg($monitor);
    }

    private function isActiveOrgAdmin(User $user): bool
    {
        $organization = app(CurrentOrganization::class)->get();

        return $organization !== null && $user->isAdminOf($organization);
    }

    private function belongsToActiveOrg(Monitor $monitor): bool
    {
        $organization = app(CurrentOrganization::class)->get();

        return $organization !== null && (int) $monitor->organization_id === $organization->id;
    }
}
