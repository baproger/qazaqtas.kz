<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Единый сборщик SEO-тегов страницы.
 *
 * Приоритет: ручные метаданные модели (seo_meta) → переданные значения →
 * автогенерация из контента (без HTML, описание режется до 160 символов по
 * границе слова). Выход читает SiteLayout: title, description, og:*, canonical.
 */
final class Seo
{
    /** @return array{title: string, description: string, keywords: ?string, image: ?string, canonical: ?string} */
    public static function for(?Model $model, string $title, ?string $description = null, ?string $image = null, ?string $canonical = null): array
    {
        $manual = $model && method_exists($model, 'seoMeta') ? $model->seoMeta : null;
        // Ручные метаданные знают язык страницы: kk с фолбэком на ru.
        $localized = $manual?->forLocale(app()->getLocale()) ?? [];

        return [
            'title' => self::text(($localized['title'] ?? null) ?: $title, 70),
            'description' => self::text(($localized['description'] ?? null) ?: $description ?: '', 160),
            'keywords' => ($localized['keywords'] ?? null) ?: null,
            'image' => $manual?->og_image ?: $image,
            'canonical' => $canonical,
        ];
    }

    /** Срез без HTML по границе слова. */
    public static function text(?string $value, int $limit): string
    {
        $plain = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $value)) ?? '');

        return Str::limit($plain, $limit, '…');
    }

    /** ЧПУ с транслитерацией, уникальный в пределах таблицы модели. */
    public static function slug(string $title, string $model, ?int $ignoreId = null): string
    {
        $base = Str::slug($title) ?: 'item';
        $slug = $base;
        for ($i = 2; $model::where('slug', $slug)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }
}
