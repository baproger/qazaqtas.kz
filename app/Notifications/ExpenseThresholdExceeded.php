<?php

namespace App\Notifications;

use App\Models\Deal;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** Финансисту: расходы сделки превысили 60% от суммы договора. */
class ExpenseThresholdExceeded extends Notification
{
    use Queueable;

    public function __construct(public Deal $deal, public float $spent) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $pct = (float) $this->deal->budget > 0 ? round($this->spent / (float) $this->deal->budget * 100) : 0;

        return [
            'type' => 'expense_threshold',
            'title' => 'Расходы превысили 60% договора',
            'message' => 'Сделка '.$this->deal->number.' ('.($this->deal->company_name ?: $this->deal->name).'): расходы '
                .number_format($this->spent, 0, '.', ' ').' ₸ — '.$pct.'% от суммы '.number_format((float) $this->deal->budget, 0, '.', ' ').' ₸.',
            'url' => route('deals.show', $this->deal->id, absolute: false),
        ];
    }
}
