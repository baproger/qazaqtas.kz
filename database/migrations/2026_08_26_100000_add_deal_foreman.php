<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Бригадир сделки.
 *
 * Бригаду на объект назначает директор, и до сих пор это жило в голове или в
 * переписке: в системе не было ответа на вопрос «кто ведёт эту сделку в цехе».
 * Поле отдельное от `responsible_user_id` — менеджер по-прежнему отвечает за
 * деньги и клиента, бригадир за работу.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->foreignId('foreman_id')->nullable()->after('responsible_user_id')
                ->constrained('users')->nullOnDelete();
            $table->index('foreman_id');
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->dropConstrainedForeignKey('foreman_id');
        });
    }
};
