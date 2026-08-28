<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

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
            // deal.create: директор заводит сделки наравне с менеджером
            // (правило владельца от 28.08.2026). Наблюдателем он остаётся
            // в деньгах: этапы Акт / ЭСФ / Оплата ему по-прежнему закрыты.
            ->push('report.viewAny', 'payroll.view', 'user.viewAny', 'user.view', 'deal.create')
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
            // Фото отливки и упаковки снимают в цехе — значит, и прикрепляют
            // там же. Удаление не даём: договор из сделки цех не стирает.
            'document.viewAny', 'document.view', 'document.create',
            // Заявка «Расход компании» — счёт бухгалтеру на оплату. Право
            // только на создание: подтверждает и удаляет бухгалтер.
            'expense.create',
        ]);

        // Должности компании (юрист/повар/технолог/снабженец) — права уровня
        // «сотрудник»: цех, задачи, своя ЗП. СЕО = роль admin (подпись в UI),
        // «Финансист-Бухгалтер» = financist.
        // Бригадир — уровень сотрудника плюс своя страница производства:
        // он ведёт наряды своей бригады (доступ проверяется в контроллере).
        foreach (['lawyer', 'cook', 'designer', 'supplier', 'foreman', 'production_head', 'assistant'] as $job) {
            $perms = [
                'project.viewAny', 'project.view',
                'task.viewAny', 'task.view', 'task.update',
                'payroll.view',
                'document.viewAny', 'document.view', 'document.create',
                // Заявку на расход компании подаёт любой сотрудник.
                'expense.create',
            ];
            // Технолог и снабженец подтверждают гейт-этапы («Замер и расчёт»,
            // «Закуп сырья»), бригадир ведёт сделку в цехе — всем троим нужен
            // просмотр сделок. Что именно они увидят, решает DealPolicy:
            // бригадиру — только назначенные ему, и без сумм.
            if (in_array($job, ['designer', 'supplier', 'foreman'], true)) {
                $perms = array_merge($perms, ['deal.viewAny', 'deal.view']);
            }
            // Начальник производства ведёт цех: доска и карточки заказов,
            // планы, склад. Наряды он ПОДТВЕРЖДАЕТ — это проверяется ролью
            // в контроллере, правом такое не выразить.
            if ($job === 'production_head') {
                $perms = array_merge($perms, [
                    'deal.viewAny', 'deal.view',
                    'project.update',
                    'product.viewAny', 'product.view',
                    'department.viewAny',
                ]);
            }
            // Ассистент — помощник руководства: видит ход дел, но ничего не
            // двигает. Ему приходят уведомления о нехватке и о новых планах.
            if ($job === 'assistant') {
                $perms = array_merge($perms, [
                    // Сделку заводят четверо: админ, менеджер, директор и
                    // ассистент (правило владельца от 28.08.2026).
                    'deal.viewAny', 'deal.view', 'deal.create',
                    'product.viewAny', 'product.view',
                    'department.viewAny',
                ]);
            }
            Role::findOrCreate($job, 'web')->syncPermissions($perms);
        }

        $this->applyTraits();
    }

    /**
     * Признаки ролей: чем роль ЯВЛЯЕТСЯ, а не только что ей разрешено.
     *
     * Живут здесь, а не только в миграции: на чистой установке и в тестах
     * миграции проходят ДО сидера, когда таблица ролей ещё пуста, и апдейт
     * в миграции не нашёл бы ни одной строки. Миграция досеивает признаки
     * действующим базам, сидер — источник правды для новых.
     *
     * [подпись, руководство, видит суммы, цеховая]
     */
    /**
     * Роли, которые заводит сама система.
     *
     * Список здесь, а не в команде восстановления: он один и тот же для
     * чистой установки и для досева, и два списка разошлись бы.
     *
     * @return array<int, string>
     */
    public static function systemRoles(): array
    {
        return ['admin', 'director', 'financist', 'manager', 'employee',
            'foreman', 'designer', 'supplier', 'lawyer', 'cook',
            'production_head', 'assistant'];
    }

    private function applyTraits(): void
    {
        $traits = [
            'admin' => ['СЕО (админ)', true, true, false],
            'director' => ['Директор', true, true, false],
            'financist' => ['Финансист-Бухгалтер', true, true, false],
            'manager' => ['Менеджер', false, true, false],
            'employee' => ['Сотрудник цеха', false, false, true],
            'foreman' => ['Бригадир', false, false, true],
            'designer' => ['Технолог', false, true, false],
            'supplier' => ['Снабженец', false, true, false],
            'lawyer' => ['Юрист', false, false, false],
            'cook' => ['Повар', false, false, false],
            // Начальник производства видит весь цех и все заказы, но суммы
            // сделок ему не нужны: он отвечает за объём, а не за деньги.
            'production_head' => ['Начальник производства', false, false, true],
            // Ассистент — наблюдатель руководства: видит всех, сумм не видит.
            'assistant' => ['Ассистент', true, false, false],
        ];

        foreach ($traits as $name => [$label, $leadership, $money, $workshop]) {
            Role::where('name', $name)->update([
                'label' => $label,
                'is_leadership' => $leadership,
                'sees_money' => $money,
                'is_workshop' => $workshop,
                'is_system' => true,
            ]);
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

        // Удалять расходы — только бухгалтер и админ. Менеджер ошибся в
        // материальном списании — просит бухгалтера удалить (остаток
        // вернётся на склад) и заводит заново. Правило продублировано в
        // ExpensePolicy: оно должно держаться, даже если права поменяют
        // через админку.
        $abilities = array_values(array_diff($abilities, ['expense.delete']));

        return $abilities;
    }
}
