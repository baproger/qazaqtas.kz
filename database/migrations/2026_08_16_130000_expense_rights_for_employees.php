<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Права на расходы под новый порядок.
 *
 * Сидер на проде не гоняют — там роли живут в базе с первого запуска,
 * поэтому смену прав приходится проводить миграцией.
 *
 * 1. `expense.create` получают все сотрудники: заявка «Расход компании» —
 *    это счёт бухгалтеру на оплату, подать его должен любой, кто потратил
 *    свои деньги.
 * 2. `expense.delete` у менеджера снимается: удалять расходы — только
 *    бухгалтер и админ.
 */
return new class extends Migration
{
    private const ROLES = ['employee', 'lawyer', 'cook', 'designer', 'supplier'];

    public function up(): void
    {
        $create = DB::table('permissions')->where('name', 'expense.create')->value('id');
        $delete = DB::table('permissions')->where('name', 'expense.delete')->value('id');

        if ($create) {
            foreach (DB::table('roles')->whereIn('name', self::ROLES)->pluck('id') as $roleId) {
                $exists = DB::table('role_has_permissions')
                    ->where(['permission_id' => $create, 'role_id' => $roleId])->exists();

                if (! $exists) {
                    DB::table('role_has_permissions')
                        ->insert(['permission_id' => $create, 'role_id' => $roleId]);
                }
            }
        }

        if ($delete) {
            $manager = DB::table('roles')->where('name', 'manager')->value('id');

            if ($manager) {
                DB::table('role_has_permissions')
                    ->where(['permission_id' => $delete, 'role_id' => $manager])->delete();
            }
        }

        // Кэш прав spatie хранит карту ролей — иначе изменения не видны.
        app()['cache']->forget('spatie.permission.cache');
    }

    public function down(): void
    {
        $create = DB::table('permissions')->where('name', 'expense.create')->value('id');
        $delete = DB::table('permissions')->where('name', 'expense.delete')->value('id');

        if ($create) {
            DB::table('role_has_permissions')->where('permission_id', $create)
                ->whereIn('role_id', DB::table('roles')->whereIn('name', self::ROLES)->pluck('id'))
                ->delete();
        }

        if ($delete && $manager = DB::table('roles')->where('name', 'manager')->value('id')) {
            DB::table('role_has_permissions')
                ->insertOrIgnore(['permission_id' => $delete, 'role_id' => $manager]);
        }

        app()['cache']->forget('spatie.permission.cache');
    }
};
