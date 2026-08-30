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

        return Cache::remember($key, self::TTL, $build);
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
