<?php

namespace App\Services;

use Carbon\Carbon;
use Iodev\Whois\Factory;
use App\Models\Monitor;
use App\Notifications\Notifiable;
use App\Notifications\DomainExpiration;

class DomainService
{
    protected $whois;

    public function __construct()
    {
        $this->whois = Factory::get()->createWhois();
    }

    public function lookupDomain($url)
    {
        $baseDomain = $this->getBaseDomainFromUrl($url);

        $domainInfo = $this->whois->loadDomainInfo($baseDomain);

        if ($domainInfo) {
            return response()->json([
                'expiration_date' => date('Y-m-d H:i:s', $domainInfo->expirationDate)
            ]);
        }
        return 0;
    }

    public function updateDomainExpiration(Monitor $monitor)
    {
        $domainExpirationDate = $this->lookupDomain($monitor->url)->getData();

        if ($domainExpirationDate) {
            $monitor->update(['domain_expiration_date' => $domainExpirationDate->expiration_date]);

            $this->checkAndNotifyExpiration($monitor);
        }
    }

    public function checkAndNotifyExpiration(Monitor $monitor)
    {
        $expirationDate = $monitor->domain_expiration_date;

        if($expirationDate){
            $daysUntilExpiration = Carbon::now()->diffInDays($expirationDate);

            $config = config('uptime-monitor.domain_check_time_period');

            $notifications = [];

            foreach ($config as $warningType => $details) {
                $daysThreshold = $details['days'];

                if ($daysUntilExpiration === $daysThreshold) {
                    $notifications[] = [
                        'days' => $daysThreshold,
                        'message' => "Domain expires in $daysThreshold " . ($daysThreshold === 1 ? 'day' : 'days') . "!",
                    ];
                    break;
                }
            }

            if (empty($notifications)) {
                return 1;
            }

            $notifiable = new Notifiable();

            foreach ($notifications as $notification) {
                $notificationInstance = new DomainExpiration($monitor, $notification['message']);
                $notifiable->notify($notificationInstance);
            }
        } else {
            return 1;
        }
    }

    protected function getBaseDomainFromUrl($url){

        $parsedUrl = parse_url((string) $url);

        $host = $parsedUrl['host'] ?? $parsedUrl['path'] ?? $url;

        $baseDomain = preg_replace('/^www\./', '', $host);

        return $baseDomain;
    }
}
