<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Ручной % бонуса менеджера по сделке (ставит финансист/админ).
// null = автоматически по ступеням маржи (PayrollService::bonusRateForMargin).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->decimal('bonus_rate_override', 5, 2)->nullable()->after('budget');
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->dropColumn('bonus_rate_override');
        });
    }
};
