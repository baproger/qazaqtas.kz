<?php

namespace App\Notifications;

use App\Models\Expense;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Бухгалтеру: сотрудник подал заявку на расход компании.
 *
 * Ведёт сразу на рабочее место бухгалтера, где заявки лежат очередью с
 * открытыми чеками, — а не в общий список расходов, где её надо искать.
 */
class CompanyExpenseSubmitted extends Notification
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
            'type' => 'company_expense_submitted',
            'title' => '🧾 Заявка на расход компании',
            'message' => number_format((float) $this->expense->amount, 0, '.', ' ').' ₸ — '
                .($this->expense->description ?: 'без описания')
                .' · '.($this->expense->responsible?->name ?? 'сотрудник'),
            'expense_id' => $this->expense->id,
            // Страница появится шагом позже — тогда путь заменится на route().
            'url' => '/expenses-board',
        ];
    }
}
