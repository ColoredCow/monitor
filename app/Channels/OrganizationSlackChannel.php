<?php

namespace App\Channels;

use Illuminate\Notifications\Notification;
use NotificationChannels\Webhook\WebhookChannel;
use Throwable;

class OrganizationSlackChannel extends WebhookChannel
{
    /**
     * Post the alert to the Slack incoming webhook of the monitor's
     * organization, when it has one. Other organizations' alerts never go
     * there.
     *
     * The webhook is set by the organization itself, so a broken one is
     * reported instead of thrown: it must never stop the alert from reaching
     * the other channels.
     *
     * @param  mixed  $notifiable
     * @return \Psr\Http\Message\ResponseInterface|null
     */
    public function send($notifiable, Notification $notification)
    {
        $url = $notification->getMonitor()->organization?->slack_webhook_url;

        if (! $url) {
            return null;
        }

        try {
            return $this->client->post($url, [
                'json' => $notification->toOrganizationSlack($notifiable)->toArray()['data'],
                'timeout' => 10,
            ]);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }
}
