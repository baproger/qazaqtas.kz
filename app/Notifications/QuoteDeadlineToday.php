<?php

namespace App\Notifications;

use App\Models\PreDeal;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** Сегодня истекает срок КП по заявке — напоминание ответственному менеджеру. */
class QuoteDeadlineToday extends Notification
{
    use Queueable;

    public function __construct(public PreDeal $preDeal) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'quote_deadline',
            'title' => '⏳ Сегодня истекает срок КП',
            'message' => 'Заявка '.($this->preDeal->request_number ? '№'.$this->preDeal->request_number : '').' — '
                .$this->preDeal->product.($this->preDeal->customer ? ' ('.$this->preDeal->customer.')' : ''),
            'url' => route('preDeals.index', absolute: false),
        ];
    }
}
