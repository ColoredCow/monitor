<?php

namespace App\Notifications;

use App\Models\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CreditBalanceLow extends Notification
{
    use Queueable;

    public function __construct(public Organization $organization, public float $runwayDays) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $days = max(1, (int) floor($this->runwayDays));

        return (new MailMessage)
            ->subject("{$this->organization->name}: credit balance low")
            ->line("{$this->organization->name} has {$this->organization->credit_balance} credits left.")
            ->line("At the current monitor configuration they will last about {$days} day(s).")
            ->line('Please contact your service administrator to top up before monitoring pauses.');
    }
}
