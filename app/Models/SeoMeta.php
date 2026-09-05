<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** Ручные SEO-метаданные страницы; пустые поля добирает автогенерация (Support\Seo). */
class SeoMeta extends Model
{
    protected $table = 'seo_meta';

    protected $fillable = [
        'seoable_type', 'seoable_id', 'og_image',
        'title', 'description', 'keywords',
        'title_kk', 'description_kk', 'keywords_kk',
    ];

    /**
     * Метаданные для языка страницы: kk с фолбэком на ru — незаполненный
     * перевод не должен оставить страницу без метатегов вовсе.
     *
     * @return array{title: ?string, description: ?string, keywords: ?string}
     */
    public function forLocale(string $locale): array
    {
        $kk = $locale === 'kk';

        return [
            'title' => ($kk ? $this->title_kk : null) ?: $this->title,
            'description' => ($kk ? $this->description_kk : null) ?: $this->description,
            'keywords' => ($kk ? $this->keywords_kk : null) ?: $this->keywords,
        ];
    }

    public function seoable(): MorphTo
    {
        return $this->morphTo();
    }
}
