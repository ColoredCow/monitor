<?php

namespace App\Http\Controllers;

use App\Http\Requests\GroupRequest;
use App\Models\Group;
// use App\Services\DomainService;
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
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function index()
    {
        return Inertia::render('Groups/Index', [
            'groups' => Group::orderBy('name')->get(),
        ]);
    }

    /**
     * Show the create group page.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function create()
    {
        return Inertia::render('Groups/Create', []);
    }

    /**
     * Create a new group.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function store(GroupRequest $request)
    {
        $validated = $request->validated();
        $group = Group::create([
            'name' => $validated['name'],
        ]);

        return redirect()->route('groups.index');
    }

    /**
     * Show the group details.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function show(Group $group)
    {
        return Inertia::render('Groups/Show', [
            'group' => $group,
        ]);
    }

    /**
     * Edit the group details.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function edit(Group $group)
    {
        return Inertia::render('Groups/Edit', [
            'group' => $group,
        ]);
    }

    /**
     * Update the group details.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function update(GroupRequest $request, Group $group)
    {
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
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function destroy(Group $group)
    {
        $group->delete();
        return redirect()->route('groups.index');
    }
}
