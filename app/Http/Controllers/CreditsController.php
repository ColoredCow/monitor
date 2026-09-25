<?php

namespace App\Http\Controllers;

use App\Models\CreditTransaction;
use App\Models\CreditUsageDaily;
use App\Models\Monitor;
use App\Services\CreditRunwayService;
use App\Support\CurrentOrganization;
use Inertia\Inertia;

class CreditsController extends Controller
{
    public function index(CreditRunwayService $runway)
    {
        $organization = app(CurrentOrganization::class)->get();

        $usageRows = CreditUsageDaily::query()
            ->where('organization_id', $organization->id)
            ->where('date', '>=', now('UTC')->subDays(29)->toDateString())
            ->get();

        $usageByDay = $usageRows
            ->groupBy(fn (CreditUsageDaily $row) => $row->date->toDateString())
            ->map(fn ($rows, $date) => [
                'date' => $date,
                'total' => (int) $rows->sum('credits'),
                'byType' => $rows->groupBy('check_type')
                    ->map(fn ($typeRows) => (int) $typeRows->sum('credits')),
            ])
            ->values()
            ->sortBy('date')
            ->values();

        $topMonitors = $usageRows
            ->whereNotNull('monitor_id')
            ->groupBy('monitor_id')
            ->map(fn ($rows, $monitorId) => [
                'monitor_id' => (int) $monitorId,
                'credits' => (int) $rows->sum('credits'),
            ])
            ->sortByDesc('credits')
            ->take(5)
            ->values();

        $monitorNames = Monitor::withTrashed()
            ->whereIn('id', $topMonitors->pluck('monitor_id'))
            ->pluck('name', 'id');

        $topMonitors = $topMonitors->map(fn (array $row) => $row + [
            'name' => $monitorNames[$row['monitor_id']] ?? 'Deleted monitor',
        ]);

        return Inertia::render('Credits/Index', [
            'balance' => $organization->credit_balance,
            'warningLevel' => $organization->credit_warning_level,
            'dailyBurn' => $runway->dailyBurnFor($organization),
            'usageByDay' => $usageByDay,
            'topMonitors' => $topMonitors,
            'transactions' => CreditTransaction::with('createdBy:id,name')
                ->where('organization_id', $organization->id)
                ->latest()
                ->limit(25)
                ->get()
                ->map(fn (CreditTransaction $transaction) => [
                    'id' => $transaction->id,
                    'type' => $transaction->type,
                    'amount' => $transaction->amount,
                    'balance_after' => $transaction->balance_after,
                    'description' => $transaction->description,
                    'created_by' => $transaction->createdBy?->name,
                    'created_at' => $transaction->created_at->toDateTimeString(),
                ]),
        ]);
    }
}
