<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Monitor;
use App\Services\DomainService;

class CheckDomainExpiration extends Command
{
    protected $signature = 'monitor:check-domain-expiration
                            {--url= : Only check these URLs}
                            {--force : Force run all monitors}';

    protected $description = 'Check domain expiration of all sites';

    public function handle(DomainService $domainService): void
    {
        $monitors = $this->option('force') ? Monitor::all() : Monitor::domainCheckEnabled();

        if ($url = $this->option('url')) {
            $urls = explode(',', $url);
            $monitors = $monitors->filter(function (Monitor $monitor) use ($urls) {
                return in_array((string) $monitor->url, $urls);
            });
        }

        $this->info('Checking the expiration of ' . count($monitors) . ' monitors...');

        $hasNotifications = false;

        foreach ($monitors as $monitor) {
            $notificationMessageConfirmation = $domainService->verifyDomainExpiration($monitor);

            if ($notificationMessageConfirmation) {
                $hasNotifications = true;
            }
        }

        if($hasNotifications){
            $this->info('Expiration dates updated and notifications sent!');
        } else {
            $this->info('No domain expiring on selected time schedule');
        }
    }
}
