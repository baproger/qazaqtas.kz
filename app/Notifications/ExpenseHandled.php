<?php

namespace App\Notifications;

use App\Models\Expense;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** Остальным бухгалтерам: расход уже подтверждён коллегой — повторно не нужно. */
class ExpenseHandled extends Notification
{
    use Queueable;

    public function __construct(public Expense $expense, public User $by) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $entity = $this->expense->expenseable;
        $isProject = $entity instanceof \App\Models\Project;
        $number = $entity?->number;

        return [
            'type' => 'expense_handled',
            'title' => 'Расход уже подтверждён',
            'message' => 'Расход #'.$this->expense->id.' на '.number_format((float) $this->expense->amount, 0, '.', ' ').' ₸ подтвердил(а) '
                .$this->by->name.($number ? ' ('.$number.')' : ''),
            'expense_id' => $this->expense->id,
            'deal_number' => $number,
            'url' => $entity
                ? ($isProject ? route('projects.show', $entity->id, false) : route('deals.show', $entity->id, false))
                : null,
        ];
    }
}
