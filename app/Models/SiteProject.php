<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Реализованный объект: витрина показывает его фото на главной и в «Проектах». */
class SiteProject extends Model
{
    protected $fillable = [
        'title', 'city', 'year', 'area', 'products', 'description',
        'image', 'thumb', 'order', 'is_active',
    ];

    protected $casts = ['is_active' => 'boolean'];

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
