<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Остаток товара на складе фирмы.
 *
 * Денормализация ради скорости: остаток спрашивают в списке товаров при
 * каждом открытии, а считать его суммой всех движений каждый раз — дорого.
 * Пишется в одной транзакции с движением; расхождение ловит `stock:selfcheck`.
 */
class ProductStock extends Model
{
    protected $fillable = ['product_id', 'company_id', 'qty'];

    protected $casts = ['qty' => 'decimal:2'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
