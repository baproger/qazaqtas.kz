<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Доля партнёра сделки: вводится только %, сумма = % × сумма договора.
// Переносится из предсделки (partner_pct лота) при «Выиграл ✓» и вычитается
// из остатка во всех расчётах (маржа, бонус, Финансы, Аналитика, Сводный отчёт).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->decimal('partner_pct', 5, 2)->nullable()->after('budget');
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->dropColumn('partner_pct');
        });
    }
};
