<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Приход товара на склад (история пополнений). */
class MaterialReceipt extends Model
{
    use Auditable;

    protected $fillable = ['material_id', 'user_id', 'expense_id', 'quantity', 'price', 'date', 'note'];

    protected $casts = ['quantity' => 'decimal:2', 'price' => 'decimal:2', 'date' => 'date'];

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    /** Расход-оплата закупа (нал/банк). Пусто — приход без оплаты. */
    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
