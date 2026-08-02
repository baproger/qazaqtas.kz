<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $modules = [
            'department', 'client', 'product', 'deal', 'project',
            'task', 'invoice', 'payment', 'expense', 'document',
            'user', 'role', 'setting', 'report',
        ];
        $abilities = ['viewAny', 'view', 'create', 'update', 'delete'];

        foreach ($modules as $module) {
            foreach ($abilities as $ability) {
                Permission::findOrCreate("{$module}.{$ability}", 'web');
            }
        }

        Permission::findOrCreate('payroll.view', 'web');

        $admin = Role::findOrCreate('admin', 'web');
        $admin->syncPermissions(Permission::all());

        // Директор — НАБЛЮДАТЕЛЬ: видит всё, но не меняет пользователей/роли/
        // настройки (иначе мог бы выдать себе admin и захватить систему). Права
        // только на просмотр + отчёты/ЗП; создание групп в чате не через Spatie.
        $director = Role::findOrCreate('director', 'web');
        $director->syncPermissions(Permission::where(fn ($q) => $q
            ->where('name', 'like', '%.viewAny')->orWhere('name', 'like', '%.view'))
            ->whereNotIn('name', ['user.viewAny', 'user.view', 'role.viewAny', 'role.view'])
            ->pluck('name')
            ->push('report.viewAny', 'payroll.view', 'user.viewAny', 'user.view')
            ->all());

        $financist = Role::findOrCreate('financist', 'web');
        $financist->syncPermissions(Permission::whereIn('name', [
            'invoice.viewAny', 'invoice.view', 'invoice.create', 'invoice.update', 'invoice.delete',
            'payment.viewAny', 'payment.view', 'payment.create', 'payment.update', 'payment.delete',
            'expense.viewAny', 'expense.view', 'expense.create', 'expense.update', 'expense.delete',
            // deal.update: бухгалтер двигает сделку по этапам Акт → ЭСФ → Оплата
            // (StageTransitionService не пускает туда менеджеров).
            // deal.create: бухгалтер тоже заводит сделки (просьба от 21.07.2026).
            'deal.viewAny', 'deal.view', 'deal.create', 'deal.update',
            'project.viewAny', 'project.view',
            'client.viewAny', 'client.view',
            'department.viewAny',
            // Финансист ведёт сотрудников: список, добавление, правка, деактивация.
            // Админов трогать не может (guardRoleAssignment + guard в destroy).
            'user.viewAny', 'user.view', 'user.create', 'user.update', 'user.delete',
            'payroll.view',
        ])->get());

        $manager = Role::findOrCreate('manager', 'web');
        $manager->syncPermissions(Permission::whereIn('name', $this->managerAbilities())->get());

        $employee = Role::findOrCreate('employee', 'web');
        $employee->syncPermissions([
            'project.viewAny', 'project.view',
            'task.viewAny', 'task.view', 'task.update',
            'payroll.view',
        ]);

        // Должности компании (юрист/повар/дизайнер/технолог) — права уровня
        // «сотрудник»: цех, задачи, своя ЗП. СЕО = роль admin (подпись в UI),
        // «Финансист-Бухгалтер» = financist.
        foreach (['lawyer', 'cook', 'designer', 'supplier'] as $job) {
            $perms = [
                'project.viewAny', 'project.view',
                'task.viewAny', 'task.view', 'task.update',
                'payroll.view',
            ];
            // Дизайнер и снабженец подтверждают гейт-этапы («Дизайн и расчет»,
            // «Закуп ЛДСП,МДФ») — им нужен просмотр сделок.
            if (in_array($job, ['designer', 'supplier'], true)) {
                $perms = array_merge($perms, ['deal.viewAny', 'deal.view']);
            }
            Role::findOrCreate($job, 'web')->syncPermissions($perms);
        }
    }

    /**
     * @return array<int, string>
     */
    private function managerAbilities(): array
    {
        $abilities = [];
        foreach (['client', 'deal', 'project', 'task', 'invoice', 'payment', 'expense', 'document', 'product'] as $module) {
            foreach (['viewAny', 'view', 'create', 'update', 'delete'] as $ability) {
                $abilities[] = "{$module}.{$ability}";
            }
        }
        // department.viewAny намеренно НЕ выдаётся менеджеру: страница «Отделы»
        // видна только admin / director / financist.
        $abilities[] = 'payroll.view';

        return $abilities;
    }
}
