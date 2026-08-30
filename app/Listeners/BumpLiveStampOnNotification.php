<?php

namespace App\Listeners;

use App\Support\LiveStamp;
use Illuminate\Notifications\Events\NotificationSent;

/** Новое уведомление в БД → сдвинуть штамп получателя. */
class BumpLiveStampOnNotification
{
    public function handle(NotificationSent $event): void
    {
        if ($event->channel === 'database' && isset($event->notifiable->id)) {
            LiveStamp::bump((int) $event->notifiable->id, 'notifications');
        }
    }
}
