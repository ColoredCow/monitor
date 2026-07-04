<?php

namespace App\Console\Commands;

use App\Models\Monitor;
use App\Services\CreditMeteringService;
use App\Services\DomainService;
use App\Services\MonitorCheckLogService;
use Illuminate\Console\Command;

class CheckDomainExpiration extends Command
{
    protected $signature = 'monitor:check-domain-expiration
                            {--url= : Only check these URLs}
                            {--force : Force run all monitors}';

    protected $description = 'Check domain expiration of all sites';

    public function handle(DomainService $domainService): void
    {
        $monitors = $this->option('force')
            ? Monitor::whereHas('organization', fn ($query) => $query->where('credit_balance', '>', 0))->get()
            : Monitor::domainCheckEnabled();

        if ($url = $this->option('url')) {
            $urls = explode(',', $url);
            $monitors = $monitors->filter(function (Monitor $monitor) use ($urls) {
                return in_array((string) $monitor->url, $urls);
            });
        }

        $this->info('Checking the expiration of '.count($monitors).' monitors...');

        $hasNotifications = false;

        foreach ($monitors as $monitor) {
            app(CreditMeteringService::class)->recordCheck($monitor, MonitorCheckLogService::CHECK_TYPE_DOMAIN);

            $result = $domainService->verifyDomainExpiration($monitor);

            if ($result['notified']) {
                $hasNotifications = true;
            }
        }

        if ($hasNotifications) {
            $this->info('Expiration dates updated and notifications sent!');
        } else {
            $this->info('No domain expiring on selected time schedule');
        }
    }
}
