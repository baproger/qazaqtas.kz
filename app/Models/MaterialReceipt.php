<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Приход товара на склад (история пополнений). */
class MaterialReceipt extends Model
{
    protected $fillable = ['material_id', 'user_id', 'quantity', 'price', 'date', 'note'];

    protected $casts = ['quantity' => 'decimal:2', 'price' => 'decimal:2', 'date' => 'date'];

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
