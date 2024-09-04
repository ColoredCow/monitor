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
            return $domainServiceInstance->updateDomainExpiration($monitor, $domainInfo['expirationDate']);
        } elseif ($monitor->domain_expires_at && empty($domainInfo)) {
            return $domainServiceInstance->updateDomainExpiration($monitor, null);
        }
        return false;
    }

    public function verifyDomainExpiration(Monitor $monitor): bool
    {
        $domainInfo = $this->lookupDomain($monitor->url);

        if (! empty($domainInfo)) {
            if(! $monitor->domain_expires_at->equalTo(Carbon::parse($domainInfo['expirationDate']))){
                $this->updateDomainExpiration($monitor, $domainInfo['expirationDate']);
            }

            return $this->checkAndNotifyExpiration($monitor);
        }
        return false;
    }

    protected function checkAndNotifyExpiration(Monitor $monitor) : bool
    {
        $expirationDate = $monitor->domain_expires_at;

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
            return ['expirationDate' => date('Y-m-d H:i:s', $domainInfo->expirationDate)];
        }
        return [];
    }

    protected function updateDomainExpiration(Monitor $monitor, mixed $expirationDate): bool
    {
        return $monitor->update(['domain_expires_at' => $expirationDate]);
    }

    protected function getBaseDomainFromUrl(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (!$host) {
            return '';
        }

        $host = preg_replace('/^www\./i', '', $host);

        $hostParts = explode('.', $host);
        $countHostParts = count($hostParts);

        if ($countHostParts < 2) {
            return $host;
        }

        $tld = array_pop($hostParts); // Get the TLD
        $secondLastHostPart = array_pop($hostParts); // Get the part before the TLD

        $mainDomain = $secondLastHostPart . '.' . $tld;

        // If there are more parts, check for valid multi-part TLDs
        if ($countHostParts > 2) {
            // Check if the current main domain is a valid TLD
            if (checkdnsrr($mainDomain, 'A') || checkdnsrr($mainDomain, 'MX')) {
                return $mainDomain;
            } else {
                $subdomainOrBase = array_pop($hostParts);
                return $subdomainOrBase . '.' . $mainDomain;
            }
        }

        return $mainDomain;
    }
}
