<?php

namespace App\Support;

use App\Models\Task;
use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Выполненное действие само гасит красный счётчик.
 *
 * Раньше уведомление жило до тех пор, пока получатель не откроет его руками:
 * бухгалтер подтверждал расход, а «ждёт проверки» продолжало висеть у него и
 * у всех остальных бухгалтеров. Счётчик, который не гаснет от работы, люди
 * перестают читать — и пропускают в нём настоящее.
 *
 * Ищем по data: у денежных уведомлений всегда лежит `expense_id`, у задач —
 * `task_id`. Тексты не разбираем: они меняются, а идентификатор — нет.
 */
class NotificationResolver
{
    /** Расход подтвердили или удалили — гасим всё, что про него. */
    public static function expenseHandled(int $expenseId): void
    {
        self::readWhere('expense_id', $expenseId);
    }

    /** Задачу закрыли — её уведомления получателям больше не нужны. */
    public static function taskDone(Task $task): void
    {
        self::readWhere('task_id', $task->id);
    }

    /**
     * Пометить прочитанными все непрочитанные уведомления, у которых в data
     * лежит нужный ключ.
     *
     * `like` по JSON-полю, а не разбор JSON средствами БД: приложение
     * работает и на SQLite (тесты), и на MySQL (прод), и json-функции у них
     * разные. Ключи числовые, поэтому совпадение однозначно.
     */
    private static function readWhere(string $key, int $id): void
    {
        DatabaseNotification::whereNull('read_at')
            ->where(fn ($q) => $q
                ->where('data', 'like', '%"'.$key.'":'.$id.',%')
                ->orWhere('data', 'like', '%"'.$key.'":'.$id.'}%'))
            ->update(['read_at' => now()]);
    }

    /**
     * Ссылка на владельца записи для уведомления об удалении.
     *
     * Уведомление «удалили расход на 40 000 ₸» без ссылки заставляет искать
     * сделку руками. У поступления и долга хозяина нет — ведём на Финансы.
     */
    public static function ownerUrl(?string $type, ?int $id): string
    {
        if (! $id) {
            return '/finance';
        }

        return $type === 'project' ? '/projects/'.$id : '/deals/'.$id;
    }

    /** Активные бухгалтеры и админы — получатели денежных напоминаний. */
    public static function accountants(): \Illuminate\Support\Collection
    {
        return User::where('is_active', true)
            ->whereHas('roles', fn ($q) => $q->whereIn('name', ['admin', 'financist']))
            ->get();
    }
}
