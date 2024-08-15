<?php

namespace App\Notifications;

use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use NotificationChannels\Webhook\WebhookMessage;

class DomainExpiration extends Notification
{
    use Queueable;

    protected $monitor;
    protected $message;

    public function __construct($monitor, $message)
    {
        $this->monitor = $monitor;
        $this->message = $message;
    }

    public function via($notifiable)
    {
        return config('uptime-monitor.notifications.notifications.'.static::class);
    }

    public function toGoogleChat($notifiable)
    {
        $expirationDate = Carbon::parse($this->monitor->domain_expiration_date)->format('F j, Y');

        return WebhookMessage::create()
            ->data([
                'text' => "Alert: {$this->message} Domain: {$this->monitor->url}. Expiration date: {$expirationDate}.",
            ]);
    }
}
