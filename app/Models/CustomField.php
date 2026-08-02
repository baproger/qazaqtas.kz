<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomField extends Model
{
    protected $fillable = ['entity_type', 'name', 'type', 'required', 'unique', 'is_visible', 'options', 'order'];

    protected $casts = [
        'required' => 'boolean',
        'unique' => 'boolean',
        'is_visible' => 'boolean',
        'options' => 'array',
    ];

    public function translations(): HasMany
    {
        return $this->hasMany(CustomFieldTranslation::class);
    }

    public function values(): HasMany
    {
        return $this->hasMany(CustomFieldValue::class);
    }
}
