<?php

namespace App\Notifications;

use App\Models\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MonitoringPaused extends Notification
{
    use Queueable;

    public function __construct(public Organization $organization) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("{$this->organization->name}: monitoring paused — out of credits")
            ->line("{$this->organization->name} has run out of credits.")
            ->line('All uptime, certificate, and domain checks are paused until credits are added.')
            ->line('Please contact your service administrator to top up.');
    }
}
