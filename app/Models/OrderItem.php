<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Строка заказа с сайта: снимок позиции на момент оформления. */
class OrderItem extends Model
{
    protected $fillable = ['order_id', 'product_id', 'name', 'unit', 'color', 'price', 'quantity', 'sum'];

    protected $casts = ['price' => 'decimal:2', 'quantity' => 'decimal:2', 'sum' => 'decimal:2'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
