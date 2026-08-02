<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Срок действия КП: в этот день ответственному менеджеру приходит напоминание
// (команда pre-deals:notify-quote-deadline, 09:00) — пора получить ответ клиента.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pre_deals', function (Blueprint $table) {
            $table->date('valid_until')->nullable()->after('request_number');
        });
    }

    public function down(): void
    {
        Schema::table('pre_deals', function (Blueprint $table) {
            $table->dropColumn('valid_until');
        });
    }
};
