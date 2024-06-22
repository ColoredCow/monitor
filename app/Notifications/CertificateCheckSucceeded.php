<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use NotificationChannels\Webhook\WebhookMessage;
use Spatie\UptimeMonitor\Notifications\Notifications\CertificateCheckSucceeded as SpatieCertificateCheckSucceeded;

class CertificateCheckSucceeded extends SpatieCertificateCheckSucceeded
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
