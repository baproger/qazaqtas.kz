<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\WorkOrder;
use App\Models\WorkOrderLine;
use Illuminate\Support\Facades\DB;

/**
 * Бонус производства: деньги за сделанный объём.
 *
 * Рабочий получает за СВОЙ объём, бригадир — за объём всей смены (правило
 * владельца от 21.08.2026). Поэтому у наряда две породы строк: строки
 * рабочих и одна строка бригадира на весь наряд.
 *
 * Ставки берутся из настроек и КОПИРУЮТСЯ в строку: цену за метр поднимут, а
 * прошлые наряды пересчитываться не должны — иначе выплаченная зарплата
 * начинает меняться задним числом.
 */
class ProductionBonusService
{
    /** @return array{pcs: float, m2: float} ставки роли */
    public function rates(string $role): array
    {
        return $role === 'foreman'
            ? [
                'pcs' => (float) Setting::get('foreman_rate_pcs', 35),
                'm2' => (float) Setting::get('foreman_rate_m2', 450),
            ]
            : [
                // Ставку рабочего задаёт владелец: выдумывать её нельзя,
                // поэтому по умолчанию ноль и подпись в настройках.
                'pcs' => (float) Setting::get('worker_rate_pcs', 0),
                'm2' => (float) Setting::get('worker_rate_m2', 0),
            ];
    }

    private function lineAmount(float $pcs, float $m2, array $rates): float
    {
        return round($pcs * $rates['pcs'] + $m2 * $rates['m2'], 2);
    }

    /**
     * Переписать строки наряда: объём по рабочим + строка бригадира на всю
     * смену.
     *
     * @param  array<int, array<string, mixed>>  $rows  [[user_id, qty_pcs, qty_m2]]
     */
    public function syncLines(WorkOrder $order, array $rows): void
    {
        $workerRates = $this->rates('worker');
        $foremanRates = $this->rates('foreman');

        DB::transaction(function () use ($order, $rows, $workerRates, $foremanRates) {
            $order->lines()->delete();

            $totalPcs = 0.0;
            $totalM2 = 0.0;

            foreach ($rows as $row) {
                $pcs = round((float) ($row['qty_pcs'] ?? 0), 2);
                $m2 = round((float) ($row['qty_m2'] ?? 0), 2);
                if ($pcs <= 0 && $m2 <= 0) {
                    continue;   // пустая строка — не выработка
                }

                $totalPcs += $pcs;
                $totalM2 += $m2;

                $order->lines()->create([
                    'user_id' => $row['user_id'] ?? null,
                    'qty_pcs' => $pcs,
                    'qty_m2' => $m2,
                    'rate_pcs' => $workerRates['pcs'],
                    'rate_m2' => $workerRates['m2'],
                    'amount' => $this->lineAmount($pcs, $m2, $workerRates),
                    'role' => 'worker',
                ]);
            }

            // Бригадир получает за ВЕСЬ объём смены — отдельной строкой, а не
            // долей: так видно, за что именно ему начислено.
            $foremanId = $order->brigade?->foreman_id;
            if ($foremanId && ($totalPcs > 0 || $totalM2 > 0)) {
                $order->lines()->create([
                    'user_id' => $foremanId,
                    'qty_pcs' => $totalPcs,
                    'qty_m2' => $totalM2,
                    'rate_pcs' => $foremanRates['pcs'],
                    'rate_m2' => $foremanRates['m2'],
                    'amount' => $this->lineAmount($totalPcs, $totalM2, $foremanRates),
                    'role' => 'foreman',
                ]);
            }
        });
    }

    /**
     * Начисленный бонус производства по месяцам — для страницы «Бонусы».
     *
     * Считаются ТОЛЬКО подтверждённые наряды: неподтверждённая выработка —
     * ещё не заработок.
     *
     * @param  array<int, string>  $months  YYYY-MM
     * @return array<string, array<int, float>> [месяц => [id сотрудника => сумма]]
     */
    public function accrualsByMonths($userIds, array $months): array
    {
        $result = array_fill_keys($months, []);
        $userIds = collect($userIds)->map(fn ($id) => (int) $id)->unique();
        if ($userIds->isEmpty() || $months === []) {
            return $result;
        }

        WorkOrderLine::query()
            ->whereIn('user_id', $userIds)
            ->whereHas('order', fn ($q) => $q->where('status', 'confirmed'))
            ->with('order:id,date')
            ->get(['id', 'work_order_id', 'user_id', 'amount'])
            ->each(function (WorkOrderLine $line) use (&$result) {
                $month = $line->order?->date?->format('Y-m');
                if ($month === null || ! array_key_exists($month, $result)) {
                    return;
                }
                $uid = (int) $line->user_id;
                $result[$month][$uid] = round(($result[$month][$uid] ?? 0) + (float) $line->amount, 2);
            });

        return $result;
    }

    /**
     * Итоги наряда — для страницы производства.
     *
     * @return array{pcs: float, m2: float, workers: float, foreman: float}
     */
    public function totals(WorkOrder $order): array
    {
        $lines = $order->relationLoaded('lines') ? $order->lines : $order->lines()->get();
        $workers = $lines->where('role', 'worker');

        return [
            'pcs' => round((float) $workers->sum('qty_pcs'), 2),
            'm2' => round((float) $workers->sum('qty_m2'), 2),
            'workers' => round((float) $workers->sum('amount'), 2),
            'foreman' => round((float) $lines->where('role', 'foreman')->sum('amount'), 2),
        ];
    }
}
