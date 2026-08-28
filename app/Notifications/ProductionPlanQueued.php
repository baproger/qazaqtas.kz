<?php

namespace App\Notifications;

use App\Models\Deal;
use App\Models\ProductionPlan;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Руководству производства: под сделку не хватило склада, объём ушёл в план.
 *
 * Уведомление говорит не «где-то нехватка», а «вот сколько чего надо
 * сделать» — иначе начальнику производства пришлось бы искать это самому.
 * Ссылка ведёт на «План — факт», где стоит нераспределённая строка.
 */
class ProductionPlanQueued extends Notification
{
    use Queueable;

    /** @param  array<int, ProductionPlan>  $plans */
    public function __construct(public Deal $deal, public array $plans) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $what = collect($this->plans)
            ->map(fn (ProductionPlan $p) => ($p->product?->name ?? '—').' — '
                .rtrim(rtrim(number_format((float) $p->plan_qty, 2, '.', ' '), '0'), '.')
                .' '.($p->unit ?: ''))
            ->implode('; ');

        return [
            'type' => 'production_plan_queued',
            'title' => 'В план производства',
            'message' => 'Сделка '.$this->deal->number.': '.$what.' — бригада не назначена.',
            'url' => route('production.plans.index', [], false),
            'deal_id' => $this->deal->id,
        ];
    }
}
