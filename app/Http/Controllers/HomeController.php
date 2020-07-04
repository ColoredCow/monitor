<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Spatie\UptimeMonitor\Models\Monitor;

class HomeController extends Controller
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
     * Show the application dashboard.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function index()
    {
        $monitors = Monitor::all();
        return view('home')->with([
            'monitors' => $monitors,
        ]);
    }
}
