<?php

namespace App\Notifications;

use App\Models\Service;
use Illuminate\Notifications\Notification;

/** Итог модерации — партнёру. */
class ServiceModerated extends Notification
{
    public function __construct(public Service $service) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $ok = $this->service->status === 'approved';

        return [
            'type' => 'service_moderated',
            'title' => $ok ? 'Услуга опубликована' : 'Услуга отклонена',
            'message' => $this->service->title.($ok ? ' — уже видна в каталоге услуг.' : ' — причина: '.$this->service->rejection_reason),
            'url' => route('partner.services'),
        ];
    }
}
