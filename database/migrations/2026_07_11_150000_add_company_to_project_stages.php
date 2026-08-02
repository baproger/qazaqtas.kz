<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Цех у каждой компании СВОЙ (BAIA — мебельный, ASU — швейный):
     * project_stages получает company_id (null = общий, легаси/тесты).
     * Существующие этапы цеха уходят компании BAIA; для ASU создаётся копия
     * набора (названия админ меняет в Настройки → Этапы под швейный процесс).
     * Заказы швейного цеха (сделки ASU) переезжают на этапы ASU по порядку.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('project_stages', 'company_id')) {
            Schema::table('project_stages', function (Blueprint $table) {
                $table->foreignId('company_id')->nullable()->after('id')->constrained('companies')->nullOnDelete();
            });
        }

        $baia = DB::table('companies')->where('code', 'BAIA')->value('id');
        $asu = DB::table('companies')->where('code', 'ASU')->value('id');
        if (! $baia || ! $asu) {
            return; // свежая установка без компаний — этапы остаются общими
        }

        $stages = DB::table('project_stages')->whereNull('company_id')->orderBy('order')->get();
        if ($stages->isEmpty()) {
            return;
        }

        // Существующий (мебельный) цех — BAIA.
        DB::table('project_stages')->whereIn('id', $stages->pluck('id'))->update(['company_id' => $baia]);

        // Копия воронки для ASU + перенос переводов; связка старый id → новый id.
        $map = [];
        foreach ($stages as $s) {
            $map[$s->id] = DB::table('project_stages')->insertGetId([
                'company_id' => $asu, 'name' => $s->name, 'order' => $s->order,
                'color' => $s->color, 'checklist' => $s->checklist, 'type' => $s->type,
                'is_completed' => $s->is_completed, 'is_active' => $s->is_active,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach (DB::table('project_stage_translations')->where('project_stage_id', $s->id)->get() as $tr) {
                DB::table('project_stage_translations')->insert([
                    'project_stage_id' => $map[$s->id], 'locale' => $tr->locale, 'name' => $tr->name,
                ]);
            }
        }

        // Заказы цеха ASU переезжают на свою воронку (этап того же порядка).
        $asuProjectIds = DB::table('projects')
            ->whereIn('deal_id', DB::table('deals')->where('company_id', $asu)->pluck('id'))
            ->pluck('id');
        foreach ($map as $oldId => $newId) {
            DB::table('projects')->whereIn('id', $asuProjectIds)->where('project_stage_id', $oldId)
                ->update(['project_stage_id' => $newId]);
        }
    }

    public function down(): void
    {
        Schema::table('project_stages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_id');
        });
    }
};
