<?php

namespace App\Services;

use App\Models\Deal;
use App\Models\ProductionPlan;
use App\Models\User;
use App\Notifications\ProductionPlanQueued;
use Illuminate\Support\Facades\DB;

/**
 * Нехватка со склада → задание цеху, одним нажатием.
 *
 * Менеджер собрал сделку, товара на складе не хватило — он жмёт «Добавить
 * недостающее в план производства», и объём попадает в «План — факт» сразу,
 * без промежуточной заявки. Заявка была бы ещё одной сущностью, которую надо
 * кому-то разбирать, а разбирает её всё равно тот же начальник производства.
 *
 * План рождается БЕЗ бригады: менеджер не знает, кто сейчас свободен в цехе.
 * Бригаду назначает начальник производства — до этого план стоит в очереди.
 */
class ProductionPlanService
{
    /**
     * Дописать нехватку в план месяца.
     *
     * СКЛАДЫВАЕМ, а не создаём вторую строку: две сделки на один товар — это
     * один объём для цеха. Иначе очередь распухла бы одинаковыми строками, а
     * процент выполнения считался бы по каждой отдельно.
     *
     * @param  array<int, array{product_id: int, qty: float, unit: ?string}>  $rows
     * @return array<int, ProductionPlan>
     */
    public function addShortage(Deal $deal, array $rows, ?User $author = null): array
    {
        $month = now()->startOfMonth()->toDateString();
        $created = [];

        DB::transaction(function () use ($deal, $rows, $month, $author, &$created) {
            foreach ($rows as $row) {
                $qty = round((float) $row['qty'], 2);
                if ($qty <= 0) {
                    continue;
                }

                // Блокируем нераспределённые планы этого товара на месяц:
                // два менеджера, нажавшие кнопку одновременно, должны сложить
                // объём в одну строку, а не создать две.
                // whereDate, а не точное сравнение: колонка приводится к дате,
                // и строка '2026-08-01' не совпала бы со значением со временем —
                // сложение молча превращалось бы во вторую строку очереди.
                $plan = ProductionPlan::whereDate('period_month', $month)
                    ->where('product_id', $row['product_id'])
                    ->whereNull('brigade_id')
                    ->when($deal->company_id, fn ($q, $c) => $q->where('company_id', $c))
                    ->lockForUpdate()
                    ->first();

                if ($plan) {
                    $plan->increment('plan_qty', $qty);
                    $plan->refresh();
                } else {
                    $plan = ProductionPlan::create([
                        'company_id' => $deal->company_id,
                        'period_month' => $month,
                        'brigade_id' => null,
                        'product_id' => $row['product_id'],
                        'deal_id' => $deal->id,
                        'plan_qty' => $qty,
                        'unit' => $row['unit'] ?? null,
                        'status' => 'active',
                        'created_by' => $author?->id,
                    ]);
                }

                $created[] = $plan;
            }
        });

        if ($created !== []) {
            $this->notify($deal, $created);
        }

        return $created;
    }

    /**
     * Кому уходит весть: директор, начальник производства и ассистент.
     *
     * Бригадиру не шлём: план ещё ничей, бригаду ему только назначат — иначе
     * каждый бригадир получал бы весть о чужой работе.
     *
     * @param  array<int, ProductionPlan>  $plans
     */
    private function notify(Deal $deal, array $plans): void
    {
        User::role(['admin', 'director', 'production_head', 'assistant'])
            ->where('is_active', true)
            ->get()
            ->each->notify(new ProductionPlanQueued($deal, $plans));
    }
}
