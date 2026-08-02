<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * 1) Роль «Технолог» (technologist) переименована в «Снабженец» (supplier) —
 *    сотрудники роли сохраняются.
 * 2) Гейты этапов сделки (по stage_type, идемпотентно, если гейт не задан):
 *    - design («Дизайн и расчет»)  → задача «Подтвердить дизайн и расчет»,
 *      роль designer: пока дизайнер не подтвердит — дальше не идёт;
 *    - shop_gate («Закуп ЛДСП,МДФ») → задача «Подтвердить закуп ЛДСП, МДФ»,
 *      роль supplier (уведомление снабженцу; отправку в цех не блокирует).
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        if ($tech = Role::where('name', 'technologist')->first()) {
            $tech->update(['name' => 'supplier']);
        } else {
            Role::findOrCreate('supplier', 'web')->syncPermissions(
                collect(['project.viewAny', 'project.view', 'task.viewAny', 'task.view', 'task.update', 'payroll.view'])
                    ->map(fn ($p) => Permission::findOrCreate($p, 'web'))->all()
            );
        }
        // Дизайнер и снабженец подтверждают гейт-этапы — нужен просмотр сделок.
        $view = collect(['deal.viewAny', 'deal.view'])->map(fn ($p) => Permission::findOrCreate($p, 'web'));
        foreach (['designer', 'supplier'] as $r) {
            Role::findOrCreate($r, 'web')->givePermissionTo($view);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        DB::table('deal_stages')->where('stage_type', 'design')
            ->where(fn ($q) => $q->whereNull('gate_task_title')->orWhere('gate_task_title', ''))
            ->update(['gate_task_title' => 'Подтвердить дизайн и расчет', 'gate_task_role' => 'designer', 'gate_task_days' => 3]);

        DB::table('deal_stages')->where('stage_type', 'shop_gate')
            ->where(fn ($q) => $q->whereNull('gate_task_title')->orWhere('gate_task_title', ''))
            ->update(['gate_task_title' => 'Подтвердить закуп ЛДСП, МДФ', 'gate_task_role' => 'supplier', 'gate_task_days' => 3]);
    }

    public function down(): void
    {
        Role::where('name', 'supplier')->update(['name' => 'technologist']);
    }
};
