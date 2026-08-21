<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Долг сотрудника перед компанией.
 *
 * Отличается от аванса тем, что переходит из месяца в месяц и гасится
 * ТОЛЬКО из бонуса: нет бонуса в месяце — удержания нет, остаток целиком
 * едет дальше. Оклад долг не трогает никогда.
 */
class EmployeeDebt extends Model
{
    use Auditable;

    protected $fillable = [
        'user_id', 'company_id', 'amount', 'monthly_payment',
        'payment_method', 'expense_id', 'note', 'closed_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'monthly_payment' => 'decimal:2',
        'closed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(EmployeeDebtPayment::class);
    }

    /** Сколько осталось. Считается по погашениям, отдельной колонки нет. */
    public function balance(): float
    {
        return round((float) $this->amount - (float) $this->payments()->sum('amount'), 2);
    }

    public function isClosed(): bool
    {
        return $this->closed_at !== null;
    }

    public function scopeOpen($query)
    {
        return $query->whereNull('closed_at');
    }
}
