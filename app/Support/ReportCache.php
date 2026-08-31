<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Кеш тяжёлых отчётов (Аналитика, Сводный отчёт, Зарплата).
 *
 * Эти страницы считают по всем сделкам и платежам за период — на
 * шаред-хостинге пара таких запросов подряд занимает все PHP-процессы, и
 * остальные получают 503. Результат живёт 5 минут; любое изменение денег
 * (сделка, счёт, платёж, расход, наряд, бонус, корректировка ЗП, настройки)
 * сдвигает версию — и все отчёты пересчитываются заново при следующем
 * открытии. Ключ включает пользователя и фильтры: каждый видит своё.
 */
final class ReportCache
{
    public const TTL = 300;

    /** @return array<string, mixed> */
    public static function remember(Request $request, string $report, \Closure $build): array
    {
        $key = implode(':', [
            'report', $report, self::version(),
            (string) CurrentCompany::id(), (string) $request->user()?->id,
            md5(json_encode($request->query())),
        ]);

        // Результат приводится к чистым массивам ДО записи: Collection внутри
        // кеша при unserialize может вернуться как __PHP_Incomplete_Class —
        // страница тогда получает мусор вместо данных и падает белым экраном.
        $fresh = fn () => json_decode(json_encode($build()), true);

        $data = Cache::remember($key, self::TTL, $fresh);

        // Страховка от записей, сделанных до этой нормализации.
        if (! is_array($data) || str_contains(serialize($data), '__PHP_Incomplete_Class')) {
            Cache::forget($key);
            $data = Cache::remember($key, self::TTL, $fresh);
        }

        return $data;
    }

    /** Деньги изменились — все отчёты устарели. */
    public static function bump(): void
    {
        Cache::put('report:version', (int) (microtime(true) * 1000), 86400 * 30);
    }

    public static function version(): int
    {
        return (int) Cache::get('report:version', 0);
    }
}
