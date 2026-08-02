<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * СЕО (admin) и директору: кто-то удалил финансовую запись (расход,
 * поступление, счёт, платёж, задолженность) — контроль изменений денег.
 */
class FinanceRecordDeleted extends Notification
{
    use Queueable;

    public function __construct(
        public string $what,    // что удалено (описание с суммой)
        public string $byName,  // кто удалил
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'finance_deleted',
            'title' => 'Удалена финансовая запись',
            'message' => $this->what.' — удалил(а) '.$this->byName,
            'url' => route('finance.index', absolute: false),
        ];
    }
}
