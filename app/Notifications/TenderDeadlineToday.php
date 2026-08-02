<?php

namespace App\Notifications;

use App\Models\PreDeal;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** Сегодня заканчивается тендер по лоту — напоминание ответственному менеджеру. */
class TenderDeadlineToday extends Notification
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
            'type' => 'tender_deadline',
            'title' => '⏳ Сегодня заканчивается тендер',
            'message' => 'Лот '.($this->preDeal->lot_number ? '№'.$this->preDeal->lot_number : '').' — '
                .$this->preDeal->product.($this->preDeal->customer ? ' ('.$this->preDeal->customer.')' : ''),
            'url' => route('preDeals.index', absolute: false),
        ];
    }
}
