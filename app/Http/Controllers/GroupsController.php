<?php

namespace App\Http\Controllers;

use App\Http\Requests\GroupRequest;
use App\Models\Group;
use App\Support\CurrentOrganization;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class GroupsController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('auth');
    }

    /**
     * Show the groups dashboard.
     *
     * @return Renderable
     */
    public function index()
    {
        return Inertia::render('Groups/Index', [
            'groups' => Group::forOrganization(app(CurrentOrganization::class)->id())
                ->with(['monitors' => fn ($q) => $q->forOrganization(app(CurrentOrganization::class)->id())])
                ->orderBy('name')->get(),
        ]);
    }

    /**
     * Show the create group page.
     *
     * @return Renderable
     */
    public function create()
    {
        $this->authorize('create', Group::class);

        return Inertia::render('Groups/Create', []);
    }

    /**
     * Create a new group.
     *
     * @return Renderable
     */
    public function store(GroupRequest $request)
    {
        $this->authorize('create', Group::class);
        $validated = $request->validated();
        Group::create([
            'name' => $validated['name'],
        ]);

        return redirect()->route('groups.index');
    }

    /**
     * Show the group details.
     *
     * @return Renderable
     */
    public function show(Group $group)
    {
        $this->authorize('view', $group);

        return Inertia::render('Groups/Show', [
            'group' => $group,
        ]);
    }

    /**
     * Edit the group details.
     *
     * @return Renderable
     */
    public function edit(Group $group)
    {
        $this->authorize('update', $group);

        return Inertia::render('Groups/Edit', [
            'group' => $group,
        ]);
    }

    /**
     * Update the group details.
     *
     * @return Renderable
     */
    public function update(GroupRequest $request, Group $group)
    {
        $this->authorize('update', $group);
        $validated = $request->validated();
        $currentDomainCheck = $group->domain_check_enabled;

        $group->update([
            'name' => $validated['name'],
        ]);

        return redirect()->route('groups.index');
    }

    /**
     * Delete the group.
     *
     * @return Renderable
     */
    public function destroy(Group $group)
    {
        $this->authorize('delete', $group);

        // The FK no longer blocks this (soft delete is an UPDATE), so enforce
        // explicitly: a group with live monitors must not disappear from under them.
        if ($group->monitors()->exists()) {
            throw ValidationException::withMessages([
                'group' => 'This group still has monitors. Move or delete them before deleting the group.',
            ]);
        }

        $group->delete();

        return redirect()->route('groups.index');
    }
}
