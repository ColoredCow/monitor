<?php

namespace App\Console\Commands;

use App\Models\CreditTransaction;
use App\Models\Organization;
use App\Services\CreditLedgerService;
use Illuminate\Console\Command;

class GrantCredits extends Command
{
    protected $signature = 'credits:grant
                            {amount : Credits to grant}
                            {--org= : Organization id}
                            {--all : Grant to every organization}
                            {--description=Manual grant : Ledger description}';

    protected $description = 'Grant credits to an organization (or all organizations) via the credit ledger';

    public function handle(CreditLedgerService $ledger): int
    {
        $rawAmount = (string) $this->argument('amount');

        if (! ctype_digit($rawAmount) || (int) $rawAmount <= 0) {
            $this->error('Amount must be a positive integer.');

            return self::FAILURE;
        }

        $amount = (int) $rawAmount;

        $orgId = $this->option('org');
        $all = (bool) $this->option('all');

        if (($orgId !== null) === $all) {
            $this->error('Specify exactly one of --org or --all.');

            return self::FAILURE;
        }

        $description = (string) $this->option('description');

        if ($all) {
            $granted = 0;
            $skipped = 0;

            // Include soft-deleted orgs so a later restore comes back funded.
            Organization::withTrashed()->each(function (Organization $organization) use ($ledger, $amount, $description, &$granted, &$skipped) {
                // Rerun safety: after a partial failure (or a fleet funded by
                // hand), retrying must not double-grant the orgs that already
                // hold a grant with this description.
                $alreadyGranted = CreditTransaction::query()
                    ->where('organization_id', $organization->id)
                    ->where('type', CreditTransaction::TYPE_GRANT)
                    ->where('description', $description)
                    ->exists();

                if ($alreadyGranted) {
                    $this->warn("Skipped organization #{$organization->id} ({$organization->name}): already granted \"{$description}\".");
                    $skipped++;

                    return;
                }

                $ledger->grant($organization, $amount, null, $description);
                $this->info("Granted {$amount} credits to organization #{$organization->id} ({$organization->name}).");
                $granted++;
            });

            $this->info("Done: granted {$amount} credits to {$granted} organization(s), skipped {$skipped} already granted.");

            return self::SUCCESS;
        }

        // withTrashed to match --all: a deleted org can be funded ahead of a
        // restore from the CLI too.
        $organization = Organization::withTrashed()->find($orgId);

        if (! $organization) {
            $this->error("Organization #{$orgId} not found.");

            return self::FAILURE;
        }

        $ledger->grant($organization, $amount, null, $description);
        $this->info("Granted {$amount} credits to organization #{$organization->id} ({$organization->name}).");
        $this->info('Done: granted credits to 1 organization.');

        return self::SUCCESS;
    }
}
