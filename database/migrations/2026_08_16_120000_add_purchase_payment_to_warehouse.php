<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Деньги за товар уходят при ЗАКУПЕ, а не при списании в сделку.
 *
 * До сих пор было наоборот: приход на склад кассы не касался, а списание
 * материала в сделку уменьшало кассу — то есть деньги «уходили» второй раз,
 * уже после того как их отдали поставщику.
 *
 * Миграция переносит учёт: у прихода появляется связь с расходом-оплатой, а
 * у всех прошлых материальных списаний способ оплаты обнуляется — списание
 * становится внутренним движением запаса (в марже сделки оно остаётся).
 *
 * ⚠️ Остаток кассы/банка после этой миграции ВЫРАСТЕТ на сумму прошлых
 * списаний: они перестанут вычитаться, а закупы задним числом не
 * восстанавливаются (система не знает, чем платили). Владелец один раз
 * выравнивает остаток корректировкой кассы под факт.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('material_receipts', function (Blueprint $table) {
            // Расход-оплата закупа. nullOnDelete: расход могли удалить с
            // Финансов — приход при этом остаётся, просто без оплаты.
            $table->foreignId('expense_id')->nullable()->after('user_id')
                ->constrained('expenses')->nullOnDelete();
        });

        DB::table('expenses')->whereNotNull('material_id')->update(['payment_method' => null]);
    }

    public function down(): void
    {
        Schema::table('material_receipts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('expense_id');
        });

        // Обратно способ оплаты не восстанавливаем: чем платили за старые
        // списания, не знает никто — это и была ошибка учёта.
    }
};
