<?php

namespace App\Notifications;

use App\Models\Expense;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** Автору заявки: бухгалтер проверил и оплатил. */
class CompanyExpensePaid extends Notification
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
        $method = $this->expense->payment_method === 'cash' ? 'наличными' : 'через банк';

        return [
            'type' => 'company_expense_paid',
            'title' => '✅ Ваш расход оплачен',
            'message' => number_format((float) $this->expense->amount, 0, '.', ' ').' ₸ '.$method
                .' — '.($this->expense->description ?: 'без описания'),
            'expense_id' => $this->expense->id,
            'url' => '/my-expenses',
        ];
    }
}
