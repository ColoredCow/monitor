<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use NotificationChannels\Webhook\WebhookMessage;
use Spatie\UptimeMonitor\Notifications\Notifications\UptimeCheckSucceeded as SpatieUptimeCheckSucceeded;

class UptimeCheckSucceeded extends SpatieUptimeCheckSucceeded
{
    use Queueable;

    public function toGoogleChat($notifiable)
    {
        return WebhookMessage::create()
            ->data([
                'text' => $this->getMessageText(),
            ]);
    }
}
