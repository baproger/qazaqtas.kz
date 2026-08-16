<?php

namespace App\Notifications;

use App\Models\Expense;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** Бухгалтерии: заявка сотрудника ждёт оплаты дольше срока. */
class CompanyExpenseStale extends Notification
{
    use Queueable;

    public function __construct(public Expense $expense, public int $days) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'company_expense_stale',
            'title' => '⏳ Заявка ждёт оплаты '.$this->days.' дн.',
            'message' => number_format((float) $this->expense->amount, 0, '.', ' ').' ₸ — '
                .($this->expense->responsible?->name ?? 'сотрудник')
                .($this->expense->description ? ': '.$this->expense->description : ''),
            // Тот же ключ, что у остальных денежных уведомлений: оплатили
            // заявку — резолвер погасит и это напоминание.
            'expense_id' => $this->expense->id,
            'url' => '/expenses-board',
        ];
    }
}
