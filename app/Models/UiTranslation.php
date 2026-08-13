<?php

namespace App\Models;

use App\Support\Locales;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Lang;

/**
 * Строка интерфейса, переопределённая владельцем через ERP → Переводы.
 *
 * Базовые тексты лежат в `lang/{kk,ru}/*.php` и едут вместе с кодом, а эта
 * таблица их ПЕРЕКРЫВАЕТ: владелец правит формулировку в админке, не трогая
 * репозиторий. Поэтому кэшируется только слой правок — файлы читаются как
 * есть, и после деплоя новый текст виден сразу, без чистки кэша.
 */
class UiTranslation extends Model
{
    /** Файлы словаря: `site.title` → lang/{locale}/site.php, ключ `title`. */
    private const GROUPS = ['site', 'erp', 'app'];

    /** Понятные названия групп для страницы «Переводы». */
    public const GROUP_LABELS = [
        'site' => 'Сайт',
        'erp' => 'Интерфейс ERP',
        'app' => 'Общее',
    ];

    /** @return array<int, string> */
    public static function groups(): array
    {
        return self::GROUPS;
    }

    /**
     * Группы, которые подстраховываются запасным языком.
     *
     * У витрины и общих строк ключ ничего не значит (`site.nav.catalog`), и
     * показать вместо пропущенного перевода текст на другом языке лучше, чем
     * голый ключ. У интерфейса ERP ключом служит сам русский текст, и такая
     * подстраховка привела бы к обратному: на русском интерфейсе выскочил бы
     * казахский. Там запасной вариант — сам ключ, и его подставляет $e().
     */
    private const FALLBACK_GROUPS = ['site', 'app'];

    /** Разобранные файлы словаря в пределах запроса. */
    private static array $files = [];

    protected $fillable = ['key', 'group', 'ru', 'kk'];

    /**
     * Плоская карта [ключ => текст] для языка.
     *
     * Слои, сверху вниз: правки владельца → файлы этого языка → файлы
     * запасного языка. Последний слой нужен, чтобы недопереведённая строка
     * показывала текст на другом языке, а не голый ключ.
     */
    public static function map(string $locale): array
    {
        $locale = Locales::supported($locale) ? $locale : Locales::default();

        return static::overrides($locale)
            + static::fileStrings($locale)
            + static::fileStrings(Locales::FALLBACK, self::FALLBACK_GROUPS);
    }

    /** Слой правок из админки (кэшируется, сбрасывается при сохранении). */
    public static function overrides(string $locale): array
    {
        return Cache::rememberForever("ui_translations.$locale", function () use ($locale) {
            return static::all(['key', ...Locales::ALL])
                ->mapWithKeys(fn ($t) => [$t->key => (string) ($t->{$locale} ?: '')])
                ->filter(fn ($value) => $value !== '')
                ->toArray();
        });
    }

    /** Плоский словарь из lang-файлов языка. */
    private static function fileStrings(string $locale, ?array $groups = null): array
    {
        $groups ??= self::GROUPS;
        $cacheKey = $locale.':'.implode(',', $groups);

        if (isset(static::$files[$cacheKey])) {
            return static::$files[$cacheKey];
        }

        $out = [];

        foreach ($groups as $group) {
            $lines = Lang::get($group, [], $locale);

            // Отсутствующий файл Laravel возвращает как само имя группы.
            if (! is_array($lines)) {
                continue;
            }

            foreach (Arr::dot($lines) as $key => $value) {
                if (is_string($value)) {
                    $out["$group.$key"] = $value;
                }
            }
        }

        return static::$files[$cacheKey] = $out;
    }

    public static function flushCache(): void
    {
        foreach (Locales::ALL as $locale) {
            Cache::forget("ui_translations.$locale");
        }

        static::$files = [];
    }

    protected static function booted(): void
    {
        static::saved(fn () => static::flushCache());
        static::deleted(fn () => static::flushCache());
    }
}
