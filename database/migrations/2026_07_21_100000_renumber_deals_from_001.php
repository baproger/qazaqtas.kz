<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Разовая перенумерация ВСЕХ живых сделок заново с 001 (по фирме: BAIA-001…,
 * ASU-001…), в порядке создания. Удалённые не участвуют (их номера уже
 * освобождены как …#del{id}). Чаты сделок привязаны к deal_id — история
 * сохраняется, имя чата и тексты гейт-задач обновляются косметически.
 */
return new class extends Migration
{
    public function up(): void
    {
        $deals = DB::table('deals')->whereNull('deleted_at')
            ->orderBy('created_at')->orderBy('id')
            ->get(['id', 'number', 'company_id']);
        if ($deals->isEmpty()) {
            return;
        }

        $codes = DB::table('companies')->pluck('code', 'id');

        // Фаза 1: временные номера — иначе упрёмся в unique(number) при обмене.
        foreach ($deals as $d) {
            DB::table('deals')->where('id', $d->id)->update(['number' => 'TMP#'.$d->id]);
        }

        // Фаза 2: сквозная нумерация с 001 по каждой фирме, в порядке создания.
        $counters = [];
        foreach ($deals as $d) {
            $prefix = strtoupper((string) ($codes[$d->company_id] ?? '') ?: 'BAIA');
            $counters[$prefix] = ($counters[$prefix] ?? 0) + 1;
            $new = $prefix.'-'.str_pad((string) $counters[$prefix], 3, '0', STR_PAD_LEFT);

            DB::table('deals')->where('id', $d->id)->update(['number' => $new]);
            if ($d->number === $new) {
                continue;
            }

            DB::table('chats')->where('deal_id', $d->id)->update(['name' => 'Чат '.$new]);
            DB::table('tasks')->where('taskable_type', 'deal')->where('taskable_id', $d->id)
                ->where('title', 'like', '%'.$d->number.'%')
                ->get(['id', 'title'])
                ->each(fn ($t) => DB::table('tasks')->where('id', $t->id)
                    ->update(['title' => str_replace($d->number, $new, $t->title)]));
        }
    }

    public function down(): void
    {
        // Разовая перенумерация — старые номера не восстанавливаются.
    }
};
