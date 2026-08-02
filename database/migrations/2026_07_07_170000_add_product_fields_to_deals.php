<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            // Ед. изм. количества (штук, рулон, Комплект, Работа, метр, метр погонный).
            // Само количество живёт в исторической колонке lot_number (в UI — «Количество»).
            $table->string('unit', 50)->nullable()->after('lot_number');
            // Дата договора (Срок = существующий deadline).
            $table->date('contract_date')->nullable()->after('bin');
            // Источник (портал): ОМ, ЗЦП, ИОИ, СК, СК-ЭМ, СК-store, ОТП.
            $table->string('source', 50)->nullable()->after('unit');
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->dropColumn(['unit', 'contract_date', 'source']);
        });
    }
};
