<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class UiTranslation extends Model
{
    protected $fillable = ['key', 'group', 'ru', 'kk'];

    /** Flat [key => value] map for a locale (cached; falls back to ru when a value is empty). */
    public static function map(string $locale): array
    {
        $locale = in_array($locale, ['ru', 'kk'], true) ? $locale : 'ru';

        return Cache::rememberForever("ui_translations.$locale", function () use ($locale) {
            return static::all(['key', 'ru', 'kk'])
                ->mapWithKeys(fn ($t) => [$t->key => ($t->{$locale} ?: $t->ru ?: $t->key)])
                ->toArray();
        });
    }

    public static function flushCache(): void
    {
        Cache::forget('ui_translations.ru');
        Cache::forget('ui_translations.kk');
    }

    protected static function booted(): void
    {
        static::saved(fn () => static::flushCache());
        static::deleted(fn () => static::flushCache());
    }
}
