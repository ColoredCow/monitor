<?php

namespace App\Http\Controllers;

use App\Http\Requests\UserRequest;
use App\Models\Organization;
use App\Models\User;
use App\Support\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class UsersController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function index()
    {
        $this->authorize('manage-org-users');
        $organization = app(CurrentOrganization::class)->get();

        $users = $organization->users()->orderBy('name')->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->pivot->role,
            ]);

        return Inertia::render('Users/Index', [
            'users' => $users,
        ]);
    }

    public function create()
    {
        $this->authorize('manage-org-users');

        return Inertia::render('Users/Create');
    }

    public function store(UserRequest $request): RedirectResponse
    {
        $this->authorize('manage-org-users');
        $organization = app(CurrentOrganization::class)->get();
        $validated = $request->validated();

        // Link an existing account by email if one exists; a soft-deleted
        // account (e.g. removed with a deleted organization) is restored and
        // linked rather than duplicated. Name and password are only set for
        // brand-new accounts — an existing account's credentials are never
        // touched here.
        $user = User::withTrashed()->firstOrNew(['email' => $validated['email']]);

        // Super-admins are platform-level and are never managed through org
        // user management — refuse to pull one into an organization.
        if ($user->exists && $user->isSuperAdmin()) {
            throw ValidationException::withMessages([
                'email' => 'This account cannot be added to an organization.',
            ]);
        }

        // A live account that already belongs to this org must have its role
        // changed via edit, not silently overwritten by re-adding it.
        if ($user->exists && ! $user->trashed()
            && $organization->users()->whereKey($user->id)->exists()) {
            throw ValidationException::withMessages([
                'email' => 'This user is already a member of this organization.',
            ]);
        }

        if ($user->trashed()) {
            $user->restore();
        }

        if (! $user->exists) {
            $user->name = $validated['name'];
            $user->password = bcrypt($validated['password']);
            $user->email_verified_at = now();
            $user->save();
        }

        $organization->users()->syncWithoutDetaching([
            $user->id => ['role' => $validated['role']],
        ]);

        return redirect()->route('users.index');
    }

    public function edit(User $user)
    {
        $this->authorize('manage-org-users');
        abort_if($user->isSuperAdmin(), 404);
        $organization = app(CurrentOrganization::class)->get();
        $member = $organization->users()->whereKey($user->id)->firstOrFail();

        return Inertia::render('Users/Edit', [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $member->pivot->role,
            ],
            // Profile/credentials may only be edited for an account owned solely
            // by this org; a shared account's identity is not this org's to rewrite.
            'canEditProfile' => $this->ownsAccount($organization, $user),
        ]);
    }

    public function update(UserRequest $request, User $user): RedirectResponse
    {
        $this->authorize('manage-org-users');
        abort_if($user->isSuperAdmin(), 404);
        $organization = app(CurrentOrganization::class)->get();
        abort_unless($organization->users()->whereKey($user->id)->exists(), 404);
        $validated = $request->validated();

        if ($validated['role'] !== Organization::ROLE_ADMIN && $this->isLastAdmin($organization, $user)) {
            throw ValidationException::withMessages([
                'role' => 'The organization must keep at least one admin.',
            ]);
        }

        // Only rewrite global name/email/password when this org is the account's
        // sole home. For an account shared with other organizations, an org
        // admin may change its role here but not its global credentials —
        // otherwise one org could reset the password of another org's user.
        if ($this->ownsAccount($organization, $user)) {
            $user->update([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $request->filled('password') ? bcrypt($validated['password']) : $user->password,
            ]);
        }

        $organization->users()->updateExistingPivot($user->id, ['role' => $validated['role']]);

        return redirect()->route('users.index');
    }

    public function destroy(User $user): RedirectResponse
    {
        $this->authorize('manage-org-users');
        abort_if($user->isSuperAdmin(), 404);
        $organization = app(CurrentOrganization::class)->get();
        abort_unless($organization->users()->whereKey($user->id)->exists(), 404);

        if ($this->isLastAdmin($organization, $user)) {
            throw ValidationException::withMessages([
                'user' => "You cannot remove the organization's last admin.",
            ]);
        }

        $organization->users()->detach($user->id);

        return redirect()->route('users.index');
    }

    /**
     * Whether the account belongs solely to this organization (its only live
     * membership), making its global profile safe for this org to edit.
     */
    private function ownsAccount(Organization $organization, User $user): bool
    {
        return $user->organizations()->count() === 1
            && $organization->users()->whereKey($user->id)->exists();
    }

    /**
     * Whether removing/demoting this user would leave the org with no admin.
     */
    private function isLastAdmin(Organization $organization, User $user): bool
    {
        $adminIds = $organization->users()
            ->wherePivot('role', Organization::ROLE_ADMIN)
            ->pluck('users.id');

        return $adminIds->count() === 1 && (int) $adminIds->first() === (int) $user->id;
    }
}
