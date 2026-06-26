<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name', 'email', 'password',
    ];

    protected $hidden = [
        'password', 'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
        ];
    }

    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    public function isSuperAdmin(): bool
    {
        return (bool) $this->is_super_admin;
    }

    public function hasRoleInOrganization(Organization|int $organization, string $role): bool
    {
        $organizationId = $organization instanceof Organization ? $organization->id : $organization;

        return $this->organizations()
            ->where('organizations.id', $organizationId)
            ->wherePivot('role', $role)
            ->exists();
    }

    public function isAdminOf(Organization|int $organization): bool
    {
        return $this->hasRoleInOrganization($organization, Organization::ROLE_ADMIN);
    }
}
