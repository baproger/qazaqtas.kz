<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Перевод карточки товара: название, описания, значения характеристик, названия цветов. */
class ProductTranslation extends Model
{
    protected $fillable = ['product_id', 'locale', 'name', 'short_description', 'description', 'specs', 'colors'];

    protected $casts = ['specs' => 'array', 'colors' => 'array'];
}
