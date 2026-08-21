<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Выплата бонуса за конкретный месяц.
 *
 * Месяц здесь — тот, ЗА который бонус заработан, а не когда его выдали:
 * сотрудник может забрать в декабре бонус, накопленный с июня.
 */
class BonusPayout extends Model
{
    protected $fillable = [
        'user_id', 'company_id', 'month', 'amount',
        'payment_method', 'expense_id', 'paid_by', 'note',
    ];

    protected $casts = ['amount' => 'decimal:2'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }
}
