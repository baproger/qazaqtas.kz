<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Позиция каталога. Ведётся в ERP (Каталог), сайт её только читает —
 * никакой отдельной базы товаров у витрины нет.
 */
class Product extends Model
{
    use HasTranslations, SoftDeletes;

    /** Что видит покупатель — то и переводится; коды, цены и файлы общие. */
    protected static function translatable(): array
    {
        return ['name', 'short_description', 'description', 'specs', 'colors'];
    }

    protected $fillable = [
        'category_id', 'name', 'slug', 'code', 'unit', 'price', 'old_price', 'min_order',
        'description', 'short_description', 'specs', 'colors', 'images', 'documents',
        'texture_path', 'model_path',
        'is_service', 'is_active', 'is_featured', 'in_stock', 'min_stock', 'order',
    ];

    /**
     * Товары для выбора в сделке и заявке: id, название, категория, единица и
     * цена. Один источник на обе страницы — иначе список товаров в заявке и в
     * сделке со временем разъедется.
     */
    public static function catalogForPicker(): \Illuminate\Support\Collection
    {
        return static::active()->orderBy('name')
            ->get(['id', 'category_id', 'name', 'unit', 'price'])
            ->map(fn ($p) => [
                'id' => $p->id,
                'category_id' => $p->category_id,
                'name' => $p->name,
                'unit' => $p->unit,
                'price' => (float) $p->price,
            ]);
    }

    /** Категории, в которых есть активные товары — пустые в выборе не нужны. */
    public static function pickerCategories(): \Illuminate\Support\Collection
    {
        return ProductCategory::whereIn('id', static::active()->select('category_id'))
            ->orderBy('order')->orderBy('name')->get(['id', 'name']);
    }

    protected $casts = [
        'specs' => 'array',
        'colors' => 'array',
        'images' => 'array',
        'documents' => 'array',
        'min_stock' => 'decimal:2',
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

    public function translations(): HasMany
    {
        return $this->hasMany(ProductTranslation::class);
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

    /** Первый цвет считается основным — им рисуется превью карточки. */
    public function primaryColor(): string
    {
        return $this->colors[0]['hex'] ?? '#9CA3AF';
    }

    /**
     * Цвет для 3D-сцены. Первым в палитре обычно идёт «Мрамор белый» — на
     * тёмном фоне он выглядит выцветшим пятном, поэтому берём первый тон
     * средней светлоты, а если такого нет — основной.
     */
    public function sceneColor(): string
    {
        foreach ($this->colors ?? [] as $color) {
            $hex = ltrim((string) ($color['hex'] ?? ''), '#');
            if (strlen($hex) !== 6) {
                continue;
            }
            [$r, $g, $b] = sscanf($hex, '%2x%2x%2x');
            $luminance = ($r * 0.299 + $g * 0.587 + $b * 0.114) / 255;
            if ($luminance > 0.35 && $luminance < 0.78) {
                return '#'.$hex;
            }
        }

        return $this->primaryColor();
    }

    protected static function booted(): void
    {
        static::saving(function (self $product) {
            // Транслитерация: «Плитка «Квадрат» 300×300» → plitka-kvadrat-300x300.
            $product->slug = $product->slug ?: Str::slug($product->name, '-', 'ru') ?: Str::random(8);
        });
    }
}
