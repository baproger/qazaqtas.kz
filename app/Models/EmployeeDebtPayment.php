<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Погашение долга за месяц.
 *
 * Уникальный ключ (долг, месяц) в схеме — на нём держится идемпотентность
 * команды `debts:charge`: повторный прогон не спишет второй раз, потому что
 * не даст база, а не потому что так написано условие в коде.
 */
class EmployeeDebtPayment extends Model
{
    use Auditable;

    protected $fillable = ['employee_debt_id', 'month', 'amount'];

    protected $casts = ['amount' => 'decimal:2'];

    public function debt(): BelongsTo
    {
        return $this->belongsTo(EmployeeDebt::class, 'employee_debt_id');
    }
}
