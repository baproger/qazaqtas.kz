<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceCategory extends Model
{
    use HasTranslations;

    protected $fillable = ['name', 'slug', 'sort', 'is_active'];

    protected static function translatable(): array
    {
        return ['name'];
    }

    public function translations(): HasMany
    {
        return $this->hasMany(ServiceCategoryTranslation::class);
    }

    protected $casts = ['is_active' => 'boolean'];

    public function services(): HasMany
    {
        return $this->hasMany(Service::class, 'category_id');
    }
}
