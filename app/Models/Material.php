<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Позиция склада (у каждой компании свой склад). quantity — текущий остаток:
 * приход увеличивает, расход по материалам из сделки (этап 3) списывает.
 */
class Material extends Model
{
    protected $fillable = ['company_id', 'name', 'unit', 'quantity', 'price', 'note'];

    protected $casts = ['quantity' => 'decimal:2', 'price' => 'decimal:2'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(MaterialReceipt::class);
    }

    public function scopeForCurrentCompany($query)
    {
        return $query->when(\App\Support\CurrentCompany::id(), fn ($q, $c) => $q->where('company_id', $c));
    }
}
