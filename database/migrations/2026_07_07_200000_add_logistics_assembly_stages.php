<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Поток: Закуп ЛДСП,МДФ (→ цех) → Логистика → Сборка → Акт утверждение →
     * ЭСФ → Оплата успешно. Цех возвращает сделку на «Логистика»; менеджер
     * двигает Логистика → Сборка → Акт; дальше работает бухгалтер.
     *
     * Идемпотентно: досоздаёт недостающие этапы/переводы и чинит нумерацию
     * (deal_stage_translations не имеет timestamps-колонок).
     */
    public function up(): void
    {
        $stages = fn () => DB::table('deal_stages')->where('is_active', true)->orderBy('order')->get();

        // Только для баз с настроенным потоком (есть «Акт утверждение»).
        // На свежей/тестовой базе этапы ставит сидер — тут делать нечего.
        if (! $stages()->contains(fn ($x) => mb_stripos($x->name, 'акт') !== false)) {
            return;
        }

        foreach ([
            ['name' => 'Логистика', 'kk' => 'Логистика', 'color' => '#F97316'],
            ['name' => 'Сборка', 'kk' => 'Құрастыру', 'color' => '#8B5CF6'],
        ] as $s) {
            $exists = $stages()->contains(fn ($x) => mb_stripos($x->name, $s['name']) !== false);
            if (! $exists) {
                $act = $stages()->first(fn ($x) => mb_stripos($x->name, 'акт') !== false);
                $order = $act ? $act->order : ((int) DB::table('deal_stages')->max('order') + 1);
                DB::table('deal_stages')->where('order', '>=', $order)->increment('order');
                DB::table('deal_stages')->insert([
                    'name' => $s['name'], 'type' => 'sale', 'order' => $order,
                    'color' => $s['color'], 'is_won' => false, 'is_active' => true,
                    'checklist' => json_encode([]),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        // Переводы (upsert, БЕЗ timestamps — их нет в таблице переводов).
        $names = ['Логистика' => ['ru' => 'Логистика', 'kk' => 'Логистика'],
            'Сборка' => ['ru' => 'Сборка', 'kk' => 'Құрастыру'],
            'ЭСФ' => ['ru' => 'ЭСФ', 'kk' => 'ЭШФ']];
        foreach ($names as $stageName => $locales) {
            $stage = DB::table('deal_stages')->where('type', 'sale')->where('name', $stageName)->first();
            if ($stage) {
                foreach ($locales as $locale => $name) {
                    DB::table('deal_stage_translations')->updateOrInsert(
                        ['deal_stage_id' => $stage->id, 'locale' => $locale],
                        ['name' => $name]
                    );
                }
            }
        }

        // Чиним нумерацию: 1..N без пробелов, относительный порядок сохраняем.
        DB::table('deal_stages')->where('type', 'sale')->orderBy('order')->orderBy('id')->get()
            ->each(fn ($s, $i) => DB::table('deal_stages')->where('id', $s->id)->update(['order' => $i + 1]));
    }

    public function down(): void
    {
        foreach (['Логистика', 'Сборка'] as $name) {
            $stage = DB::table('deal_stages')->where('name', $name)->where('type', 'sale')->first();
            if ($stage) {
                DB::table('deal_stage_translations')->where('deal_stage_id', $stage->id)->delete();
                DB::table('deal_stages')->where('id', $stage->id)->delete();
                DB::table('deal_stages')->where('order', '>', $stage->order)->decrement('order');
            }
        }
    }
};
