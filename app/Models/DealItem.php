<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Позиция сделки: один товар с собственным количеством, единицей и ценой.
 *
 * Название, единица и цена — СНИМОК каталога на момент продажи: товар потом
 * переименуют или переоценят, а сумма закрытой сделки меняться не должна.
 */
class DealItem extends Model
{
    protected $fillable = ['deal_id', 'product_id', 'name', 'unit', 'quantity', 'price', 'amount', 'sort'];

    protected $casts = [
        'quantity' => 'decimal:2',
        'price' => 'decimal:2',
        'amount' => 'decimal:2',
    ];

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
