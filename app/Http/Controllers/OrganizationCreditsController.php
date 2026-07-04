<?php

namespace App\Http\Controllers;

use App\Models\CreditTransaction;
use App\Models\Organization;
use App\Services\CreditLedgerService;
use App\Services\CreditRunwayService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class OrganizationCreditsController extends Controller
{
    public function show(Organization $organization, CreditRunwayService $runway)
    {
        $this->authorize('manage-organizations');

        return Inertia::render('Organizations/Credits', [
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
                'credit_balance' => $organization->credit_balance,
                'credit_warning_level' => $organization->credit_warning_level,
            ],
            'dailyBurn' => $runway->dailyBurnFor($organization),
            'transactions' => CreditTransaction::with('createdBy:id,name')
                ->where('organization_id', $organization->id)
                ->latest()
                ->limit(50)
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

    public function store(Request $request, Organization $organization, CreditLedgerService $ledger): RedirectResponse
    {
        $this->authorize('manage-organizations');

        $validated = $request->validate([
            'amount' => 'required|integer|not_in:0',
            'description' => 'nullable|string|max:255',
        ]);

        $amount = (int) $validated['amount'];
        $description = $validated['description'] ?? null;

        $amount > 0
            ? $ledger->grant($organization, $amount, $request->user(), $description)
            : $ledger->adjust($organization, $amount, $request->user(), $description);

        return redirect()->route('organizations.credits.show', $organization);
    }
}
