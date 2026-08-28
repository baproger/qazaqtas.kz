<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * План производства на месяц: бригада, товар, объём.
 *
 * Задание цеху между заказами клиентов. Ставит директор, выполняет бригада,
 * подтверждает директор или финансист. Выполнение считается по нарядам,
 * привязанным к этому плану, — второго счётчика нет.
 */
class ProductionPlan extends Model
{
    use Auditable;

    protected $fillable = [
        'company_id', 'period_month', 'brigade_id', 'product_id', 'deal_id',
        'plan_qty', 'unit', 'bonus_rate', 'status', 'note', 'created_by',
    ];

    protected $casts = [
        'period_month' => 'date',
        'plan_qty' => 'decimal:2',
        'bonus_rate' => 'decimal:2',
    ];

    public function brigade(): BelongsTo
    {
        return $this->belongsTo(Brigade::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Наряды по этому плану: из них считается выполнение. */
    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class);
    }

    /**
     * Правится план, пока по нему нет ПОДТВЕРЖДЁННОЙ выработки.
     *
     * После подтверждения задание уже стало деньгами: сменишь объём — и
     * процент выполнения, по которому платили, начнёт меняться задним числом.
     */
    public function isEditable(): bool
    {
        return ! $this->workOrders()->where('status', 'confirmed')->exists();
    }

    /** Месяц плана в виде YYYY-MM — им фильтруется страница. */
    public function month(): string
    {
        return $this->period_month->format('Y-m');
    }
}
