<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use NotificationChannels\Webhook\WebhookMessage;
use Spatie\UptimeMonitor\Notifications\Notifications\CertificateExpiresSoon as SpatieCertificateExpiresSoon;

class CertificateExpiresSoon extends SpatieCertificateExpiresSoon
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
