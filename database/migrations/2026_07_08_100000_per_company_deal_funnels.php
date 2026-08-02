<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * У каждой компании (BAIA/ASU) своя воронка сделок:
     *  - deal_stages.company_id (null = общий этап — легаси/тестовые базы);
     *  - существующая воронка отдаётся BAIA, для ASU создаётся её клон;
     *  - сделки ASU перепривязываются к клонированным этапам.
     * Этапы цеха (project_stages) остаются общими.
     */
    public function up(): void
    {
        Schema::table('deal_stages', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('id')->constrained()->nullOnDelete();
        });

        $baia = DB::table('companies')->where('code', 'BAIA')->value('id');
        $asu = DB::table('companies')->where('code', 'ASU')->value('id');
        $saleStages = DB::table('deal_stages')->where('type', 'sale')->whereNull('company_id')->orderBy('order')->get();

        // Только для боевой базы с обеими компаниями и настроенной воронкой
        // (есть «Акт») — свежие/тестовые базы остаются на общих этапах сидера.
        if (! $baia || ! $asu || ! $saleStages->contains(fn ($s) => mb_stripos($s->name, 'акт') !== false)) {
            return;
        }

        DB::table('deal_stages')->where('type', 'sale')->whereNull('company_id')->update(['company_id' => $baia]);

        $map = []; // BAIA stage id → ASU clone id
        foreach ($saleStages as $s) {
            $cloneId = DB::table('deal_stages')->insertGetId([
                'company_id' => $asu, 'name' => $s->name, 'type' => 'sale', 'order' => $s->order,
                'color' => $s->color, 'is_won' => $s->is_won, 'is_active' => $s->is_active,
                'checklist' => $s->checklist,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $map[$s->id] = $cloneId;

            foreach (DB::table('deal_stage_translations')->where('deal_stage_id', $s->id)->get() as $tr) {
                DB::table('deal_stage_translations')->updateOrInsert(
                    ['deal_stage_id' => $cloneId, 'locale' => $tr->locale],
                    ['name' => $tr->name]
                );
            }
        }

        // Сделки ASU переезжают на клонированные этапы своей воронки.
        foreach ($map as $oldId => $newId) {
            DB::table('deals')->where('company_id', $asu)->where('deal_stage_id', $oldId)
                ->update(['deal_stage_id' => $newId]);
        }
    }

    public function down(): void
    {
        $asu = DB::table('companies')->where('code', 'ASU')->value('id');
        if ($asu) {
            // Вернуть сделки ASU на этапы BAIA с теми же названиями.
            $asuStages = DB::table('deal_stages')->where('company_id', $asu)->get();
            foreach ($asuStages as $s) {
                $baseId = DB::table('deal_stages')->where('type', 'sale')->where('company_id', '!=', $asu)
                    ->where('name', $s->name)->value('id');
                if ($baseId) {
                    DB::table('deals')->where('deal_stage_id', $s->id)->update(['deal_stage_id' => $baseId]);
                }
                DB::table('deal_stage_translations')->where('deal_stage_id', $s->id)->delete();
                DB::table('deal_stages')->where('id', $s->id)->delete();
            }
        }
        Schema::table('deal_stages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_id');
        });
    }
};
