<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Сколько объёма в строку плана принесла конкретная сделка.
 *
 * По этим записям карточка сделки понимает, что «уже отправлено», — и не
 * даёт нажать «Добавить недостающее» второй раз, даже когда строку очереди
 * завела другая сделка на тот же товар.
 */
class ProductionPlanDeal extends Model
{
    protected $fillable = ['production_plan_id', 'deal_id', 'qty'];

    protected $casts = ['qty' => 'decimal:2'];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(ProductionPlan::class, 'production_plan_id');
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }
}
