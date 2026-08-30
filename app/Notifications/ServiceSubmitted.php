<?php

namespace App\Notifications;

use App\Models\Service;
use Illuminate\Notifications\Notification;

/** Партнёр прислал услугу на модерацию — весть ассистенту и админу. */
class ServiceSubmitted extends Notification
{
    public function __construct(public Service $service) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'service_submitted',
            'title' => 'Услуга на модерацию',
            'message' => $this->service->title.' — '.($this->service->partner?->name ?? 'партнёр'),
            'url' => route('moderation.services'),
        ];
    }
}
