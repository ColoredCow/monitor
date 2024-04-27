<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Illuminate\Http\Request;
use Spatie\UptimeMonitor\Models\Monitor;

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
        return Inertia::render('Monitors/Create');
    }

    /**
     * Create a new monitor.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function store(Request $request)
    {
        // create a monitor
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
}
