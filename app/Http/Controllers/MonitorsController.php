<?php

namespace App\Http\Controllers;

use App\Services\DomainService;
use Illuminate\Support\Facades\Log;
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
            'monitors' => Monitor::orderBy('name')->get(),
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
        $monitor = Monitor::create([
            'name' => $validated['name'],
            'url' => $validated['url'],
            'uptime_check_enabled' => $validated['monitorUptime'],
            'uptime_check_interval_in_minutes' => $validated['uptimeCheckInterval'],
            'domain_check_enabled' => $validated['monitorDomain'],
        ]);

        if ($monitor) {
            $this->addOrUpdateDomainDetails($monitor);
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
            'name' => $validated['name'],
            'url' => $validated['url'],
            'uptime_check_enabled' => $validated['monitorUptime'],
            'uptime_check_interval_in_minutes' => $validated['uptimeCheckInterval'],
            'domain_check_enabled' => $validated['monitorDomain'],
        ]);

        if ($monitor) {
            $this->addOrUpdateDomainDetails($monitor);
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

    /**
     * Add or Update domain details on the monitor.
     *
     * @param  Monitor  $monitor
     * @return void
     */
    public function addOrUpdateDomainDetails(Monitor $monitor)
    {
        $domainController = app(DomainService::class);

        $response = $domainController->lookupDomain($monitor->url);

        if ($response) {
            $domainExpirationDate = $response->getData();
            $monitor->update([
                'domain_expiration_date' => $domainExpirationDate->expiration_date
            ]);
        } else {
            Log::error('Failed to fetch domain details', ['url' => $monitor->url, 'monitor_id' => $monitor->id]);
            return redirect()->route('monitors.index')->withErrors(['errors' => 'Failed to fetch domain details']);
        }
    }
}
