<?php

namespace App\Channels;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Arr;
use NotificationChannels\Webhook\Exceptions\CouldNotSendNotification;

class GoogleChatChannel extends AlertWebhookChannel
{
    /**
     * Send the given notification.
     *
     * @param  mixed  $notifiable
     * @return void
     */
    public function send($notifiable, Notification $notification)
    {
        if (! $url = $notifiable->routeNotificationFor('googleChat', $notification)) {
            return;
        }

        $webhookData = $notification->toGoogleChat($notifiable)->toArray();

        $response = $this->post($url, [
            'query' => Arr::get($webhookData, 'query'),
            'body' => json_encode(Arr::get($webhookData, 'data')),
            'headers' => Arr::get($webhookData, 'headers'),
        ]);

        if ($response->getStatusCode() >= 300 || $response->getStatusCode() < 200) {
            throw CouldNotSendNotification::serviceRespondedWithAnError($response);
        }

        return $response;
    }
}
