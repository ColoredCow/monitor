<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use NotificationChannels\Webhook\WebhookMessage;
use Spatie\UptimeMonitor\Notifications\Notifications\CertificateCheckFailed as SpatieCertificateCheckFailed;

class CertificateCheckFailed extends SpatieCertificateCheckFailed
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
