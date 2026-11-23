<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Notifications\CreditBalanceCritical;
use App\Notifications\CreditBalanceLow;
use App\Services\CreditMeteringService;
use App\Services\CreditRunwayService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

class EvaluateCreditWarnings extends Command
{
    protected $signature = 'credits:evaluate-warnings';

    protected $description = 'Escalate per-organization credit warning levels from projected runway and email org admins';

    private const LEVEL_RANK = [
        Organization::CREDIT_LEVEL_NONE => 0,
        Organization::CREDIT_LEVEL_LOW => 1,
        Organization::CREDIT_LEVEL_CRITICAL => 2,
        Organization::CREDIT_LEVEL_EXHAUSTED => 3,
    ];

    public function handle(CreditRunwayService $runway): int
    {
        Organization::query()->each(function (Organization $organization) use ($runway) {
            // Exhaustion is owned by the live zero-crossing in metering;
            // grants own the reset. This job only handles low/critical.
            // A balance <= 0 org with a stale level is repaired here; the
            // conditional UPDATE makes this a no-op for already-flagged orgs.
            if ($organization->credit_balance <= 0) {
                app(CreditMeteringService::class)->markExhausted($organization->id);

                return;
            }

            $days = $runway->runwayDaysFor($organization);

            if ($days === null) {
                // Nothing is consuming credits: clear a stale low/critical.
                if (in_array($organization->credit_warning_level, [Organization::CREDIT_LEVEL_LOW, Organization::CREDIT_LEVEL_CRITICAL], true)) {
                    $organization->forceFill(['credit_warning_level' => Organization::CREDIT_LEVEL_NONE])->save();
                }

                return;
            }

            $target = Organization::CREDIT_LEVEL_NONE;

            if ($days <= (int) config('credits.warning_days.critical')) {
                $target = Organization::CREDIT_LEVEL_CRITICAL;
            } elseif ($days <= (int) config('credits.warning_days.low')) {
                $target = Organization::CREDIT_LEVEL_LOW;
            }

            if ($target === Organization::CREDIT_LEVEL_NONE) {
                // Healthy again (e.g. monitors were removed): clear silently.
                if ($organization->credit_warning_level !== Organization::CREDIT_LEVEL_NONE) {
                    $organization->forceFill(['credit_warning_level' => Organization::CREDIT_LEVEL_NONE])->save();
                }

                return;
            }

            // ?? 0 so an unexpected stored value never kills the loop; the
            // escalation below then repairs it to a real level.
            $targetRank = self::LEVEL_RANK[$target] ?? 0;
            $currentRank = self::LEVEL_RANK[$organization->credit_warning_level] ?? 0;

            // Same severity: nothing to change, and never re-email.
            if ($targetRank === $currentRank) {
                return;
            }

            // Runway improved (critical -> low): step the stored level down
            // silently so the UI stops over-alarming. Exhausted is owned by
            // the pause/resume flow; only a grant clears it.
            if ($targetRank < $currentRank) {
                if ($organization->credit_warning_level !== Organization::CREDIT_LEVEL_EXHAUSTED) {
                    $organization->forceFill(['credit_warning_level' => $target])->save();
                }

                return;
            }

            $notification = $target === Organization::CREDIT_LEVEL_CRITICAL
                ? new CreditBalanceCritical($organization, $days)
                : new CreditBalanceLow($organization, $days);

            // Save the level only after a successful send, so a mail failure
            // is retried by the next daily run instead of suppressed forever.
            try {
                Notification::send($organization->admins()->get(), $notification);
            } catch (Throwable $exception) {
                Log::error('Credit warning notification failed to send.', [
                    'organization_id' => $organization->id,
                    'error' => $exception->getMessage(),
                ]);

                return;
            }

            $organization->forceFill(['credit_warning_level' => $target])->save();
        });

        $this->info('Credit warning levels evaluated.');

        return self::SUCCESS;
    }
}
