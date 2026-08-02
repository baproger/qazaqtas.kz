<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Задолженность: receivable — нам должны (дебиторка), payable — мы должны (кредиторка). */
class Debt extends Model
{
    public const TYPES = ['receivable', 'payable'];

    protected $fillable = ['company_id', 'type', 'counterparty', 'amount', 'date', 'note', 'created_by'];

    protected $casts = [
        'amount' => 'decimal:2',
        'date' => 'date',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
