<?php

namespace App\Console\Commands;

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
            $count = 0;

            Organization::query()->each(function (Organization $organization) use ($ledger, $amount, $description, &$count) {
                $ledger->grant($organization, $amount, null, $description);
                $this->info("Granted {$amount} credits to organization #{$organization->id} ({$organization->name}).");
                $count++;
            });

            $this->info("Done: granted {$amount} credits to {$count} organization(s).");

            return self::SUCCESS;
        }

        $organization = Organization::find($orgId);

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
