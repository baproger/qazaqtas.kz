<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Поток спец-этапов: … → Акт утверждение (галочка, 3 дня) → ЭСФ (галочка,
     * 30 дней) → Оплата успешно. Вставляем «ЭСФ» после «Акт утверждение» и
     * включаем checklist-гейт на обоих этапах (непустой checklist блокирует
     * переход вперёд, пока по сделке есть незакрытые задачи).
     */
    public function up(): void
    {
        $stages = DB::table('deal_stages')->where('is_active', true)->orderBy('order')->get();
        $act = $stages->first(fn ($s) => mb_stripos($s->name, 'акт') !== false);

        if ($act) {
            DB::table('deal_stages')->where('id', $act->id)->update(['checklist' => json_encode(['Акт выставлен'])]);

            $esfExists = $stages->contains(fn ($s) => mb_stripos($s->name, 'эсф') !== false);
            if (! $esfExists) {
                // Освобождаем место сразу после Акта и вставляем ЭСФ.
                DB::table('deal_stages')->where('order', '>', $act->order)->increment('order');
                $esfId = DB::table('deal_stages')->insertGetId([
                    'name' => 'ЭСФ', 'type' => 'sale', 'order' => $act->order + 1,
                    'color' => '#0EA5E9', 'is_won' => false, 'is_active' => true,
                    'checklist' => json_encode(['ЭСФ выставлен']),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                foreach (['ru' => 'ЭСФ', 'kk' => 'ЭШФ'] as $locale => $name) {
                    // deal_stage_translations без timestamps-колонок — created_at/
                    // updated_at сюда писать нельзя (иначе Unknown column при re-run).
                    DB::table('deal_stage_translations')->insert([
                        'deal_stage_id' => $esfId, 'locale' => $locale, 'name' => $name,
                    ]);
                }
            } else {
                DB::table('deal_stages')
                    ->whereRaw('LOWER(name) LIKE ?', ['%эсф%'])
                    ->update(['checklist' => json_encode(['ЭСФ выставлен'])]);
            }
        }
    }

    public function down(): void
    {
        $esf = DB::table('deal_stages')->whereRaw('LOWER(name) LIKE ?', ['%эсф%'])->first();
        if ($esf) {
            DB::table('deal_stage_translations')->where('deal_stage_id', $esf->id)->delete();
            DB::table('deal_stages')->where('id', $esf->id)->delete();
            DB::table('deal_stages')->where('order', '>', $esf->order)->decrement('order');
        }
    }
};
