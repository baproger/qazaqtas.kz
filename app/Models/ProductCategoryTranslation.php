<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Перевод раздела каталога: название, подзаголовок, описание, выноски на витрине. */
class ProductCategoryTranslation extends Model
{
    protected $fillable = ['product_category_id', 'locale', 'name', 'tagline', 'description', 'specs'];

    protected $casts = ['specs' => 'array'];
}
