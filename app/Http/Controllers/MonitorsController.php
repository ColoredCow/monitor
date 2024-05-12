<?php

namespace App\Http\Controllers;

use App\Http\Requests\MonitorRequest;
use App\Models\Monitor;
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
        return Inertia::render('Monitors/Index', [
            'monitors' => Monitor::all(),
        ]);
    }

    /**
     * Show the create monitor page.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function create()
    {
        return Inertia::render('Monitors/Create', []);
    }

    /**
     * Create a new monitor.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function store(MonitorRequest $request)
    {
        $validated = $request->validated();
        Monitor::create([
            'url' => $validated['url'],
            'uptime_check_enabled' => $validated['monitorUptime'],
            'uptime_check_interval_in_minutes' => $validated['uptimeCheckInterval'],
        ]);
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
        return Inertia::render('Monitors/Edit', [
            'monitor' => $monitor,
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
        $monitor->update([
            'url' => $validated['url'],
            'uptime_check_enabled' => $validated['monitorUptime'],
            'uptime_check_interval_in_minutes' => $validated['uptimeCheckInterval'],
        ]);
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
