<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Начальнику производства: под новую сделку не хватает товара на складе.
 *
 * Сделку не блокируем — договор уже подписан, отменять его складом поздно. Но
 * если менеджер продал 1000 м², а на складе 200, кто-то должен узнать об этом
 * в тот же день, а не когда придёт время грузить.
 */
class ProductShortage extends Notification
{
    use Queueable;

    /**
     * @param  array<int, array{name: string, unit: ?string, need: float, have: float, short: float}>  $rows
     */
    public function __construct(
        public array $rows,
        public ?string $dealNumber = null,
        public ?int $dealId = null,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $short = collect($this->rows)
            ->map(fn ($r) => $r['name'].' — '.rtrim(rtrim(number_format($r['short'], 2, '.', ' '), '0'), '.')
                .' '.($r['unit'] ?: ''))
            ->implode('; ');

        return [
            'type' => 'product_shortage',
            'title' => 'Не хватает на складе',
            'message' => ($this->dealNumber ? 'Сделка '.$this->dealNumber.': ' : '').$short,
            'url' => $this->dealId ? route('deals.show', $this->dealId, false) : route('deals.index', [], false),
            'deal_id' => $this->dealId,
        ];
    }
}
