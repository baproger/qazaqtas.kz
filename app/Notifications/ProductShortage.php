<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Начальнику производства: под новую заявку не хватает товара на складе.
 *
 * Заявку не блокируем — это ещё запрос КП, а не обязательство. Но если
 * менеджер обещает 1000 м², а на складе 200, кто-то должен узнать об этом в
 * тот же день, а не когда придёт время грузить.
 */
class ProductShortage extends Notification
{
    use Queueable;

    /**
     * @param  array<int, array{name: string, unit: ?string, need: float, have: float, short: float}>  $rows
     */
    public function __construct(
        public array $rows,
        public ?string $requestNumber = null,
        public ?int $preDealId = null,
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
            'message' => ($this->requestNumber ? 'Заявка №'.$this->requestNumber.': ' : '').$short,
            'url' => route('preDeals.index', [], false),
            'pre_deal_id' => $this->preDealId,
        ];
    }
}
