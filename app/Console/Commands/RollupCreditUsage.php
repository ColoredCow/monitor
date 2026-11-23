<?php

namespace App\Console\Commands;

use App\Models\CreditTransaction;
use App\Models\CreditUsageDaily;
use App\Models\Organization;
use App\Services\CreditLedgerService;
use DateTimeInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class RollupCreditUsage extends Command
{
    protected $signature = 'credits:rollup-usage
                            {--date= : UTC date (Y-m-d) to roll up; defaults to every date before today still missing its usage_debit}';

    protected $description = 'Write one usage_debit ledger transaction per organization for each day of metered usage';

    public function handle(CreditLedgerService $ledger): int
    {
        $dates = $this->option('date')
            ? collect([(string) $this->option('date')])
            : $this->datesMissingRollup();

        if ($dates->isEmpty()) {
            $this->info('Nothing to roll up.');

            return self::SUCCESS;
        }

        $failures = 0;

        foreach ($dates as $date) {
            $failures += $this->rollupDate($date, $ledger);
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Every UTC date before today with metered usage that some organization
     * still has no usage_debit transaction for. This makes the nightly run
     * self-healing: a night the host was down, or an org that threw last
     * night, is swept up by the next run without anyone passing --date.
     */
    protected function datesMissingRollup(): Collection
    {
        return CreditUsageDaily::query()
            ->where('date', '<', now('UTC')->toDateString())
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('credit_transactions')
                    ->whereColumn('credit_transactions.organization_id', 'credit_usage_daily.organization_id')
                    ->where('credit_transactions.type', CreditTransaction::TYPE_USAGE_DEBIT)
                    ->whereRaw("json_unquote(json_extract(credit_transactions.metadata, '$.date')) = date_format(credit_usage_daily.date, '%Y-%m-%d')");
            })
            ->distinct()
            ->orderBy('date')
            ->pluck('date')
            ->map(fn ($date) => $date instanceof DateTimeInterface ? $date->format('Y-m-d') : (string) $date);
    }

    /**
     * @return int the number of organizations that failed for this date
     */
    protected function rollupDate(string $date, CreditLedgerService $ledger): int
    {
        $totals = CreditUsageDaily::query()
            ->where('date', $date)
            ->groupBy('organization_id')
            ->selectRaw('organization_id, sum(credits) as total')
            ->pluck('total', 'organization_id');

        $written = 0;
        $failed = 0;

        foreach ($totals as $organizationId => $total) {
            try {
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
            } catch (Throwable $exception) {
                // One bad org must not abort the sweep. Its date stays
                // unrolled, so the next default run retries it.
                Log::error('Credit usage rollup failed for an organization; continuing.', [
                    'organization_id' => $organizationId,
                    'date' => $date,
                    'error' => $exception->getMessage(),
                ]);
                $this->error("Failed to roll up organization #{$organizationId} for {$date}: {$exception->getMessage()}");
                $failed++;
            }
        }

        $suffix = $failed > 0 ? ", {$failed} failed" : '';
        $this->info("Rolled up credit usage for {$date}: {$written} organization(s){$suffix}.");

        return $failed;
    }
}
