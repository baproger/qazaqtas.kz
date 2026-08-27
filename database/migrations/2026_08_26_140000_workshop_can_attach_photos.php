<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Цеху — право видеть и прикреплять файлы.
 *
 * Фото в карточке не открывались ни у бригадира, ни у цехового работника:
 * `document.*` не выдавали никому, кроме менеджера и руководства, а показ
 * картинки идёт через DocumentPolicy. Снимок отливки — это как раз то, что
 * делают в цехе, а не в отделе продаж.
 *
 * Удаление НЕ выдаём: договор из сделки цех стирать не должен. Свой файл
 * автор убирает и без этого права — см. DocumentPolicy::delete.
 *
 * Миграцией, а не сидером: сидер на проде не запускают.
 */
return new class extends Migration
{
    private const ROLES = ['employee', 'foreman', 'lawyer', 'cook', 'designer', 'supplier'];

    private const PERMISSIONS = ['document.viewAny', 'document.view', 'document.create'];

    public function up(): void
    {
        // Права берём записями, а не именами: на чистой базе (тесты, новая
        // установка) прав ещё нет, а роли уже создал предыдущий миграционный
        // шаг — по имени spatie бросил бы PermissionDoesNotExist. Пустой
        // список означает «здесь ещё нечего выдавать»: раздаст сидер.
        $permissions = Permission::whereIn('name', self::PERMISSIONS)->get();
        if ($permissions->isEmpty()) {
            return;
        }

        foreach (self::ROLES as $name) {
            Role::where('name', $name)->first()?->givePermissionTo($permissions);
        }
    }

    public function down(): void
    {
        $permissions = Permission::whereIn('name', self::PERMISSIONS)->get();
        foreach (self::ROLES as $name) {
            Role::where('name', $name)->first()?->revokePermissionTo($permissions);
        }
    }
};
