<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Штамп «у пользователя что-то изменилось» — в кеше, не в БД.
 *
 * Опрос с браузера читает одно значение из кеша; БД не трогается вовсе.
 * Штамп двигают события: сохранение задачи (исполнитель и автор) и новое
 * уведомление. Так сотня открытых вкладок стоит серверу ровно столько,
 * сколько сотня чтений из кеша.
 */
final class LiveStamp
{
    private const TTL = 86400 * 7;

    /** @return array{tasks: int, notifications: int} */
    public static function get(int $userId): array
    {
        return Cache::get(self::key($userId)) ?? ['tasks' => 0, 'notifications' => 0];
    }

    public static function bump(int|array|null $userIds, string $what): void
    {
        foreach (array_unique(array_filter((array) $userIds)) as $id) {
            $stamp = self::get((int) $id);
            $stamp[$what] = (int) (microtime(true) * 1000);
            Cache::put(self::key((int) $id), $stamp, self::TTL);
        }
    }

    private static function key(int $userId): string
    {
        return 'live:'.$userId;
    }
}
