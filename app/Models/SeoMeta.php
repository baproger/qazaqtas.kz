<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** Ручные SEO-метаданные страницы; пустые поля добирает автогенерация (Support\Seo). */
class SeoMeta extends Model
{
    protected $table = 'seo_meta';

    protected $fillable = ['seoable_type', 'seoable_id', 'title', 'description', 'og_image'];

    public function seoable(): MorphTo
    {
        return $this->morphTo();
    }
}
