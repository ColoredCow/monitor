<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use NotificationChannels\Webhook\WebhookMessage;
use Illuminate\Notifications\Messages\MailMessage;
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
