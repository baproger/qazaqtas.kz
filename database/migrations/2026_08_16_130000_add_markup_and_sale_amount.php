<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Товар со склада: наценка и цена продажи.
 *
 * Позицию склада покупают за 10 000 ₸, добавляют свой процент и продают.
 * Разница между продажей и закупом — наценка, от неё менеджер получает
 * бонус за складской товар (Setting `warehouse_bonus_percent`, по умолчанию
 * 2%).
 *
 * `expenses.sale_amount` фиксирует цену продажи В МОМЕНТ списания в сделку:
 * наценку на складе потом поменяют, а бонус по уже проданному товару меняться
 * не должен — иначе прошлые ведомости ЗП начнут «плыть».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('materials', function (Blueprint $table) {
            // Пусто — действует общая наценка из настроек.
            $table->decimal('markup_pct', 6, 2)->nullable()->after('price');
        });

        Schema::table('expenses', function (Blueprint $table) {
            // Заполняется только у списаний со склада: qty × цена продажи.
            $table->decimal('sale_amount', 15, 2)->nullable()->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('materials', fn (Blueprint $table) => $table->dropColumn('markup_pct'));
        Schema::table('expenses', fn (Blueprint $table) => $table->dropColumn('sale_amount'));
    }
};
