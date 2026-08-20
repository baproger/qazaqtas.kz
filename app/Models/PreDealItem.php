<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Позиция заявки (запроса КП).
 *
 * В отличие от позиции сделки хранит ещё и закупочную цену: по ней считается
 * маржа, и без неё заявка не смогла бы ответить на главный вопрос — стоит ли
 * брать заказ.
 */
class PreDealItem extends Model
{
    protected $fillable = [
        'pre_deal_id', 'product_id', 'name', 'unit',
        'quantity', 'price', 'purchase_price', 'amount', 'sort',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'price' => 'decimal:2',
        'purchase_price' => 'decimal:2',
        'amount' => 'decimal:2',
    ];

    public function preDeal(): BelongsTo
    {
        return $this->belongsTo(PreDeal::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
