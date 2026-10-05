<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/** Database notification shown in the notification centre. Queued so requests stay fast. */
class PlatformNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $title, public string $message, public string $kind = 'info', public ?string $url = null) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];   // add 'mail' here once mail delivery is configured
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return ['title' => $this->title, 'message' => $this->message, 'kind' => $this->kind, 'url' => $this->url];
    }
}
