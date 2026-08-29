<?php

namespace App\Notifications;

use App\Models\Deal;
use Illuminate\Notifications\Notification;

/** Сообщение от робота этапа — текст задаёт владелец в настройках. */
class RobotNotification extends Notification
{
    public function __construct(public Deal $deal, public string $title, public string $message) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return ['type' => 'robot', 'title' => $this->title, 'message' => $this->message, 'deal_id' => $this->deal->id];
    }
}
