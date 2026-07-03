<?php

namespace App\Support;

use App\Models\Organization;
use App\Models\User;

class CurrentOrganization
{
    private ?Organization $organization = null;

    public function set(?Organization $organization): void
    {
        $this->organization = $organization;
    }

    public function get(): ?Organization
    {
        return $this->organization;
    }

    public function id(): ?int
    {
        return $this->organization?->id;
    }

    /**
     * Pure resolution: validate the session org against the user's
     * memberships (super-admins may use any org), else fall back to the
     * user's first org by name. Performs NO session writes — callers
     * decide whether to persist. Shared by the middleware and route binding
     * so resolution is identical regardless of middleware ordering.
     */
    public function resolveFor(User $user, ?int $sessionOrgId): ?Organization
    {
        if ($sessionOrgId) {
            $candidate = Organization::find($sessionOrgId);
            if ($candidate && ($user->isSuperAdmin() || $user->organizations()->whereKey($candidate->id)->exists())) {
                return $candidate;
            }
        }

        return $user->isSuperAdmin()
            ? Organization::orderBy('name')->first()
            : $user->organizations()->orderBy('name')->first();
    }
}
