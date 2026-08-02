<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Освобождает номера уже удалённых сделок (BAIA-040 → BAIA-040#del{id}):
 * unique-индекс deals.number учитывает и soft-deleted строки, поэтому без
 * переименования нумерация не может начаться заново с 001.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('deals')->whereNotNull('deleted_at')
            ->where('number', 'not like', '%#del%')
            ->get(['id', 'number'])
            ->each(fn ($d) => DB::table('deals')->where('id', $d->id)
                ->update(['number' => $d->number.'#del'.$d->id]));
    }

    public function down(): void
    {
        // Номер удалённой сделки восстанавливаем только если он снова свободен.
        DB::table('deals')->whereNotNull('deleted_at')->where('number', 'like', '%#del%')
            ->get(['id', 'number'])->each(function ($d) {
                $orig = preg_replace('/#del\d+$/', '', $d->number);
                if (! DB::table('deals')->where('number', $orig)->exists()) {
                    DB::table('deals')->where('id', $d->id)->update(['number' => $orig]);
                }
            });
    }
};
