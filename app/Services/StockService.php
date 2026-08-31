<?php

namespace App\Services;

use App\Models\Deal;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\StockMovement;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Склад готовой продукции: приход, расход, остаток.
 *
 * Единственное место, где остаток меняется. Меняется он только движением со
 * ссылкой на источник — наряд, позицию сделки, инвентаризацию: иначе
 * расхождение в конце месяца объяснить нечем, а откатить невозможно.
 *
 * Денормализованный остаток (`product_stocks`) пишется в той же транзакции,
 * что и движение, под блокировкой строки: два подтверждения одновременно
 * иначе прочитали бы одно и то же старое число и одно из них потерялось бы.
 */
class StockService
{
    /**
     * Приход из подтверждённой выработки по плану.
     *
     * Только по плану: наряд под позицию сделки прихода не даёт — этот товар
     * делается под конкретный заказ и уже продан, на складе ему делать нечего.
     * Иначе он лёг бы в остаток и был бы продан второй раз.
     *
     * Идемпотентно: повторное подтверждение того же наряда второго прихода не
     * создаёт (уникальный индекс по источнику и типу).
     */
    public function receiveFromWorkOrder(WorkOrder $order, ?int $userId = null): ?StockMovement
    {
        $plan = $order->plan;
        if (! $plan || ! $plan->product_id) {
            return null;
        }

        $measure = app(ProductionProgressService::class)->measure($plan->unit ?: $plan->product?->unit);
        $qty = round((float) $order->lines()->where('role', 'worker')->sum('qty_'.$measure), 2);
        if ($qty <= 0) {
            return null;
        }

        if ($this->alreadyMoved($order, StockMovement::PRODUCTION_IN)) {
            return null;
        }

        return $this->move(
            productId: (int) $plan->product_id,
            companyId: $order->company_id ? (int) $order->company_id : null,
            qty: $qty,
            type: StockMovement::PRODUCTION_IN,
            source: $order,
            userId: $userId,
            note: 'Наряд от '.$order->date?->format('d.m.Y').' · '.($order->brigade?->name ?: 'бригада'),
        );
    }

    /**
     * Сторно прихода: наряд отменили или удалили.
     *
     * Приход не стираем — он был, и его видели. Пишем обратное движение,
     * чтобы история осталась читаемой.
     */
    public function reverseWorkOrder(WorkOrder $order, ?int $userId = null): ?StockMovement
    {
        $income = StockMovement::where('source_type', $order->getMorphClass())
            ->where('source_id', $order->id)
            ->where('type', StockMovement::PRODUCTION_IN)
            ->first();

        if (! $income || $this->alreadyMoved($order, StockMovement::REVERSAL)) {
            return null;
        }

        return $this->move(
            productId: (int) $income->product_id,
            companyId: $income->company_id ? (int) $income->company_id : null,
            qty: -1 * (float) $income->qty,
            type: StockMovement::REVERSAL,
            source: $order,
            userId: $userId,
            note: 'Сторно прихода по наряду #'.$order->id,
        );
    }

    /**
     * Записать движение и поправить остаток — одной транзакцией.
     *
     * Разойдись они, остаток начал бы врать, а лента движений — показывать
     * другое число, и понять, какое из двух верное, стало бы нельзя.
     */
    public function move(int $productId, ?int $companyId, float $qty, string $type,
        ?Model $source = null, ?int $userId = null, ?string $note = null): StockMovement
    {
        return DB::transaction(function () use ($productId, $companyId, $qty, $type, $source, $userId, $note) {
            $movement = StockMovement::create([
                'product_id' => $productId,
                'company_id' => $companyId,
                'qty' => round($qty, 2),
                'type' => $type,
                'source_type' => $source?->getMorphClass(),
                'source_id' => $source?->getKey(),
                'created_by' => $userId,
                'note' => $note,
            ]);

            // Блокируем строку остатка: два движения по одному товару
            // одновременно иначе прочитали бы одно и то же старое число.
            $stock = ProductStock::query()
                ->where('product_id', $productId)
                ->where('company_id', $companyId)
                ->lockForUpdate()->first();

            if ($stock) {
                $stock->update(['qty' => round((float) $stock->qty + $qty, 2)]);
            } else {
                ProductStock::create([
                    'product_id' => $productId,
                    'company_id' => $companyId,
                    'qty' => round($qty, 2),
                ]);
            }

            // Витринный флажок «в наличии» следует за остатком, как только
            // товар встал на складской учёт (появилось первое движение).
            // Товар, которого на складе не бывает («под заказ»), остаётся на
            // ручном флажке из карточки каталога.
            $total = (float) ProductStock::where('product_id', $productId)->sum('qty');
            Product::whereKey($productId)->update(['in_stock' => $total > 0.001]);

            return $movement;
        });
    }

    /**
     * Списание позиций сделки со склада: сделка дошла до выигрышного этапа.
     *
     * Списываем НЕ БОЛЬШЕ остатка: чего на складе не было — делалось под
     * заказ и в остатке никогда не лежало (наряд под позицию сделки прихода
     * не даёт, §4 «Склад»). Идемпотентно по позиции: повторный перевод на
     * won второй раз не списывает (уникальный индекс источник+тип).
     */
    public function writeOffDeal(Deal $deal, ?int $userId = null): void
    {
        $companyId = $deal->company_id ? (int) $deal->company_id : null;

        foreach ($deal->items()->whereNotNull('product_id')->get() as $item) {
            if ($this->alreadyMoved($item, StockMovement::DEAL_OUT)) {
                continue;
            }

            $have = $this->qty((int) $item->product_id, $companyId);
            $qty = round(min((float) $item->quantity, max($have, 0)), 2);
            if ($qty <= 0) {
                continue; // остатка нет — вся позиция сделана под заказ
            }

            $this->move(
                productId: (int) $item->product_id,
                companyId: $companyId,
                qty: -$qty,
                type: StockMovement::DEAL_OUT,
                source: $item,
                userId: $userId,
                note: 'Сделка '.$deal->number,
            );
        }
    }

    /**
     * Возврат на склад: сделку увели с выигрышного этапа обратно.
     *
     * Возвращаем ровно столько, сколько списали (не сколько в позиции):
     * часть позиции могла делаться под заказ и на складе не лежала.
     */
    public function returnDeal(Deal $deal, ?int $userId = null): void
    {
        foreach ($deal->items()->whereNotNull('product_id')->get() as $item) {
            if ($this->alreadyMoved($item, StockMovement::DEAL_RETURN)) {
                continue;
            }

            $out = StockMovement::where('source_type', $item->getMorphClass())
                ->where('source_id', $item->id)
                ->where('type', StockMovement::DEAL_OUT)
                ->first();
            if (! $out) {
                continue;
            }

            $this->move(
                productId: (int) $out->product_id,
                companyId: $out->company_id ? (int) $out->company_id : null,
                qty: -1 * (float) $out->qty,
                type: StockMovement::DEAL_RETURN,
                source: $item,
                userId: $userId,
                note: 'Возврат: сделка '.$deal->number.' ушла с выигрышного этапа',
            );
        }
    }

    /** Остаток одного товара на складе фирмы. */
    public function qty(int $productId, ?int $companyId): float
    {
        return round((float) ProductStock::where('product_id', $productId)
            ->where('company_id', $companyId)->value('qty'), 2);
    }

    /**
     * Остатки пачкой: [id товара => остаток]. Для списков, где остаток
     * спрашивают сразу по всему каталогу.
     *
     * @param  array<int, int>|Collection<int, int>  $productIds
     * @return array<int, float>
     */
    public function qtyFor($productIds, ?int $companyId): array
    {
        $ids = collect($productIds)->map(fn ($id) => (int) $id)->unique();
        if ($ids->isEmpty()) {
            return [];
        }

        return ProductStock::whereIn('product_id', $ids)
            ->where('company_id', $companyId)
            ->pluck('qty', 'product_id')
            ->map(fn ($v) => round((float) $v, 2))
            ->all();
    }

    /**
     * Чего не хватает под этот список позиций.
     *
     * @param  array<int, array{product_id: int|null, quantity: float|int}>  $rows
     * @return Collection<int, array{product: Product, need: float, have: float, short: float}>
     */
    public function shortages(array $rows, ?int $companyId): Collection
    {
        $need = collect($rows)
            ->filter(fn ($r) => ! empty($r['product_id']) && (float) ($r['quantity'] ?? 0) > 0)
            ->groupBy('product_id')
            ->map(fn ($group) => round((float) collect($group)->sum('quantity'), 2));

        if ($need->isEmpty()) {
            return collect();
        }

        $have = $this->qtyFor($need->keys(), $companyId);
        $products = Product::whereIn('id', $need->keys())->get(['id', 'name', 'unit'])->keyBy('id');

        return $need->map(function (float $qty, $productId) use ($have, $products) {
            $stock = $have[(int) $productId] ?? 0.0;

            return [
                'product' => $products->get((int) $productId),
                'need' => $qty,
                'have' => $stock,
                'short' => round(max($qty - $stock, 0), 2),
            ];
        })->filter(fn ($row) => $row['short'] > 0 && $row['product'] !== null)->values();
    }

    /** Не сошёлся ли денормализованный остаток с суммой движений. */
    public function drift(): Collection
    {
        $sums = StockMovement::query()
            ->groupBy('product_id', 'company_id')
            ->selectRaw('product_id, company_id, sum(qty) as total')
            ->get();

        return $sums->filter(function ($row) {
            $stored = ProductStock::where('product_id', $row->product_id)
                ->where('company_id', $row->company_id)->value('qty');

            return abs(round((float) $stored - (float) $row->total, 2)) > 0.001;
        })->values();
    }

    private function alreadyMoved(Model $source, string $type): bool
    {
        return StockMovement::where('source_type', $source->getMorphClass())
            ->where('source_id', $source->getKey())
            ->where('type', $type)
            ->exists();
    }
}
