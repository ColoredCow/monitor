<?php

namespace App\Channels;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Arr;
use NotificationChannels\Webhook\Exceptions\CouldNotSendNotification;
use NotificationChannels\Webhook\WebhookChannel;

class GoogleChatChannel extends WebhookChannel
{
    /**
     * Send the given notification.
     *
     * @param  mixed  $notifiable
     * @return void
     */
    public function send($notifiable, Notification $notification)
    {
        if (! $url = $notifiable->routeNotificationFor('googleChat')) {
            return;
        }

        $webhookData = $notification->toGoogleChat($notifiable)->toArray();

        $response = $this->client->post($url, [
            'query' => Arr::get($webhookData, 'query'),
            'body' => json_encode(Arr::get($webhookData, 'data')),
            'verify' => Arr::get($webhookData, 'verify'),
            'headers' => Arr::get($webhookData, 'headers'),
        ]);

        if ($response->getStatusCode() >= 300 || $response->getStatusCode() < 200) {
            throw CouldNotSendNotification::serviceRespondedWithAnError($response);
        }

        return $response;
    }
}
