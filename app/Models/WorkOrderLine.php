<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Строка наряда: сколько сделал конкретный человек.
 *
 * Ставки хранятся снимком — прошлые наряды не должны пересчитываться, когда
 * владелец поднимает цену за метр.
 */
class WorkOrderLine extends Model
{
    use Auditable;

    protected $fillable = [
        'work_order_id', 'user_id', 'qty_pcs', 'qty_m2',
        'rate_pcs', 'rate_m2', 'amount', 'role',
    ];

    protected $casts = [
        'qty_pcs' => 'decimal:2',
        'qty_m2' => 'decimal:2',
        'rate_pcs' => 'decimal:2',
        'rate_m2' => 'decimal:2',
        'amount' => 'decimal:2',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class, 'work_order_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
