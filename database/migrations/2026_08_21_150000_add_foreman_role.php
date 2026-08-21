<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Роль «бригадир»: ведёт сменные наряды своей бригады.
 *
 * Права уровня сотрудника — цех, задачи, своя ЗП, заявка на расход. Доступ к
 * нарядам проверяется по бригаде в контроллере: бригадир видит только свою.
 *
 * Отдельной миграцией, потому что на проде сидер не запускают.
 */
return new class extends Migration
{
    public function up(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $role = Role::findOrCreate('foreman', 'web');
        $role->syncPermissions(Permission::whereIn('name', [
            'project.viewAny', 'project.view',
            'task.viewAny', 'task.view', 'task.update',
            'payroll.view',
            'expense.create',
        ])->get());
    }

    public function down(): void
    {
        Role::where('name', 'foreman')->delete();
    }
};
