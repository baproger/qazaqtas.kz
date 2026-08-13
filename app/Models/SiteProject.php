<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Реализованный объект: витрина показывает его фото на главной и в «Проектах». */
class SiteProject extends Model
{
    use HasTranslations;

    protected static function translatable(): array
    {
        return ['title', 'city', 'products', 'description'];
    }

    protected $fillable = [
        'title', 'city', 'year', 'area', 'products', 'description',
        'image', 'thumb', 'order', 'is_active',
    ];

    protected $casts = ['is_active' => 'boolean'];

    public function translations(): HasMany
    {
        return $this->hasMany(SiteProjectTranslation::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /** Для скролл-истории главной годятся только объекты с фотографией. */
    public function scopeWithPhoto($query)
    {
        return $query->whereNotNull('image');
    }
}
