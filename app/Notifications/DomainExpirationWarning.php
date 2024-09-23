<?php

namespace App\Notifications;

use App\Models\Monitor;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use NotificationChannels\Webhook\WebhookMessage;

class DomainExpirationWarning extends Notification
{
    use Queueable;

    protected $monitor;
    protected $message;

    public function __construct(Monitor $monitor, string $message)
    {
        $this->monitor = $monitor;
        $this->message = $message;
    }

    public function via(Notifiable $notifiable): array
    {
        return config('domain-expiration.notifications.notifications.'.static::class);
    }

    public function toGoogleChat(Notifiable $notifiable) : WebhookMessage
    {
        return WebhookMessage::create()
            ->data([
                'text' => $this->getMessageText(),
            ]);
    }

    public function toMail(Notifiable $notifiable): MailMessage
    {
        $mailMessage = (new MailMessage())
            ->subject($this->monitor->name.': '.$this->message)
            ->line($this->getLocationDescription());

            $mailMessage->line($this->getMessageText());

        return $mailMessage;
    }

    public function getMessageText(): string
    {
        $expirationDate = $this->monitor->domain_expires_at->format('F j, Y');

        return "Alert: {$this->message} Domain: {$this->monitor->url}. Expiration date: {$expirationDate}.";
    }

    public function getLocationDescription(): string
    {
        $configuredLocation = config('domain-expiration.notifications.location');

        if ($configuredLocation == '') {
            return '';
        }

        return "Monitor {$configuredLocation}";
    }

}
