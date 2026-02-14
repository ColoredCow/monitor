<?php

namespace App\Services;

use App\Models\Monitor;
use App\Notifications\DomainExpirationWarning;
use App\Notifications\Notifiable;
use Carbon\Carbon;
use Exception;
use Iodev\Whois\Factory;

class DomainService
{
    protected $whois;

    public function __construct()
    {
        $this->whois = Factory::get()->createWhois();
    }

    public static function addDomainExpiration(Monitor $monitor): bool
    {
        $domainServiceInstance = new self;

        $domainInfo = $domainServiceInstance->getDomainExpirationDate($monitor->url);

        if (! empty($domainInfo)) {
            return $domainServiceInstance->updateDomainExpiration($monitor, $domainInfo['expirationDate']);
        }
        if ($monitor->domain_expires_at) {
            return $domainServiceInstance->updateDomainExpiration($monitor, null);
        }

        return false;
    }

    public function verifyDomainExpiration(Monitor $monitor): array
    {
        if (! $monitor->domain_check_enabled) {
            return [
                'status' => MonitorCheckLogService::STATUS_UNKNOWN,
                'notified' => false,
                'reason' => 'Domain check is disabled for this monitor.',
                'days_until_expiration' => null,
                'expiration_date' => null,
            ];
        }

        $checkedAt = Carbon::now()->utc();
        $domainInfo = $this->getDomainExpirationDate($monitor->url);

        if (empty($domainInfo)) {
            app(MonitorCheckLogService::class)->logCheck(
                monitor: $monitor,
                checkType: MonitorCheckLogService::CHECK_TYPE_DOMAIN,
                status: MonitorCheckLogService::STATUS_FAILED,
                checkedAt: $checkedAt,
                message: 'Domain expiration lookup failed.',
                failureReason: 'Unable to determine domain expiration date.',
            );

            return [
                'status' => MonitorCheckLogService::STATUS_FAILED,
                'notified' => false,
                'reason' => 'Unable to determine domain expiration date.',
                'days_until_expiration' => null,
                'expiration_date' => null,
            ];
        }

        $expirationDate = Carbon::parse($domainInfo['expirationDate']);
        if (! $monitor->domain_expires_at || ! $monitor->domain_expires_at->equalTo($expirationDate)) {
            $this->updateDomainExpiration($monitor, $domainInfo['expirationDate']);
        }

        $daysUntilExpiration = Carbon::now()->startOfDay()->diffInDays($expirationDate->copy()->startOfDay(), false);
        $notifications = $this->checkAndNotifyExpiration($monitor, $daysUntilExpiration);

        $status = match (true) {
            $daysUntilExpiration < 0 => MonitorCheckLogService::STATUS_FAILED,
            $daysUntilExpiration <= 30 => MonitorCheckLogService::STATUS_WARNING,
            default => MonitorCheckLogService::STATUS_SUCCESS,
        };

        $message = match (true) {
            $daysUntilExpiration < 0 => 'Domain has expired.',
            $daysUntilExpiration === 0 => 'Domain expires today.',
            default => "Domain expires in {$daysUntilExpiration} day(s).",
        };

        app(MonitorCheckLogService::class)->logCheck(
            monitor: $monitor,
            checkType: MonitorCheckLogService::CHECK_TYPE_DOMAIN,
            status: $status,
            checkedAt: $checkedAt,
            message: $message,
            metadata: [
                'days_until_expiration' => $daysUntilExpiration,
                'expiration_date' => optional($monitor->domain_expires_at)->toDateString(),
                'notifications_sent' => count($notifications),
            ],
        );

        return [
            'status' => $status,
            'notified' => ! empty($notifications),
            'reason' => null,
            'days_until_expiration' => $daysUntilExpiration,
            'expiration_date' => optional($monitor->domain_expires_at)->toDateString(),
        ];
    }

    protected function checkAndNotifyExpiration(Monitor $monitor, int $daysUntilExpiration): array
    {
        $expirationDate = $monitor->domain_expires_at;

        if (! $expirationDate) {
            return [];
        }

        $domainCheckTimePeriods = config('domain-expiration.domain_check_time_period');

        $notifications = [];

        foreach ($domainCheckTimePeriods as $warningType => $details) {
            $daysThreshold = $details['days'];

            if ($daysUntilExpiration >= 0 && $daysUntilExpiration === $daysThreshold) {
                $notifications[] = [
                    'days' => $daysThreshold,
                    'message' => "Domain expires in $daysThreshold ".($daysThreshold === 1 ? 'day' : 'days').'!',
                ];
                break;
            }
        }

        if (empty($notifications)) {
            return [];
        }

        $notifiable = new Notifiable;

        foreach ($notifications as $notification) {
            $notificationInstance = new DomainExpirationWarning($monitor, $notification['message']);
            $notifiable->notify($notificationInstance);
        }

        return $notifications;
    }

    protected function getDomainExpirationDate(string $url): array
    {
        $domainExpirationDate = $this->handleUrl($url);

        if ($domainExpirationDate) {
            return ['expirationDate' => date('Y-m-d H:i:s', $domainExpirationDate)];
        }

        return [];
    }

    protected function updateDomainExpiration(Monitor $monitor, mixed $expirationDate): bool
    {
        return $monitor->update(['domain_expires_at' => $expirationDate]);
    }

    protected function handleUrl(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);
        $domain = preg_replace('/^www\./i', '', $host);

        $domainInfo = $this->getWhoisRecord($domain);

        if ($domainInfo) {
            return $domainInfo;
        }

        $baseDomain = $this->getBaseDomainFromUrl($domain);

        $domainInfo = $this->getWhoisRecord($baseDomain);

        if ($domainInfo) {
            return $domainInfo;
        }

        return null;
    }

    protected function getWhoisRecord(string $baseDomain): ?string
    {
        try {
            $domainInfo = $this->whois->loadDomainInfo($baseDomain);
            if ($domainInfo) {
                return $domainInfo->expirationDate;
            }
        } catch (Exception $e) {
            return null;
        }

        return null;
    }

    protected function getBaseDomainFromUrl(string $host): string
    {
        $hostParts = explode('.', $host);
        $countHostParts = count($hostParts);

        if ($countHostParts < 2) {
            return $host;
        }

        $tld = array_pop($hostParts); // Get the TLD
        $secondLastHostPart = array_pop($hostParts); // Get the part before the TLD

        $mainDomain = $secondLastHostPart.'.'.$tld;

        // If there are more parts, check for valid multi-part TLDs
        if ($countHostParts > 2) {
            // Check if the current main domain is a valid TLD
            if (checkdnsrr($mainDomain, 'A') || checkdnsrr($mainDomain, 'MX')) {
                return $mainDomain;
            } else {
                $subdomainOrBase = array_pop($hostParts);

                return $subdomainOrBase.'.'.$mainDomain;
            }
        }

        return $mainDomain;
    }
}
