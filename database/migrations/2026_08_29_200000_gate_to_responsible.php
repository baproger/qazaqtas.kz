<?php

use App\Models\DealStage;
use Illuminate\Database\Migrations\Migration;

/**
 * Гейт «Кому: Менеджер» означал «всем менеджерам» — и задача с уведомлением
 * уходили людям, не имеющим отношения к сделке. Для ролей, которые ведут
 * сделки, адресат — ответственный. Заодно убираем устаревшее правило
 * notify_roles: рассылки теперь делают роботы, явно.
 */
return new class extends Migration
{
    public function up(): void
    {
        DealStage::whereIn('gate_task_role', ['manager', 'director', 'admin'])->update(['gate_task_role' => 'responsible']);

        DealStage::whereNotNull('rules')->get()->each(function (DealStage $s) {
            $rules = $s->rules;
            if (array_key_exists('notify_roles', $rules)) {
                unset($rules['notify_roles']);
                $s->rules = $rules;
                $s->saveQuietly();
            }
        });
    }

    public function down(): void {}
};
