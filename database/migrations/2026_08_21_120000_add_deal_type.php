<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Тип сделки: своё производство или перепродажа.
 *
 * От него зависит ставка бонуса менеджера (1% против 2%) и отчётность. Тип
 * задаётся ЯВНО, а не угадывается по наличию складских позиций: угадывание в
 * деньгах однажды заплатит не тому и не столько.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->string('deal_type', 16)->default('production')->after('status')->index();
        });

        Schema::table('pre_deals', function (Blueprint $table) {
            // Заявка знает тип заранее — он переезжает в сделку при «В работу ✓».
            $table->string('deal_type', 16)->default('production')->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('deals', fn (Blueprint $table) => $table->dropColumn('deal_type'));
        Schema::table('pre_deals', fn (Blueprint $table) => $table->dropColumn('deal_type'));
    }
};
