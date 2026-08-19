<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class DomainNotification extends Notification
{
    use Queueable;

    public function __construct(public array $payload)
    {
        // The payload is serialized by Laravel's database notification channel.
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return $this->payload;
    }
}
