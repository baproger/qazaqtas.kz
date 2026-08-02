<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Должности компании отдельными ролями: Юрист, Повар, Дизайнер, Технолог.
 * Права уровня «сотрудник»: цех, задачи, своя ЗП. Миграция (а не только
 * сидер), чтобы роли появились на проде автоматически при деплое.
 */
return new class extends Migration
{
    private const JOBS = ['lawyer', 'cook', 'designer', 'technologist'];

    private const PERMS = [
        'project.viewAny', 'project.view',
        'task.viewAny', 'task.view', 'task.update',
        'payroll.view',
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMS as $p) {
            Permission::findOrCreate($p, 'web');
        }
        foreach (self::JOBS as $job) {
            Role::findOrCreate($job, 'web')->syncPermissions(self::PERMS);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Role::whereIn('name', self::JOBS)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
