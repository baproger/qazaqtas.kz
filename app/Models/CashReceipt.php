<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Поступление денег (нал/банк): вводит финансист на странице Финансы. */
class CashReceipt extends Model
{
    protected $fillable = ['company_id', 'amount', 'method', 'source', 'date', 'note', 'created_by'];

    protected $casts = [
        'amount' => 'decimal:2',
        'date' => 'date',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
