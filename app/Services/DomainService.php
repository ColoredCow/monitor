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

        if ($domainInfo && $domainInfo->expirationDate) {
            return response()->json([
                'expiration_date' => date('Y-m-d H:i:s', $domainInfo->expirationDate)
            ]);
        }
        return 0;
    }

    public function updateAndNotifyDomainExpiration(Monitor $monitor)
    {
        $domainInfo = $this->lookupDomain($monitor->url);

        if ($domainInfo) {
            $domainExpirationDate = $domainInfo->getData();

            if ($domainExpirationDate && $domainExpirationDate->expiration_date) {
                $monitor->update(['domain_expiration_date' => $domainExpirationDate->expiration_date]);
            }
            return $this->checkAndNotifyExpiration($monitor);
        }
        return 0;
    }

    protected function checkAndNotifyExpiration(Monitor $monitor)
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

    protected function getBaseDomainFromUrl($url)
    {
        $parsedUrl = parse_url((string) $url);
        $host = $parsedUrl['host'] ?? $url;

        $hostParts = explode('.', $host);
        $hostParts = array_reverse($hostParts);

        if ($hostParts[0] === 'com') {
            $mainDomain = $hostParts[1] . '.com';
        } else {
            $mainDomain = implode('.', array_reverse($hostParts));
        }
        $baseDomain = preg_replace('/^www\./', '', $mainDomain);

        return $baseDomain;
    }
}
