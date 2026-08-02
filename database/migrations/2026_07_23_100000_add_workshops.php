<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * У BAIA два цеха («Металл цех» и «Ағаш цех»), у каждого своя воронка этапов;
 * у ASU один цех, как раньше (workshop = null). Существующие этапы и заказы
 * BAIA относим к «Металл цех», для «Ағаш цех» клонируем набор этапов —
 * названия/цвета правятся в Настройки → Этапы.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_stages', fn (Blueprint $t) => $t->string('workshop', 100)->nullable()->after('company_id')->index());
        Schema::table('projects', fn (Blueprint $t) => $t->string('workshop', 100)->nullable()->after('project_stage_id'));

        $baia = DB::table('companies')->where('code', 'BAIA')->value('id');
        if (! $baia) {
            return;
        }

        $stages = DB::table('project_stages')->where('company_id', $baia)->orderBy('order')->get();
        if ($stages->isEmpty()) {
            return;
        }

        DB::table('project_stages')->where('company_id', $baia)->update(['workshop' => 'Металл цех']);
        foreach ($stages as $s) {
            DB::table('project_stages')->insert([
                'company_id' => $baia, 'workshop' => 'Ағаш цех',
                'name' => $s->name, 'order' => $s->order, 'color' => $s->color,
                'checklist' => $s->checklist, 'type' => $s->type,
                'is_completed' => $s->is_completed, 'is_active' => $s->is_active,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        DB::table('projects')
            ->whereIn('deal_id', DB::table('deals')->where('company_id', $baia)->pluck('id'))
            ->update(['workshop' => 'Металл цех']);
    }

    public function down(): void
    {
        DB::table('project_stages')->where('workshop', 'Ағаш цех')->delete();
        Schema::table('project_stages', fn (Blueprint $t) => $t->dropColumn('workshop'));
        Schema::table('projects', fn (Blueprint $t) => $t->dropColumn('workshop'));
    }
};
