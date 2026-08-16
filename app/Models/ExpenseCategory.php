<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExpenseCategory extends Model
{
    /**
     * Служебные категории: несут логику, а не только название.
     *
     * EMPLOYEE — авансы, долги и выплата ЗП. Исключается из ИТОГА «Расходы»
     * (зарплата стоит там отдельной строкой, иначе двойной счёт), но кассу
     * уменьшает честно.
     * MATERIALS_PURCHASE — оплата закупа при приходе на склад.
     *
     * Искать их по имени нельзя: имя владелец правит из админки. Категорию
     * с кодом нельзя переименовать и удалить — на ней держатся расчёты.
     */
    public const EMPLOYEE = 'employee';

    public const MATERIALS_PURCHASE = 'materials_purchase';

    protected $fillable = ['code', 'name', 'parent_id', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public static function findByCode(string $code): ?self
    {
        return static::where('code', $code)->first();
    }

    /** Служебную категорию нельзя переименовать и удалить. */
    public function isSystem(): bool
    {
        return $this->code !== null;
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(ExpenseCategory::class, 'parent_id');
    }

    public function translations(): HasMany
    {
        return $this->hasMany(ExpenseCategoryTranslation::class);
    }
}
