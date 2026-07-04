<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Notifications\CreditBalanceCritical;
use App\Notifications\CreditBalanceLow;
use App\Services\CreditRunwayService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

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
            if ($organization->credit_balance <= 0) {
                return;
            }

            $days = $runway->runwayDaysFor($organization);

            if ($days === null) {
                return; // nothing is consuming credits
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

            // Escalation-only: same or lower severity never re-emails.
            if (self::LEVEL_RANK[$target] <= self::LEVEL_RANK[$organization->credit_warning_level]) {
                return;
            }

            $organization->forceFill(['credit_warning_level' => $target])->save();

            $notification = $target === Organization::CREDIT_LEVEL_CRITICAL
                ? new CreditBalanceCritical($organization, $days)
                : new CreditBalanceLow($organization, $days);

            Notification::send($organization->admins()->get(), $notification);
        });

        $this->info('Credit warning levels evaluated.');

        return self::SUCCESS;
    }
}
