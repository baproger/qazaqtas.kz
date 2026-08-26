<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;

/**
 * Бригадиру — доступ к сделкам, на которые его назначили.
 *
 * Роль заводилась под «Производство», и сделок не касалась вовсе. Теперь
 * бригадир ведёт сделку в цехе: должен открыть карточку и двигать её по
 * этапам. Прав на правку и удаление ему не даём — их проверяет DealPolicy,
 * а суммы прячет DealController.
 *
 * Миграцией, а не сидером: сидер на проде не запускают.
 */
return new class extends Migration
{
    private const PERMISSIONS = ['deal.viewAny', 'deal.view'];

    public function up(): void
    {
        $role = Role::where('name', 'foreman')->first();
        if ($role) {
            $role->givePermissionTo(self::PERMISSIONS);
        }
    }

    public function down(): void
    {
        $role = Role::where('name', 'foreman')->first();
        if ($role) {
            $role->revokePermissionTo(self::PERMISSIONS);
        }
    }
};
