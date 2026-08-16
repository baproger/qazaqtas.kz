<?php

namespace App\Support;

use App\Models\User;
use App\Notifications\FinanceRecordDeleted;

/**
 * Контроль удаления финансовых данных: любое удаление (расход, поступление,
 * счёт, платёж, задолженность) уведомляет СЕО (admin) и директора.
 */
class FinanceAudit
{
    /**
     * @param  string|null  $ownerType  'deal' | 'project' — хозяин записи
     * @param  int|null  $ownerId  его id; без него ссылка ведёт на Финансы
     */
    public static function notifyDeleted(string $what, ?string $ownerType = null, ?int $ownerId = null): void
    {
        $actor = auth()->user();
        // Уведомление «удалили расход на 40 000 ₸» без ссылки заставляет
        // искать сделку руками — ведём сразу к хозяину записи.
        $url = NotificationResolver::ownerUrl($ownerType, $ownerId);
        User::where('is_active', true)
            ->whereHas('roles', fn ($q) => $q->whereIn('name', ['admin', 'director']))
            ->when($actor, fn ($q) => $q->where('id', '!=', $actor->id))
            ->get()
            ->each(fn (User $u) => $u->notify(new FinanceRecordDeleted($what, $actor?->name ?? 'система', $url)));
    }
}
