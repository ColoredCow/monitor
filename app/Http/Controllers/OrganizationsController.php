<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Inertia;

class OrganizationsController extends Controller
{
    public function index()
    {
        $this->authorize('manage-organizations');

        return Inertia::render('Organizations/Index', [
            'organizations' => Organization::withCount('users', 'monitors')->orderBy('name')->get(),
        ]);
    }

    public function create()
    {
        $this->authorize('manage-organizations');

        return Inertia::render('Organizations/Create');
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('manage-organizations');

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'admin_name' => 'required|string|max:255',
            'admin_email' => 'required|email',
            'admin_password' => 'required|string|min:6',
        ]);

        $organization = Organization::create([
            'name' => $validated['name'],
            'slug' => $this->uniqueSlug($validated['name']),
        ]);

        // Link an existing user if their email already exists; name and password are
        // intentionally NOT overwritten for existing accounts — only new accounts
        // get the values from the form.
        $admin = User::firstOrNew(
            ['email' => $validated['admin_email']],
            [
                'name' => $validated['admin_name'],
                'password' => Hash::make($validated['admin_password']),
            ]
        );

        if (! $admin->exists) {
            $admin->email_verified_at = now();
        }

        $admin->save();

        $organization->users()->syncWithoutDetaching([
            $admin->id => ['role' => Organization::ROLE_ADMIN],
        ]);

        return redirect()->route('organizations.index');
    }

    public function edit(Organization $organization)
    {
        $this->authorize('update', $organization);

        return Inertia::render('Organizations/Edit', [
            'organization' => $organization->only('id', 'name', 'slug'),
        ]);
    }

    public function update(Request $request, Organization $organization): RedirectResponse
    {
        $this->authorize('update', $organization);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $organization->update([
            'name' => $validated['name'],
            'slug' => $this->uniqueSlug($validated['name'], $organization->id),
        ]);

        return redirect()->route('organizations.index');
    }

    private function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $suffix = 2;

        while (Organization::where('slug', $slug)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }
}
