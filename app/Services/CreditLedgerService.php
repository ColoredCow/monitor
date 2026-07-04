<?php

namespace App\Services;

use App\Models\CreditTransaction;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\MonitoringResumed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;

class CreditLedgerService
{
    public function grant(Organization $organization, int $amount, ?User $createdBy = null, ?string $description = null): CreditTransaction
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Grant amount must be positive.');
        }

        return $this->applyTransaction($organization, CreditTransaction::TYPE_GRANT, $amount, $createdBy, $description);
    }

    public function adjust(Organization $organization, int $amount, ?User $createdBy = null, ?string $description = null): CreditTransaction
    {
        if ($amount === 0) {
            throw new InvalidArgumentException('Adjustment amount must be non-zero.');
        }

        return $this->applyTransaction($organization, CreditTransaction::TYPE_ADJUSTMENT, $amount, $createdBy, $description);
    }

    /**
     * Audit record for a day of metered usage. The balance was already
     * decremented live by CreditMeteringService, so this only writes the
     * ledger row — it must NOT touch the balance.
     */
    public function recordUsageDebit(Organization $organization, int $credits, string $date): CreditTransaction
    {
        return CreditTransaction::create([
            'organization_id' => $organization->id,
            'type' => CreditTransaction::TYPE_USAGE_DEBIT,
            'amount' => -$credits,
            'balance_after' => $organization->fresh()->credit_balance,
            'description' => "Metered usage for {$date}",
            'metadata' => ['date' => $date],
        ]);
    }

    protected function applyTransaction(Organization $organization, string $type, int $amount, ?User $createdBy, ?string $description): CreditTransaction
    {
        [$transaction, $resumed] = DB::transaction(function () use ($organization, $type, $amount, $createdBy, $description) {
            $locked = Organization::query()->whereKey($organization->id)->lockForUpdate()->firstOrFail();

            $wasPaused = $locked->credit_warning_level === Organization::CREDIT_LEVEL_EXHAUSTED;
            $locked->credit_balance += $amount;

            if ($amount > 0 && $locked->credit_balance > 0) {
                $locked->credit_warning_level = Organization::CREDIT_LEVEL_NONE;
            }

            $locked->save();

            $transaction = CreditTransaction::create([
                'organization_id' => $locked->id,
                'type' => $type,
                'amount' => $amount,
                'balance_after' => $locked->credit_balance,
                'description' => $description,
                'created_by' => $createdBy?->id,
            ]);

            return [$transaction, $wasPaused && $locked->credit_balance > 0];
        });

        // Outside the DB transaction: never hold a row lock while sending
        // synchronous mail.
        if ($resumed) {
            $fresh = $organization->fresh();
            Notification::send($fresh->admins()->get(), new MonitoringResumed($fresh));
        }

        return $transaction;
    }
}
