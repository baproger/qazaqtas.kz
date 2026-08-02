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
    public static function notifyDeleted(string $what): void
    {
        $actor = auth()->user();
        User::where('is_active', true)
            ->whereHas('roles', fn ($q) => $q->whereIn('name', ['admin', 'director']))
            ->when($actor, fn ($q) => $q->where('id', '!=', $actor->id))
            ->get()
            ->each(fn (User $u) => $u->notify(new FinanceRecordDeleted($what, $actor?->name ?? 'система')));
    }
}
