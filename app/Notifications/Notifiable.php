<?php

namespace App\Notifications;

use App\Exceptions\AlertChannelFailed;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Notifications\Notifiable as NotifiableTrait;
use Illuminate\Support\Facades\Log;
use Spatie\UptimeMonitor\Notifications\Notifiable as SpatieNotifiable;
use Throwable;

class Notifiable extends SpatieNotifiable
{
    use NotifiableTrait;

    /**
     * Send each channel on its own.
     *
     * Laravel stops at the first channel that throws, so a broken mail
     * transport or webhook would drop every channel after it, an
     * organization's Slack alert included. A failure is reported instead,
     * and HTTP client errors without the URL, which for a webhook is the
     * secret.
     */
    public function notify($instance)
    {
        foreach ($instance->via($this) as $channel) {
            try {
                $this->notifyNow($instance, [$channel]);
            } catch (Throwable $e) {
                report($e instanceof GuzzleException ? AlertChannelFailed::from($channel, $instance, $e) : $e);
            }
        }
    }

    public function routeNotificationForGoogleChat()
    {
        return config('uptime-monitor.notifications.google_chat.webhook_url');
    }

    /**
     * The Slack webhook of the alerting monitor's organization, if it set one.
     */
    public function routeNotificationForOrganizationSlack($notification)
    {
        if (! method_exists($notification, 'getMonitor')) {
            return null;
        }

        $organization = $notification->getMonitor()->organization;
        $url = $organization?->slack_webhook_url;

        // Stored but unreadable (APP_KEY changed without APP_PREVIOUS_KEYS):
        // Slack is skipped rather than failing the alert; an admin can paste it again.
        if ($url === null && filled($organization?->getRawOriginal('slack_webhook_url'))) {
            Log::warning("Slack webhook for organization {$organization->id} can't be decrypted; Slack alert skipped.");
        }

        return $url;
    }
}
