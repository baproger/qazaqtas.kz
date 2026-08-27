<?php

namespace App\Services;

use App\Models\DealItem;
use App\Models\ProductionPlan;
use App\Models\WorkOrderLine;
use Illuminate\Support\Collection;

/**
 * План и факт производства: сколько по сделке нужно и сколько сделано.
 *
 * План — это позиция сделки: «Плитка «Ромб» — 210 м²». Факт — подтверждённая
 * выработка нарядов, привязанных к этой позиции. Одно число считается в одном
 * месте и показывается везде — в сделке, в цехе и на производстве; разойдись
 * счёт, бригадир и менеджер видели бы разный остаток по одному заказу.
 *
 * ФАКТ СЧИТАЕТСЯ ПО СТРОКАМ РАБОЧИХ. У наряда две породы строк: строки
 * рабочих (их объём) и одна строка бригадира на весь объём смены — она нужна
 * для его бонуса, а не для учёта. Сложить все строки значило бы посчитать
 * смену дважды.
 *
 * Неподтверждённые наряды в факт не идут, но показываются отдельно: мастер
 * должен видеть, что ждёт подтверждения, и не считать это сделанным.
 */
class ProductionProgressService
{
    /** Позиции в этих единицах считаются в м², остальные — в штуках. */
    private const SQUARE_UNITS = ['м²', 'м2', 'кв.м', 'м^2'];

    /** Метрика позиции: 'm2' или 'pcs'. Одна на план и на факт. */
    public function measure(?string $unit): string
    {
        return in_array(trim((string) $unit), self::SQUARE_UNITS, true) ? 'm2' : 'pcs';
    }

    /**
     * План/факт по позициям.
     *
     * @param  Collection<int, DealItem>|array<int, DealItem>  $items
     * @return array<int, array{measure: string, unit: ?string, plan: float, done: float, pending: float, left: float, percent: float, over: bool}>
     */
    public function forItems($items): array
    {
        $items = collect($items);
        if ($items->isEmpty()) {
            return [];
        }

        $facts = $this->factsByItem($items->pluck('id')->all());

        return $items->mapWithKeys(function (DealItem $item) use ($facts) {
            $measure = $this->measure($item->unit);
            $fact = $facts[$item->id][$measure] ?? ['done' => 0.0, 'pending' => 0.0];

            $plan = round((float) $item->quantity, 2);
            $done = round((float) $fact['done'], 2);

            return [$item->id => [
                'measure' => $measure,
                'unit' => $item->unit,
                'plan' => $plan,
                'done' => $done,
                'pending' => round((float) $fact['pending'], 2),
                // Остаток не уходит в минус: перевыполнение показываем флагом,
                // а «осталось −30 м²» читалось бы как долг.
                'left' => round(max($plan - $done, 0), 2),
                'percent' => $plan > 0 ? min(round($done / $plan * 100), 999) : 0,
                'over' => $plan > 0 && $done > $plan,
            ]];
        })->all();
    }

    /**
     * План/факт по месячным планам бригад.
     *
     * Тот же счёт, что по позициям сделки: план — задание, факт — объём
     * рабочих в подтверждённых нарядах по этому плану. Один сервис на оба
     * источника задания, иначе «выполнено» в цехе и на производстве
     * считалось бы по-разному.
     *
     * @param  \Illuminate\Support\Collection<int, ProductionPlan>|array<int, ProductionPlan>  $plans
     * @return array<int, array{measure: string, unit: ?string, plan: float, done: float, pending: float, left: float, percent: float, over: bool}>
     */
    public function forPlans($plans): array
    {
        $plans = collect($plans);
        if ($plans->isEmpty()) {
            return [];
        }

        $facts = $this->factsByPlan($plans->pluck('id')->all());

        return $plans->mapWithKeys(function (ProductionPlan $planRow) use ($facts) {
            $unit = $planRow->unit ?: $planRow->product?->unit;
            $measure = $this->measure($unit);
            $fact = $facts[$planRow->id][$measure] ?? ['done' => 0.0, 'pending' => 0.0];

            $plan = round((float) $planRow->plan_qty, 2);
            $done = round((float) $fact['done'], 2);

            return [$planRow->id => [
                'measure' => $measure,
                'unit' => $unit,
                'plan' => $plan,
                'done' => $done,
                'pending' => round((float) $fact['pending'], 2),
                'left' => round(max($plan - $done, 0), 2),
                'percent' => $plan > 0 ? min(round($done / $plan * 100), 999) : 0,
                'over' => $plan > 0 && $done > $plan,
            ]];
        })->all();
    }

    /**
     * Сколько сделала каждая бригада по этим позициям.
     *
     * Бригадир спрашивает не только «сколько по сделке осталось», но и «чья
     * это работа»: на одном объекте смены ведут разные бригады.
     *
     * @param  array<int, int>  $itemIds
     * @return array<int, array<int, array{brigade: ?string, m2: float, pcs: float}>> [id позиции => [id бригады => …]]
     */
    public function byBrigade(array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }

        $result = [];
        foreach ($this->workerLines($itemIds)->where('order.status', 'confirmed') as $line) {
            $itemId = (int) $line->order->deal_item_id;
            $brigadeId = (int) $line->order->brigade_id;

            $row = $result[$itemId][$brigadeId] ?? ['brigade' => $line->order->brigade?->name, 'm2' => 0.0, 'pcs' => 0.0];
            $row['m2'] = round($row['m2'] + (float) $line->qty_m2, 2);
            $row['pcs'] = round($row['pcs'] + (float) $line->qty_pcs, 2);
            $result[$itemId][$brigadeId] = $row;
        }

        return $result;
    }

    /**
     * Факт по позициям, отдельно подтверждённый и ожидающий.
     *
     * @param  array<int, int>  $itemIds
     * @return array<int, array<string, array{done: float, pending: float}>>
     */
    private function factsByItem(array $itemIds): array
    {
        $facts = [];
        foreach ($this->workerLines($itemIds) as $line) {
            $itemId = (int) $line->order->deal_item_id;
            $bucket = $line->order->status === 'confirmed' ? 'done' : 'pending';

            foreach (['m2' => 'qty_m2', 'pcs' => 'qty_pcs'] as $measure => $column) {
                $facts[$itemId][$measure] ??= ['done' => 0.0, 'pending' => 0.0];
                $facts[$itemId][$measure][$bucket] += (float) $line->{$column};
            }
        }

        return $facts;
    }

    /**
     * Факт по планам, отдельно подтверждённый и ожидающий.
     *
     * @param  array<int, int>  $planIds
     * @return array<int, array<string, array{done: float, pending: float}>>
     */
    private function factsByPlan(array $planIds): array
    {
        if ($planIds === []) {
            return [];
        }

        $lines = WorkOrderLine::query()
            ->where('role', 'worker')
            ->whereHas('order', fn ($q) => $q->whereIn('production_plan_id', $planIds))
            ->with('order:id,production_plan_id,status')
            ->get(['id', 'work_order_id', 'qty_pcs', 'qty_m2']);

        $facts = [];
        foreach ($lines as $line) {
            $planId = (int) $line->order->production_plan_id;
            $bucket = $line->order->status === 'confirmed' ? 'done' : 'pending';

            foreach (['m2' => 'qty_m2', 'pcs' => 'qty_pcs'] as $measure => $column) {
                $facts[$planId][$measure] ??= ['done' => 0.0, 'pending' => 0.0];
                $facts[$planId][$measure][$bucket] += (float) $line->{$column};
            }
        }

        return $facts;
    }

    /**
     * Строки рабочих по этим позициям. Строка бригадира сюда не попадает —
     * это тот же объём смены, посчитанный второй раз ради его бонуса.
     *
     * @param  array<int, int>  $itemIds
     * @return Collection<int, WorkOrderLine>
     */
    private function workerLines(array $itemIds): Collection
    {
        return WorkOrderLine::query()
            ->where('role', 'worker')
            ->whereHas('order', fn ($q) => $q->whereIn('deal_item_id', $itemIds))
            ->with(['order:id,deal_item_id,brigade_id,status', 'order.brigade:id,name'])
            ->get(['id', 'work_order_id', 'qty_pcs', 'qty_m2']);
    }
}
