<?php

namespace App\Services;

use App\Models\Monitor;
use App\Models\Organization;
use App\Notifications\MonitoringPaused;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

class CreditMeteringService
{
    /**
     * Charge one credit for an executed check. Called from the per-check
     * model hooks, so it must never throw: losing a credit's worth of
     * metering beats breaking the check pipeline.
     */
    public function recordCheck(Monitor $monitor, string $checkType): void
    {
        try {
            $organizationId = $monitor->organization_id;

            if (! $organizationId) {
                return;
            }

            DB::update(
                'update organizations set credit_balance = credit_balance - 1 where id = ?',
                [$organizationId]
            );

            $now = now();

            DB::table('credit_usage_daily')->upsert(
                [[
                    'organization_id' => $organizationId,
                    'monitor_id' => $monitor->id,
                    'check_type' => $checkType,
                    'date' => now('UTC')->toDateString(),
                    'credits' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]],
                ['organization_id', 'monitor_id', 'check_type', 'date'],
                ['credits' => DB::raw('credits + 1'), 'updated_at' => $now]
            );

            $this->handleZeroCrossing($organizationId);
        } catch (Throwable $exception) {
            Log::error('Credit metering failed; check continues unmetered.', [
                'monitor_id' => $monitor->id,
                'check_type' => $checkType,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    protected function handleZeroCrossing(int $organizationId): void
    {
        // Atomic conditional update: the affected-rows count tells us whether
        // WE made the ->exhausted transition, so overlapping check runs can
        // never send the paused email twice.
        $becameExhausted = Organization::query()
            ->whereKey($organizationId)
            ->where('credit_balance', '<=', 0)
            ->where('credit_warning_level', '!=', Organization::CREDIT_LEVEL_EXHAUSTED)
            ->update(['credit_warning_level' => Organization::CREDIT_LEVEL_EXHAUSTED]);

        if ($becameExhausted > 0) {
            $organization = Organization::find($organizationId);

            if ($organization) {
                // The exhausted flag above is already committed and correct;
                // a mail failure here must not bubble into recordCheck's
                // catch-all, which would misleadingly log it as a metering
                // failure.
                try {
                    Notification::send($organization->admins()->get(), new MonitoringPaused($organization));
                } catch (Throwable $exception) {
                    Log::error('MonitoringPaused notification failed to send.', [
                        'organization_id' => $organizationId,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }
        }
    }
}
