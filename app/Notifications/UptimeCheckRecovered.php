<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use NotificationChannels\Webhook\WebhookMessage;
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

    public function toOrganizationSlack($notifiable)
    {
        // Slack incoming webhooks take the same {"text": ...} payload as Google Chat.
        return $this->toGoogleChat($notifiable);
    }
}
