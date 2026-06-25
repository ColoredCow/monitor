<?php

namespace App\Http\Controllers;

use App\Http\Requests\MonitorRequest;
use App\Models\Group;
use App\Models\Monitor;
use App\Models\MonitorCheckLog;
use App\Services\DomainService;
use App\Services\MonitorCheckLogService;
use Carbon\Carbon;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\Request;
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
     * @return Renderable
     */
    public function index()
    {
        $groups = Group::with(['monitors' => function ($query) {
            $query->orderBy('name');
        }])
            ->has('monitors')
            ->orderBy('name')->get();

        $monitorWithNoGroups = Monitor::whereNull('group_id')->orderBy('name')->get();

        if ($monitorWithNoGroups->count()) {
            $groups = collect($groups);
            $groups->push([
                'id' => null,
                'name' => 'Ungrouped Monitors',
                'monitors' => $monitorWithNoGroups,
            ]);
        }

        return Inertia::render('Monitors/Index', [
            'groups' => $groups,
        ]);
    }

    /**
     * Show the create monitor page.
     *
     * @return Renderable
     */
    public function create()
    {
        $groups = Group::orderBy('name')->get();

        return Inertia::render('Monitors/Create', [
            'groups' => $groups,
        ]);
    }

    /**
     * Create a new monitor.
     *
     * @return Renderable
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

        if ($monitor && $monitor->domain_check_enabled) {
            DomainService::addDomainExpiration($monitor);
        }

        return redirect()->route('monitors.index');
    }

    /**
     * Show the monitor details.
     *
     * @return Renderable
     */
    public function show(Request $request, Monitor $monitor)
    {
        $graph = null;
        $filters = null;
        $summary = null;
        $recentChecks = null;

        if (config('monitor-history.enabled')) {
            // The monitor's earliest check feeds the 'all' preset range, the
            // available-years list and the summary's first_checked_at. Resolve it
            // once here and thread it through, rather than re-running the same
            // MIN(checked_at) lookup inside each consumer.
            $firstCheckedAt = $monitor->checkLogs()->orderBy('checked_at')->value('checked_at');

            $range = $this->resolveHistoryRange($request, $firstCheckedAt);
            $fromUtc = $range['from']->copy()->startOfDay()->utc();
            $toUtc = $range['to']->copy()->endOfDay()->utc();
            $timezone = $range['timezone'];

            $availableYears = $this->availableYears($timezone, $firstCheckedAt);
            $graphYear = $this->resolveGraphYear($request, $availableYears);
            $graph = $this->buildGraphPayload($monitor, $graphYear, $timezone, $availableYears);

            $selectedRangeQuery = $monitor->checkLogs()
                ->whereBetween('checked_at', [$fromUtc, $toUtc]);

            $allTimeSummary = $this->buildSummary($monitor->checkLogs());
            $selectedRangeSummary = $this->buildSummary($selectedRangeQuery);

            $filters = [
                'preset' => $range['preset'],
                'from' => $range['from']->toDateString(),
                'to' => $range['to']->toDateString(),
                'timezone' => $timezone,
            ];

            $summary = [
                'all_time' => $allTimeSummary,
                'selected_range' => $selectedRangeSummary,
                'first_checked_at' => $firstCheckedAt
                    ? Carbon::parse($firstCheckedAt)->timezone($timezone)->toDateTimeString()
                    : null,
            ];

            $recentType = $request->string('recent_type')->toString() ?: MonitorCheckLogService::CHECK_TYPE_UPTIME;
            if (! in_array($recentType, [MonitorCheckLogService::CHECK_TYPE_UPTIME, MonitorCheckLogService::CHECK_TYPE_DOMAIN], true)) {
                $recentType = MonitorCheckLogService::CHECK_TYPE_UPTIME;
            }

            $recentChecks = $this->buildRecentChecks($monitor, $recentType, $fromUtc, $toUtc, $timezone);
        }

        return Inertia::render('Monitors/Show', [
            'monitor' => $monitor,
            'graph' => $graph,
            'filters' => $filters,
            'summary' => $summary,
            'recentChecks' => $recentChecks,
        ]);
    }

    /**
     * Edit the monitor details.
     *
     * @return Renderable
     */
    public function edit(Monitor $monitor)
    {
        $groups = Group::orderBy('name')->get();

        return Inertia::render('Monitors/Edit', [
            'monitor' => $monitor,
            'groups' => $groups,
        ]);
    }

    /**
     * Update the monitor details.
     *
     * @return Renderable
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

        if (($validated['monitorDomain'] && ! $currentDomainCheck) || ($monitor->wasChanged('url'))) {
            DomainService::addDomainExpiration($monitor);
        }

        return redirect()->route('monitors.index');
    }

    /**
     * Delete the monitor.
     *
     * @return Renderable
     */
    public function destroy(Monitor $monitor)
    {
        $monitor->delete();

        return redirect()->route('monitors.index');
    }

    protected function resolveHistoryRange(Request $request, $firstCheckedAt = null): array
    {
        // The daily metrics are aggregated server-side under this single timezone,
        // so the detail page must read them back under the same one. We deliberately
        // ignore any client-supplied timezone here: reading with the browser timezone
        // would never match the aggregated rows and would render empty heatmaps.
        $timezone = config('monitor-history.timezone') ?: config('app.timezone', 'UTC');
        if (! in_array($timezone, timezone_identifiers_list(), true)) {
            $timezone = 'UTC';
        }

        $preset = $request->string('preset')->toString() ?: '30d';

        if ($preset === 'all') {
            $from = $firstCheckedAt
                ? Carbon::parse($firstCheckedAt)->timezone($timezone)->startOfDay()
                : Carbon::now($timezone)->subDays(30)->startOfDay();
            $to = Carbon::now($timezone)->endOfDay();

            return compact('preset', 'from', 'to', 'timezone');
        }

        if ($preset === '7d') {
            return [
                'preset' => $preset,
                'from' => Carbon::now($timezone)->subDays(6)->startOfDay(),
                'to' => Carbon::now($timezone)->endOfDay(),
                'timezone' => $timezone,
            ];
        }

        if ($preset === 'custom') {
            $fromInput = $request->string('from')->toString();
            $toInput = $request->string('to')->toString();

            $from = $fromInput !== ''
                ? $this->parseDateInput($fromInput, $timezone, Carbon::now($timezone)->subDays(30)->startOfDay())
                : Carbon::now($timezone)->subDays(30)->startOfDay();
            $to = $toInput !== ''
                ? $this->parseDateInput($toInput, $timezone, Carbon::now($timezone)->endOfDay(), true)
                : Carbon::now($timezone)->endOfDay();

            if ($from->greaterThan($to)) {
                [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
            }

            return compact('preset', 'from', 'to', 'timezone');
        }

        return [
            'preset' => '30d',
            'from' => Carbon::now($timezone)->subDays(29)->startOfDay(),
            'to' => Carbon::now($timezone)->endOfDay(),
            'timezone' => $timezone,
        ];
    }

    protected function parseDateInput(string $value, string $timezone, Carbon $fallback, bool $endOfDay = false): Carbon
    {
        try {
            $date = Carbon::parse($value, $timezone);

            return $endOfDay ? $date->endOfDay() : $date->startOfDay();
        } catch (\Throwable $exception) {
            return $fallback;
        }
    }

    protected function buildSummary($query): array
    {
        $rows = (clone $query)
            ->selectRaw('check_type, status, COUNT(*) as aggregate')
            ->groupBy('check_type', 'status')
            ->get();

        $summary = [
            'total_checks' => 0,
            'status_totals' => [
                MonitorCheckLogService::STATUS_SUCCESS => 0,
                MonitorCheckLogService::STATUS_WARNING => 0,
                MonitorCheckLogService::STATUS_FAILED => 0,
                MonitorCheckLogService::STATUS_UNKNOWN => 0,
            ],
            'by_type' => [],
        ];

        foreach ($rows as $row) {
            $type = $row->check_type;
            $status = $row->status;
            $aggregate = (int) $row->aggregate;

            if (! isset($summary['by_type'][$type])) {
                $summary['by_type'][$type] = [
                    'total_checks' => 0,
                    'status_totals' => [
                        MonitorCheckLogService::STATUS_SUCCESS => 0,
                        MonitorCheckLogService::STATUS_WARNING => 0,
                        MonitorCheckLogService::STATUS_FAILED => 0,
                        MonitorCheckLogService::STATUS_UNKNOWN => 0,
                    ],
                    'success_ratio' => 0,
                ];
            }

            if (! isset($summary['status_totals'][$status])) {
                $summary['status_totals'][$status] = 0;
            }

            if (! isset($summary['by_type'][$type]['status_totals'][$status])) {
                $summary['by_type'][$type]['status_totals'][$status] = 0;
            }

            $summary['total_checks'] += $aggregate;
            $summary['status_totals'][$status] += $aggregate;
            $summary['by_type'][$type]['total_checks'] += $aggregate;
            $summary['by_type'][$type]['status_totals'][$status] += $aggregate;
        }

        foreach ($summary['by_type'] as $type => $typeSummary) {
            $totalChecks = $typeSummary['total_checks'];
            $successfulChecks = $typeSummary['status_totals'][MonitorCheckLogService::STATUS_SUCCESS] ?? 0;
            $summary['by_type'][$type]['success_ratio'] = $totalChecks > 0
                ? round(($successfulChecks / $totalChecks) * 100, 2)
                : 0;
        }

        $summary['success_ratio'] = $summary['total_checks'] > 0
            ? round(($summary['status_totals'][MonitorCheckLogService::STATUS_SUCCESS] / $summary['total_checks']) * 100, 2)
            : 0;

        return $summary;
    }

    protected function graphCheckTypes(Monitor $monitor): array
    {
        return [
            [
                'type' => MonitorCheckLogService::CHECK_TYPE_UPTIME,
                'enabled' => (bool) $monitor->uptime_check_enabled,
            ],
            [
                'type' => MonitorCheckLogService::CHECK_TYPE_DOMAIN,
                'enabled' => (bool) $monitor->domain_check_enabled,
            ],
        ];
    }

    protected function availableYears(string $timezone, $firstCheckedAt = null): array
    {
        $currentYear = (int) Carbon::now($timezone)->format('Y');

        if (! $firstCheckedAt) {
            return [$currentYear];
        }

        $minYear = (int) Carbon::parse($firstCheckedAt)->timezone($timezone)->format('Y');

        if ($minYear > $currentYear) {
            $minYear = $currentYear;
        }

        return range($minYear, $currentYear);
    }

    protected function resolveGraphYear(Request $request, array $availableYears): int
    {
        $default = end($availableYears) ?: (int) Carbon::now('UTC')->format('Y');

        $requested = $request->integer('year') ?: $default;

        if (! in_array((int) $requested, $availableYears, true)) {
            return (int) $default;
        }

        return (int) $requested;
    }

    protected function buildGraphPayload(Monitor $monitor, int $year, string $timezone, array $availableYears): array
    {
        $checkTypes = $this->graphCheckTypes($monitor);
        $recentChecksLimit = (int) config('monitor-history.recent_checks_limit', 150);

        $yearStartUtc = Carbon::create($year, 1, 1, 0, 0, 0, $timezone)->startOfDay()->utc();
        $yearEndUtc = Carbon::create($year, 12, 31, 0, 0, 0, $timezone)->endOfDay()->utc();
        $yearStartDate = Carbon::create($year, 1, 1, 0, 0, 0, $timezone)->toDateString();
        $yearEndDate = Carbon::create($year, 12, 31, 0, 0, 0, $timezone)->toDateString();

        $dailyMetricsByType = $monitor->dailyCheckMetrics()
            ->forTimezone($timezone)
            ->betweenDates($yearStartDate, $yearEndDate)
            ->orderBy('date')
            ->get()
            ->groupBy('check_type')
            ->map(function ($rows) {
                return $rows->map(function ($row) {
                    return [
                        'date' => $row->date->toDateString(),
                        'total_checks' => $row->total_checks,
                        'successful_checks' => $row->successful_checks,
                        'warning_checks' => $row->warning_checks,
                        'failed_checks' => $row->failed_checks,
                        'success_ratio' => (float) $row->success_ratio,
                        'worst_status' => $row->worst_status,
                        'avg_response_time_ms' => $row->avg_response_time_ms,
                        'p95_response_time_ms' => $row->p95_response_time_ms,
                    ];
                })->values();
            });

        $series = [];

        foreach ($checkTypes as $checkType) {
            $type = $checkType['type'];

            $typeSummary = $this->buildSummary(
                $monitor->checkLogs()
                    ->where('check_type', $type)
                    ->whereBetween('checked_at', [$yearStartUtc, $yearEndUtc])
            );

            $series[$type] = [
                'summary' => [
                    'total_checks' => $typeSummary['by_type'][$type]['total_checks'] ?? 0,
                    'success_ratio' => (float) ($typeSummary['by_type'][$type]['success_ratio'] ?? 0),
                    'status_totals' => $typeSummary['by_type'][$type]['status_totals'] ?? [
                        MonitorCheckLogService::STATUS_SUCCESS => 0,
                        MonitorCheckLogService::STATUS_WARNING => 0,
                        MonitorCheckLogService::STATUS_FAILED => 0,
                        MonitorCheckLogService::STATUS_UNKNOWN => 0,
                    ],
                ],
                'daily_metrics' => $dailyMetricsByType->get($type, collect())->values()->all(),
                'latest_checks' => $this->buildLatestChecks($monitor, $type, $timezone, $recentChecksLimit),
            ];
        }

        return [
            'year' => $year,
            'available_years' => $availableYears,
            'timezone' => $timezone,
            'today_iso' => Carbon::now($timezone)->toDateString(),
            'check_types' => $checkTypes,
            'recent_checks_limit' => $recentChecksLimit,
            'series' => $series,
        ];
    }

    protected function buildRecentChecks(Monitor $monitor, string $type, Carbon $fromUtc, Carbon $toUtc, string $timezone): array
    {
        $paginator = $monitor->checkLogs()
            ->where('check_type', $type)
            ->whereBetween('checked_at', [$fromUtc, $toUtc])
            ->latest('checked_at')
            ->paginate(25, ['*'], 'recent_page');

        $data = collect($paginator->items())
            ->map(function (MonitorCheckLog $log) use ($timezone) {
                return [
                    'id' => $log->id,
                    'check_type' => $log->check_type,
                    'status' => $log->status,
                    'checked_at' => $log->checked_at->timezone($timezone)->toDateTimeString(),
                    'message' => $log->message,
                    'failure_reason' => $log->failure_reason,
                    'response_time_ms' => $log->response_time_ms,
                ];
            })
            ->all();

        return [
            'type' => $type,
            'data' => $data,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    protected function buildLatestChecks(Monitor $monitor, string $checkType, string $timezone, int $limit): array
    {
        return $monitor->checkLogs()
            ->where('check_type', $checkType)
            ->latest('checked_at')
            ->limit($limit)
            ->get()
            ->map(function (MonitorCheckLog $log) use ($timezone) {
                return [
                    'id' => $log->id,
                    'checked_at' => $log->checked_at->timezone($timezone)->toDateTimeString(),
                    'status' => $log->status,
                    'message' => $log->message,
                    'failure_reason' => $log->failure_reason,
                    'response_time_ms' => $log->response_time_ms,
                ];
            })
            ->all();
    }
}
