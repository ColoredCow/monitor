<?php

namespace App\Http\Controllers;

use App\Http\Requests\MonitorRequest;
use App\Models\Monitor;
use App\Models\Group;
use App\Services\DomainService;
use Inertia\Inertia;

class MonitorsController extends Controller
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
     * Show the monitors dashboard.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function index()
    {
        $groups = Group::with(['monitors' => function ($query) {
            $query->orderBy('name');
        }])
        ->has('monitors')
        ->orderBy('name')->get();

        $monitorWithNoGroups = Monitor::whereNull('group_id')->orderBy('name')->get();

        $groups = collect($groups);
        $groups->push([
            'id' => null,
            'name' => 'Ungrouped Monitors',
            'monitors' => $monitorWithNoGroups,
        ]);

        return Inertia::render('Monitors/Index', [
            'groups' => $groups,
        ]);
    }

    /**
     * Show the create monitor page.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function create()
    {
        $groups = Group::all();
        return Inertia::render('Monitors/Create', [
            'groups' => $groups,
        ]);
    }

    /**
     * Create a new monitor.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function store(MonitorRequest $request)
    {
        $validated = $request->validated();
        $monitor = Monitor::create([
            'name' => $validated['name'],
            'url' => $validated['url'],
            'uptime_check_enabled' => $validated['monitorUptime'],
            'uptime_check_interval_in_minutes' => $validated['uptimeCheckInterval'],
            'domain_check_enabled' => $validated['monitorDomain'],
            'group_id' => $validated['monitorGroupId'],
        ]);

        if ($monitor) {
            DomainService::addDomainExpiration($monitor);
        }
        return redirect()->route('monitors.index');
    }

    /**
     * Show the monitor details.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function show(Monitor $monitor)
    {
        return Inertia::render('Monitors/Show', [
            'monitor' => $monitor,
        ]);
    }

    /**
     * Edit the monitor details.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function edit(Monitor $monitor)
    {
        $groups = Group::all();
        return Inertia::render('Monitors/Edit', [
            'monitor' => $monitor,
            'groups' => $groups,
        ]);
    }

    /**
     * Update the monitor details.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function update(MonitorRequest $request, Monitor $monitor)
    {
        $validated = $request->validated();
        $currentDomainCheck = $monitor->domain_check_enabled;

        $monitor->update([
            'name' => $validated['name'],
            'url' => $validated['url'],
            'uptime_check_enabled' => $validated['monitorUptime'],
            'uptime_check_interval_in_minutes' => $validated['uptimeCheckInterval'],
            'domain_check_enabled' => $validated['monitorDomain'],
            'group_id' => $validated['monitorGroupId'],
        ]);

        if (($validated['monitorDomain'] && !$currentDomainCheck) || ($monitor->wasChanged('url'))) {
            DomainService::addDomainExpiration($monitor);
        }

        return redirect()->route('monitors.index');
    }

    /**
     * Delete the monitor.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function destroy(Monitor $monitor)
    {
        $monitor->delete();
        return redirect()->route('monitors.index');
    }
}
