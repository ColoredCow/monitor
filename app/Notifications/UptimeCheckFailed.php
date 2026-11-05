<?php

namespace App\Notifications;

use App\Notifications\Concerns\FormatsChatAlerts;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Str;
use Spatie\UptimeMonitor\Notifications\Notifications\UptimeCheckFailed as SpatieUptimeCheckFailed;

class UptimeCheckFailed extends SpatieUptimeCheckFailed
{
    use FormatsChatAlerts;
    use Queueable;

    /**
     * The alert text with the failure reason, so a 403 from a firewall can be
     * told apart from a timeout or a 5xx without opening the email.
     */
    protected function getAlertText(): string
    {
        $reason = $this->getMonitor()->uptime_check_failure_reason;

        if (blank($reason)) {
            return $this->getMessageText();
        }

        return $this->getMessageText()."\nReason: ".Str::limit($reason, 300);
    }
}
