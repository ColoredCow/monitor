<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use NotificationChannels\Webhook\WebhookMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Spatie\UptimeMonitor\Notifications\Notifications\UptimeCheckRecovered as SpatieUptimeCheckRecovered;

class UptimeCheckRecovered extends SpatieUptimeCheckRecovered
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
