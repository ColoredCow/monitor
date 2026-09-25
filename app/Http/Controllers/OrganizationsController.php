<?php

namespace App\Http\Controllers;

use App\Exceptions\OrganizationRestoreBlockedException;
use App\Models\Organization;
use App\Models\User;
use App\Services\CreditLedgerService;
use App\Services\OrganizationDeletionService;
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

        $purgeAfterDays = (int) config('organizations.purge_after_days', 60);

        return Inertia::render('Organizations/Index', [
            'organizations' => Organization::withCount('users', 'monitors')->orderBy('name')->get(),
            'deletedOrganizations' => Organization::onlyTrashed()->orderBy('deleted_at')->get()
                ->map(fn (Organization $organization) => [
                    'id' => $organization->id,
                    'name' => $organization->name,
                    'deleted_at' => $organization->deleted_at->toDateString(),
                    'days_until_purge' => max(0, $purgeAfterDays - (int) $organization->deleted_at->diffInDays(now())),
                ])->values(),
            'purgeAfterDays' => $purgeAfterDays,
            'status' => session('status'),
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

        // Link an existing user if their email already exists; a soft-deleted
        // account is restored and linked rather than duplicated. Name and
        // password are intentionally NOT overwritten for existing accounts.
        $admin = User::withTrashed()->firstOrNew(
            ['email' => $validated['admin_email']],
            [
                'name' => $validated['admin_name'],
                'password' => Hash::make($validated['admin_password']),
            ]
        );

        if ($admin->trashed()) {
            $admin->restore();
        }

        if (! $admin->exists) {
            $admin->email_verified_at = now();
        }

        $admin->save();

        $organization->users()->syncWithoutDetaching([
            $admin->id => ['role' => Organization::ROLE_ADMIN],
        ]);

        $defaultGrant = (int) config('credits.default_grant');

        if ($defaultGrant > 0) {
            app(CreditLedgerService::class)->grant($organization, $defaultGrant, $request->user(), 'Initial grant');
        }

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

    public function destroy(Organization $organization): RedirectResponse
    {
        $this->authorize('manage-organizations');

        app(OrganizationDeletionService::class)->delete($organization);

        return redirect()->route('organizations.index');
    }

    public function restore(Organization $organization): RedirectResponse
    {
        $this->authorize('manage-organizations');

        try {
            $result = app(OrganizationDeletionService::class)->restore($organization);
        } catch (OrganizationRestoreBlockedException $exception) {
            return redirect()->route('organizations.index')
                ->withErrors(['restore' => $exception->getMessage()]);
        }

        $status = "Restored '{$organization->name}'.";
        if ($result['skipped_monitors'] !== []) {
            $status .= ' Skipped monitors whose URLs are now in use: '.implode(', ', $result['skipped_monitors']).'.';
        }

        return redirect()->route('organizations.index')->with('status', $status);
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
