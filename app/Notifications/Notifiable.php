<?php

namespace App\Notifications;

use Illuminate\Notifications\Notifiable as NotifiableTrait;
use Spatie\UptimeMonitor\Notifications\Notifiable as SpatieNotifiable;

class Notifiable extends SpatieNotifiable
{
    use NotifiableTrait;

    public function routeNotificationForGoogleChat()
    {
        return config('uptime-monitor.notifications.google_chat.webhook_url');
    }
}
