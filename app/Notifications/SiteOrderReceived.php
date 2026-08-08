<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** Новый заказ с сайта — уведомление менеджерам и руководству. */
class SiteOrderReceived extends Notification
{
    use Queueable;

    public function __construct(public Order $order) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'site_order',
            'title' => '🛒 Заказ с сайта '.$this->order->number,
            'message' => $this->order->name.' · '.$this->order->phone.' · '
                .number_format((float) $this->order->total, 0, '.', ' ').' ₸',
            'url' => route('siteOrders.index', absolute: false),
        ];
    }
}
