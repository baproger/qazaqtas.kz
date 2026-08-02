<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Срок окончания тендера у лота: в день окончания ответственному менеджеру
// приходит уведомление (команда pre-deals:notify-tender-deadline, 09:00).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pre_deals', function (Blueprint $table) {
            $table->date('tender_deadline')->nullable()->after('lot_number');
        });
    }

    public function down(): void
    {
        Schema::table('pre_deals', function (Blueprint $table) {
            $table->dropColumn('tender_deadline');
        });
    }
};
