<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Позиция каталога. Ведётся в ERP (Каталог), сайт её только читает —
 * никакой отдельной базы товаров у витрины нет.
 */
class Product extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'category_id', 'name', 'slug', 'code', 'unit', 'price', 'old_price', 'min_order',
        'description', 'short_description', 'specs', 'colors', 'images', 'documents',
        'texture_path', 'model_path',
        'is_service', 'is_active', 'is_featured', 'in_stock', 'order',
    ];

    protected $casts = [
        'specs' => 'array',
        'colors' => 'array',
        'images' => 'array',
        'documents' => 'array',
        'price' => 'decimal:2',
        'old_price' => 'decimal:2',
        'min_order' => 'decimal:2',
        'is_service' => 'boolean',
        'is_active' => 'boolean',
        'is_featured' => 'boolean',
        'in_stock' => 'boolean',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Штук в квадратном метре — из характеристик. Нужен калькулятору площади
     * и конфигуратору: площадь × шт/м² = количество плитки.
     */
    public function piecesPerM2(): ?float
    {
        $value = (float) ($this->specs['pieces_per_m2'] ?? 0);

        return $value > 0 ? $value : null;
    }

    /** Фото-текстура для 3D-сцен; null — сцена красит изделие цветом. */
    public function texture(): ?string
    {
        return $this->texture_path ?: null;
    }

    /** Первый цвет считается основным — им рисуется превью и 3D-сцена. */
    public function primaryColor(): string
    {
        return $this->colors[0]['hex'] ?? '#9CA3AF';
    }

    protected static function booted(): void
    {
        static::saving(function (self $product) {
            // Транслитерация: «Плитка «Квадрат» 300×300» → plitka-kvadrat-300x300.
            $product->slug = $product->slug ?: Str::slug($product->name, '-', 'ru') ?: Str::random(8);
        });
    }
}
