<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Позиция склада (у каждой компании свой склад). quantity — текущий остаток:
 * приход увеличивает, расход по материалам из сделки (этап 3) списывает.
 */
class Material extends Model
{
    use Auditable;

    protected $fillable = ['company_id', 'name', 'unit', 'quantity', 'price', 'markup_pct', 'note'];

    protected $casts = ['quantity' => 'decimal:2', 'price' => 'decimal:2', 'markup_pct' => 'decimal:2'];

    /**
     * Наценка позиции в процентах. Пусто — действует общая из настроек:
     * владелец задаёт её один раз, а по отдельным товарам правит точечно.
     */
    public function markup(): float
    {
        return $this->markup_pct !== null
            ? (float) $this->markup_pct
            : (float) Setting::get('material_markup_percent', 0);
    }

    /** Цена продажи = закупочная + наценка. */
    public function salePrice(): float
    {
        return round((float) $this->price * (1 + $this->markup() / 100), 2);
    }

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
        return $query->when(CurrentCompany::id(), fn ($q, $c) => $q->where('company_id', $c));
    }
}
