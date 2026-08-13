<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Http\Request;

/**
 * Языки системы: казахский основной, русский второй.
 *
 * Единственное место, где перечислены языки, — отсюда их читают middleware,
 * валидация, витрина и админка. Основной язык витрины настраивается в ERP
 * (Настройки → Общие, «Язык по умолчанию»), поэтому он не константа, а
 * значение настройки: на нём открывается голый адрес (`/katalog`), а второй
 * язык живёт под префиксом (`/ru/katalog`).
 */
class Locales
{
    /** Поддерживаемые языки. Порядок = порядок в переключателе. */
    public const ALL = ['kk', 'ru'];

    /** Запасной язык: используется, когда настройка испорчена или пуста. */
    public const FALLBACK = 'kk';

    /** Названия языков на них самих — так их читает переключатель. */
    public const NAMES = ['kk' => 'Қазақша', 'ru' => 'Русский'];

    /**
     * Короткие подписи для переключателя.
     *
     * Код языка по стандарту — `kk`, и на нём держатся адреса, словари и
     * атрибут `lang`. А в кнопке стоит «KZ»: посетитель читает её как страну,
     * и «KK» рядом с «RU» выглядит опечаткой. Подпись и код здесь намеренно
     * разные — менять сам код ради вида нельзя.
     */
    public const SHORT = ['kk' => 'KZ', 'ru' => 'RU'];

    public static function supported(mixed $locale): bool
    {
        return is_string($locale) && in_array($locale, self::ALL, true);
    }

    /**
     * Язык по умолчанию — тот, что владелец выбрал в настройках.
     * Он же язык без префикса на витрине.
     */
    public static function default(): string
    {
        $setting = Setting::get('default_locale', self::FALLBACK);

        return self::supported($setting) ? $setting : self::FALLBACK;
    }

    /**
     * Языки для вкладок в формах ERP: код, подпись и пометка основного.
     * Основной идёт первым — с него владелец и начинает заполнять карточку.
     */
    public static function forForm(): array
    {
        $default = self::default();

        return collect(self::ALL)
            ->sortByDesc(fn (string $locale) => $locale === $default)
            ->map(fn (string $locale) => [
                'code' => $locale,
                'name' => self::NAMES[$locale],
                'short' => self::SHORT[$locale],
                'is_default' => $locale === $default,
            ])
            ->values()
            ->all();
    }

    /** Языки, кроме основного: именно они получают префикс в адресе. */
    public static function prefixed(): array
    {
        return array_values(array_diff(self::ALL, [self::default()]));
    }

    /**
     * Имя маршрута витрины для языка: основной язык живёт без префикса,
     * остальные — под своим (`ru.site.catalog`).
     */
    public static function routeName(string $base, string $locale): string
    {
        return $locale === self::default() ? $base : "$locale.$base";
    }

    /**
     * Обратное преобразование: `ru.site.catalog` → `site.catalog`.
     * Возвращает null, если маршрут не витринный, — по нему и отличаем ERP.
     */
    public static function baseRouteName(?string $name): ?string
    {
        if (! $name) {
            return null;
        }

        foreach (self::ALL as $locale) {
            if (str_starts_with($name, "$locale.site.")) {
                return substr($name, strlen($locale) + 1);
            }
        }

        return str_starts_with($name, 'site.') ? $name : null;
    }

    /** Язык, зашитый в имя маршрута; для маршрута без префикса — основной. */
    public static function fromRouteName(?string $name): ?string
    {
        if (self::baseRouteName($name) === null) {
            return null;
        }

        foreach (self::ALL as $locale) {
            if (str_starts_with((string) $name, "$locale.site.")) {
                return $locale;
            }
        }

        return self::default();
    }

    /**
     * Адреса текущей страницы на всех языках — для переключателя и hreflang.
     * Пусто, если запрос не витринный: в ERP язык выбирается профилем, а не
     * адресом.
     */
    public static function alternates(Request $request): array
    {
        $route = $request->route();
        $base = self::baseRouteName($route?->getName());

        if ($base === null) {
            return [];
        }

        $params = $route->parameters();
        $query = $request->query();
        $urls = [];

        foreach (self::ALL as $locale) {
            $url = route(self::routeName($base, $locale), $params);
            $urls[$locale] = $query ? $url.'?'.http_build_query($query) : $url;
        }

        return $urls;
    }
}
