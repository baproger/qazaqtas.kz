<?php

namespace App\Notifications;

use App\Models\Expense;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** Бухгалтеру: менеджер добавил расход — нужно подтвердить (чек + нал/банк). */
class ExpensePending extends Notification
{
    use Queueable;

    public function __construct(public Expense $expense) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        // Ссылка на сделку/заказ, к которому относится расход — чтобы бухгалтер
        // сразу открыл нужную карточку и подтвердил (вкладка Финансы).
        $entity = $this->expense->expenseable;
        $isProject = $entity instanceof \App\Models\Project;
        $number = $entity?->number;
        $url = $entity
            ? ($isProject ? route('projects.show', $entity->id, false) : route('deals.show', $entity->id, false))
            : null;

        return [
            'type' => 'expense_pending',
            'title' => 'Расход ждёт подтверждения',
            'message' => number_format((float) $this->expense->amount, 0, '.', ' ').' ₸ — '
                .($this->expense->description ?: 'без описания')
                .($number ? ' ('.$number.')' : ''),
            'expense_id' => $this->expense->id,
            'deal_number' => $number,
            'url' => $url,
        ];
    }
}
