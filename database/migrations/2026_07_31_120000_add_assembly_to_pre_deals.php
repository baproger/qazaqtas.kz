<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Монтаж в заявке — рядом с доставкой, вычитается из остатка при расчёте маржи.
// При переводе заявки в сделку доставка и монтаж автоматически создаются
// расходами сделки (🚚/🔧, confirmed, без нал/банк — кассу не трогают).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pre_deals', function (Blueprint $table) {
            $table->decimal('assembly', 15, 2)->default(0)->after('delivery');
        });
    }

    public function down(): void
    {
        Schema::table('pre_deals', function (Blueprint $table) {
            $table->dropColumn('assembly');
        });
    }
};
