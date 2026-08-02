<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Сборка в лоте — рядом с доставкой, вычитается из остатка при расчёте маржи.
// При «Выиграл ✓» доставка и сборка лота автоматически создаются расходами
// сделки (🚚/🔧, confirmed, без нал/банк — кассу не трогают).
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
