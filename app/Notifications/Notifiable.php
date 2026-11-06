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

    /**
     * The organization's own Google Chat space when it set one; otherwise the
     * app-wide space from UPTIME_MONITOR_GOOGLE_CHAT_WEBHOOK_URL.
     */
    public function routeNotificationForGoogleChat($notification = null)
    {
        $default = config('uptime-monitor.notifications.google_chat.webhook_url');

        return $this->organizationWebhook(
            $notification,
            'google_chat_webhook_url',
            'Google Chat',
            filled($default) ? 'using the default space.' : 'no default space is set, so the alert is skipped.'
        ) ?? $default;
    }

    /**
     * The Slack webhook of the alerting monitor's organization, if it set one.
     */
    public function routeNotificationForOrganizationSlack($notification)
    {
        return $this->organizationWebhook($notification, 'slack_webhook_url', 'Slack', 'Slack alert skipped.');
    }

    /**
     * The webhook the alerting monitor's organization set for a channel.
     *
     * A stored webhook that can't be decrypted (APP_KEY changed without
     * APP_PREVIOUS_KEYS) counts as unset, with a warning, rather than failing
     * the alert; an admin can paste it again.
     */
    private function organizationWebhook($notification, string $attribute, string $channel, string $fallback): ?string
    {
        if (! is_object($notification) || ! method_exists($notification, 'getMonitor')) {
            return null;
        }

        $organization = $notification->getMonitor()->organization;
        $url = $organization?->{$attribute};

        if ($url === null && filled($organization?->getRawOriginal($attribute))) {
            Log::warning("{$channel} webhook for organization {$organization->id} can't be decrypted; {$fallback}");
        }

        return $url;
    }
}
