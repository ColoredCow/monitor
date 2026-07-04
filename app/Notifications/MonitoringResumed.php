<?php

namespace App\Notifications;

use App\Models\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MonitoringResumed extends Notification
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
            ->subject("{$this->organization->name}: monitoring resumed")
            ->line("Credits were added to {$this->organization->name}.")
            ->line('All monitor checks are running again.');
    }
}
