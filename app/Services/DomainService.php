<?php

namespace App\Services;

use Carbon\Carbon;
use Iodev\Whois\Factory;
use App\Models\Monitor;
use App\Notifications\Notifiable;
use App\Notifications\DomainExpirationWarning;

class DomainService
{
    protected $whois;

    public function __construct()
    {
        $this->whois = Factory::get()->createWhois();
    }

    public static function addDomainExpiration(Monitor $monitor): bool
    {
        $domainServiceInstance = new self();

        $domainInfo = $domainServiceInstance->lookupDomain($monitor->url);

        if (! empty($domainInfo)) {
            return $domainServiceInstance->updateDomainExpiration($monitor, $domainInfo['expiration_date']);
        }
        return false;
    }

    public function verifyDomainExpiration(Monitor $monitor): bool
    {
        $domainInfo = $this->lookupDomain($monitor->url);

        if (! empty($domainInfo)) {
            if(! $monitor->domain_expiration_date_time->equalTo(Carbon::parse($domainInfo['expiration_date']))){
                $this->updateDomainExpiration($monitor, $domainInfo['expiration_date']);
            }

            return $this->checkAndNotifyExpiration($monitor);
        }
        return false;
    }

    protected function checkAndNotifyExpiration(Monitor $monitor) : bool
    {
        $expirationDate = $monitor->domain_expiration_date_time;

        if(! $expirationDate){
            return false;
        }

        $daysUntilExpiration = Carbon::now()->diffInDays($expirationDate);

        $domainCheckTimePeriods = config('domain-expiration.domain_check_time_period');

        $notifications = [];

        foreach ($domainCheckTimePeriods as $warningType => $details) {
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
            return false;
        }

        $notifiable = new Notifiable();

        foreach ($notifications as $notification) {
            $notificationInstance = new DomainExpirationWarning($monitor, $notification['message']);
            $notifiable->notify($notificationInstance);
        }
        return true;
    }

    protected function lookupDomain(string $url): array
    {
        $baseDomain = $this->getBaseDomainFromUrl($url);

        $domainInfo = $this->whois->loadDomainInfo($baseDomain);

        if ($domainInfo && $domainInfo->expirationDate) {
            return ['expiration_date' => date('Y-m-d H:i:s', $domainInfo->expirationDate)];
        }
        return [];
    }

    protected function updateDomainExpiration(Monitor $monitor, string $expirationDate): bool
    {
        return $monitor->update(['domain_expiration_date_time' => $expirationDate]);
    }

    protected function getBaseDomainFromUrl(string $url): string
    {
        $parsedUrl = parse_url((string) $url);
        $host = $parsedUrl['host'] ?? $url;

        $hostParts = explode('.', $host);
        $hostParts = array_reverse($hostParts);

        if($hostParts[0] === 'com') {
            $mainDomain = $hostParts[1] . '.com';
        } else {
            $mainDomain = implode('.', array_reverse($hostParts));
        }
        $baseDomain = preg_replace('/^www\./', '', $mainDomain);

        return $baseDomain;
    }
}
