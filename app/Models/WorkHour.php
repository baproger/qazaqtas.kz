<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Отработанные часы сотрудника за месяц (YYYY-MM) — вводит бухгалтер/админ
 * на странице ЗП. Начислено по окладу = часы × (оклад ÷ норма часов месяца,
 * Setting "work_norm_{YYYY-MM}"). Записи нет — платится полный оклад.
 */
class WorkHour extends Model
{
    protected $fillable = ['user_id', 'month', 'hours', 'created_by'];

    protected $casts = ['hours' => 'decimal:2'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
