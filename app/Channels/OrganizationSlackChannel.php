<?php

namespace App\Channels;

use Illuminate\Notifications\Notification;
use NotificationChannels\Webhook\WebhookChannel;
use Psr\Http\Message\ResponseInterface;

class OrganizationSlackChannel extends WebhookChannel
{
    /**
     * Post the notification to the Slack incoming webhook its notifiable
     * routes to. For uptime alerts that is the monitor's organization's own
     * webhook, so other organizations' alerts never go there.
     *
     * A failed post throws. App\Notifications\Notifiable sends each channel on
     * its own and reports the failure without the webhook URL.
     *
     * @param  mixed  $notifiable
     * @return ResponseInterface|null
     */
    public function send($notifiable, Notification $notification)
    {
        if (! method_exists($notification, 'toOrganizationSlack')) {
            return null;
        }

        if (! $url = $notifiable->routeNotificationFor('organizationSlack', $notification)) {
            return null;
        }

        return $this->client->post($url, [
            'json' => $notification->toOrganizationSlack($notifiable)->toArray()['data'],
            'timeout' => 10,
        ]);
    }
}
