<?php

namespace App\Listeners;

use App\Events\DealMovedToStage;
use App\Services\StockService;

/**
 * Склад следует за судьбой сделки.
 *
 * Сделка дошла до выигрышного этапа — позиции списываются со склада фирмы
 * (не больше остатка: сделанное под заказ на складе не лежало). Сделку
 * увели с выигрышного этапа назад — списанное возвращается. Оба действия
 * идемпотентны: движения привязаны к позиции сделки уникальным индексом.
 */
class SyncStockOnDealStage
{
    public function __construct(private StockService $stock) {}

    public function handle(DealMovedToStage $event): void
    {
        $wasWon = (bool) $event->from?->is_won;
        $isWon = (bool) $event->to->is_won;

        if (! $wasWon && $isWon) {
            $this->stock->writeOffDeal($event->deal, $event->user?->id);
        } elseif ($wasWon && ! $isWon) {
            $this->stock->returnDeal($event->deal, $event->user?->id);
        }
    }
}
