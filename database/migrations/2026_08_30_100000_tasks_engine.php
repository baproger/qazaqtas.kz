<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Модуль задач: тип (сделка / процесс ERP / корпоративная) и статус «отменена».
 * Статусы остаются прежними (new / in_progress / review / done), чтобы не
 * ломать панели в сделках и заказах; enum заменяется строкой.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->string('status', 20)->default('new')->change();
            $table->string('type', 20)->default('corporate')->index()->after('taskable_id');
        });

        DB::table('tasks')->where('taskable_type', 'deal')->update(['type' => 'crm_deal']);
        DB::table('tasks')->where('taskable_type', 'project')->update(['type' => 'erp_process']);
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
