<?php

namespace App\Support;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Признаки роли — одна точка чтения на всё приложение.
 *
 * Вопросы «это руководство?», «видит ли суммы?», «цеховая ли роль?» задаются
 * системой в сотне мест. Пока ответом было перечисление имён
 * (`hasAnyRole(['admin','director','financist'])`), роль, созданную
 * владельцем, никто из этих мест не знал: галочки у неё есть, поведения нет.
 *
 * Теперь ответ живёт в признаке роли. Имена остаются в БД кодом, но спрашивать
 * их напрямую больше не нужно.
 *
 * У человека может быть несколько ролей — признак берём по принципу «хотя бы
 * одна», кроме денег: там наоборот, ХОТЯ БЫ ОДНА запрещающая роль закрывает
 * суммы. Дай бригадиру вторую роль — и запрет обошёлся бы добавлением роли.
 */
final class RoleTraits
{
    /** Видит все сделки, общую аналитику и чужие показатели. */
    public static function isLeadership(?User $user): bool
    {
        return (bool) $user?->roles->contains(fn ($role) => (bool) $role->is_leadership);
    }

    /**
     * Видит суммы: договор, расходы, бонус.
     *
     * Запрет сильнее разрешения: если хоть одна роль человека сумм не видит,
     * не видит и он. Иначе бригадиру достаточно было бы выдать вторую роль,
     * чтобы деньги открылись.
     */
    public static function seesMoney(?User $user): bool
    {
        if ($user === null) {
            return false;
        }
        if (self::isLeadership($user)) {
            return true;
        }

        return ! $user->roles->contains(fn ($role) => ! (bool) $role->sees_money);
    }

    /** Цеховая роль: доска заказов и карточки цеха целиком, а не только свои. */
    public static function isWorkshop(?User $user): bool
    {
        return (bool) $user?->roles->contains(fn ($role) => (bool) $role->is_workshop);
    }

    /**
     * Люди с этими ролями — БЕЗ падения, если роли нет.
     *
     * `User::role('director')` бросает RoleDoesNotExist, а роли теперь удаляет
     * владелец через Настройки → Права доступа. Удалил «Директора» — и любая
     * рассылка, которая его упоминает, роняла страницу пятисоткой. Спрашивать
     * роль по имени можно, рассчитывать на её существование — нет.
     *
     * Нет ни одной из перечисленных — возвращаем заведомо пустой запрос, а не
     * всех подряд: некому слать письмо это не то же самое, что слать всем.
     *
     * @param  array<int, string>|string  $roles
     */
    public static function users(array|string $roles): Builder
    {
        $existing = Role::whereIn('name', (array) $roles)->pluck('name')->all();

        return $existing === []
            ? User::query()->whereRaw('1 = 0')
            : User::role($existing);
    }

    /** Руководство ИЛИ цех — кому открыт весь цех, а не только свои заказы. */
    public static function seesWholeWorkshop(?User $user): bool
    {
        return self::isLeadership($user) || self::isWorkshop($user);
    }
}
