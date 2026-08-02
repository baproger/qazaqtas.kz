<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Руководству (и руководителю отдела): у сотрудника сегодня или скоро
 * день рождения. Шлёт команда users:notify-birthdays (раз в день).
 */
class BirthdayUpcoming extends Notification
{
    use Queueable;

    public function __construct(
        public int $userId,
        public string $userName,
        public string $date,     // d.m
        public bool $isToday,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'birthday',
            'title' => $this->isToday ? '🎂 Сегодня день рождения!' : '🎂 Скоро день рождения',
            'message' => $this->isToday
                ? "{$this->userName} — поздравьте!"
                : "{$this->userName} — {$this->date}",
            'user_id' => $this->userId,
        ];
    }
}
