<?php

namespace App\Http\Controllers;

use App\Http\Requests\UserRequest;
use App\Models\User;
use App\Support\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
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

        $user = User::firstOrNew(['email' => $validated['email']]);

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
        $organization = app(CurrentOrganization::class)->get();
        abort_unless($organization->users()->whereKey($user->id)->exists(), 404);

        return Inertia::render('Users/Edit', [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $organization->users()->whereKey($user->id)->first()->pivot->role,
            ],
        ]);
    }

    public function update(UserRequest $request, User $user): RedirectResponse
    {
        $this->authorize('manage-org-users');
        $organization = app(CurrentOrganization::class)->get();
        abort_unless($organization->users()->whereKey($user->id)->exists(), 404);
        $validated = $request->validated();

        $user->update([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'] ? bcrypt($validated['password']) : $user->password,
        ]);

        $organization->users()->updateExistingPivot($user->id, ['role' => $validated['role']]);

        return redirect()->route('users.index');
    }

    public function destroy(User $user): RedirectResponse
    {
        $this->authorize('manage-org-users');
        $organization = app(CurrentOrganization::class)->get();
        abort_unless($organization->users()->whereKey($user->id)->exists(), 404);
        $organization->users()->detach($user->id);

        return redirect()->route('users.index');
    }
}
