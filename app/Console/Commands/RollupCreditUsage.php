<?php

namespace App\Console\Commands;

use App\Models\CreditTransaction;
use App\Models\CreditUsageDaily;
use App\Models\Organization;
use App\Services\CreditLedgerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RollupCreditUsage extends Command
{
    protected $signature = 'credits:rollup-usage
                            {--date= : UTC date (Y-m-d) to roll up; defaults to yesterday}';

    protected $description = 'Write one usage_debit ledger transaction per organization for a day of metered usage';

    public function handle(CreditLedgerService $ledger): int
    {
        $date = $this->option('date') ?? now('UTC')->subDay()->toDateString();

        $totals = CreditUsageDaily::query()
            ->where('date', $date)
            ->groupBy('organization_id')
            ->selectRaw('organization_id, sum(credits) as total')
            ->pluck('total', 'organization_id');

        $written = 0;

        foreach ($totals as $organizationId => $total) {
            $organization = Organization::withTrashed()->find($organizationId);

            if (! $organization) {
                continue;
            }

            // The exists-check has no DB constraint behind it, so take the org
            // row lock first: a concurrent run for the same date blocks here,
            // then sees the row the first run wrote. recordUsageDebit's own
            // transaction becomes a savepoint and its lock is already held.
            $wrote = DB::transaction(function () use ($organizationId, $date, $total, $ledger, $organization) {
                Organization::withTrashed()->whereKey($organizationId)->lockForUpdate()->first();

                $alreadyRolledUp = CreditTransaction::query()
                    ->where('organization_id', $organizationId)
                    ->where('type', CreditTransaction::TYPE_USAGE_DEBIT)
                    ->where('metadata->date', $date)
                    ->exists();

                if ($alreadyRolledUp) {
                    return false;
                }

                $ledger->recordUsageDebit($organization, (int) $total, $date);

                return true;
            });

            if ($wrote) {
                $written++;
            }
        }

        $this->info("Rolled up credit usage for {$date}: {$written} organization(s).");

        return self::SUCCESS;
    }
}
