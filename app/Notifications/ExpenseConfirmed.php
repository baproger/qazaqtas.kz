<?php

namespace App\Notifications;

use App\Models\Expense;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** Автору расхода: бухгалтер подтвердил расход (нал/банк). */
class ExpenseConfirmed extends Notification
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
        return [
            'type' => 'expense_confirmed',
            'title' => 'Расход подтверждён',
            'message' => number_format((float) $this->expense->amount, 0, '.', ' ').' ₸ · '.($this->expense->payment_method === 'cash' ? 'наличные' : 'банк'),
            'expense_id' => $this->expense->id,
        ];
    }
}
