<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** Категория каталога (тротуарная плитка, бордюры, МАФ…). Ведётся в ERP. */
class ProductCategory extends Model
{
    protected $fillable = ['name', 'slug', 'tagline', 'description', 'image', 'accent', 'order', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'category_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    protected static function booted(): void
    {
        static::saving(function (self $category) {
            // Транслитерация: «Плитка «Квадрат» 300×300» → plitka-kvadrat-300x300.
            $category->slug = $category->slug ?: Str::slug($category->name, '-', 'ru') ?: Str::random(8);
        });
    }
}
