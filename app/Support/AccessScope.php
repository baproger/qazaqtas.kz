<?php

namespace App\Support;

use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Область доступа: не «пустят ли», а «на сколько записей».
 *
 * Право отвечает на первый вопрос и живёт в spatie; политики спрашивают его.
 * Область отвечает на второй и живёт в `role_module_access`. Раньше второго
 * ответа не было вовсе: код знал только «руководство видит все, остальные
 * свои», и руководителю отдела продаж приходилось выдавать роль директора —
 * вместе с чужими деньгами.
 *
 * Границу задаёт СТРУКТУРА КОМПАНИИ: «отдел» и «отдел и подчинённые» без
 * дерева отделов не значат ничего. Поэтому две страницы настроек и связаны.
 *
 * У человека может быть несколько ролей — берём САМУЮ ШИРОКУЮ область: роль
 * дана, чтобы что-то открыть, и пересечение областей закрывало бы то, что
 * владелец только что разрешил.
 */
final class AccessScope
{
    public const NONE = 'none';

    public const OWN = 'own';

    public const DEPARTMENT = 'department';

    public const DEPARTMENT_TREE = 'department_tree';

    public const ALL = 'all';

    /** От узкого к широкому — порядок решает, какая область победит. */
    public const LEVELS = [self::NONE, self::OWN, self::DEPARTMENT, self::DEPARTMENT_TREE, self::ALL];

    public const LABELS = [
        self::NONE => 'Нет доступа',
        self::OWN => 'Свои',
        self::DEPARTMENT => 'Своего отдела',
        self::DEPARTMENT_TREE => 'Отдела и подчинённых',
        self::ALL => 'Все',
    ];

    /** @var array<int, array<string, string>> кэш на запрос: роль → право → область */
    private static array $cache = [];

    /**
     * Область этого человека по этому праву.
     *
     * Настройки нет — отвечаем по признакам роли (§3): руководство видит всё,
     * остальные своё. Так система ведёт себя ровно как до появления областей,
     * пока владелец ничего не настроил.
     */
    public static function for(?User $user, string $permission): string
    {
        if ($user === null) {
            return self::NONE;
        }
        // Админ — суперпользователь через Gate::before; областей для него нет.
        if ($user->hasRole('admin')) {
            return self::ALL;
        }

        $widest = null;
        foreach ($user->roles as $role) {
            $scope = self::stored($role)[$permission]
                ?? ($role->is_leadership ? self::ALL : self::OWN);

            if ($widest === null || self::rank($scope) > self::rank($widest)) {
                $widest = $scope;
            }
        }

        return $widest ?? self::NONE;
    }

    /**
     * Сузить запрос до области.
     *
     * `$ownerColumn` — колонка ответственного (у сделки `responsible_user_id`).
     * Область считается по НЕЙ: «свои» — записи, где ответственный ты.
     */
    public static function apply(Builder $query, ?User $user, string $permission, string $ownerColumn = 'responsible_user_id'): Builder
    {
        $scope = self::for($user, $permission);

        if ($scope === self::ALL) {
            return $query;
        }
        if ($scope === self::NONE || $user === null) {
            // Пустой результат, а не «все»: ошибка в настройке не должна
            // открывать больше, чем открыто.
            return $query->whereRaw('1 = 0');
        }
        if ($scope === self::OWN) {
            return $query->where($ownerColumn, $user->id);
        }

        return $query->whereIn($ownerColumn, self::peerIds($user, $scope));
    }

    /**
     * Кого человек видит при отделочной области: он сам плюс сотрудники
     * своего отдела (и подчинённых отделов). Себя включаем всегда — иначе
     * человек без отдела перестал бы видеть даже собственные записи.
     *
     * @return array<int, int>
     */
    public static function peerIds(User $user, string $scope): array
    {
        $departmentId = $user->department_id;
        if ($departmentId === null) {
            return [$user->id];
        }

        $ids = $scope === self::DEPARTMENT_TREE
            ? (Department::find($departmentId)?->subtreeIds() ?? [$departmentId])
            : [$departmentId];

        return User::whereIn('department_id', $ids)->pluck('id')
            ->push($user->id)->unique()->values()->all();
    }

    /** @return array<string, string> право → область, как настроено у роли */
    private static function stored(Role $role): array
    {
        return self::$cache[$role->id] ??= DB::table('role_module_access')
            ->where('role_id', $role->id)
            ->pluck('scope', 'permission')
            ->all();
    }

    private static function rank(string $scope): int
    {
        $index = array_search($scope, self::LEVELS, true);

        return $index === false ? 0 : $index;
    }

    /** Сбросить кэш — нужен тестам и сразу после сохранения настроек. */
    public static function flush(): void
    {
        self::$cache = [];
    }
}
