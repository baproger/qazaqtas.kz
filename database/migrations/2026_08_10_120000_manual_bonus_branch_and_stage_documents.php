<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Пакет доработок по просьбе владельца (10.08.2026):
 *  - users.bonus_percent — свой процент бонуса у сотрудника вместо
 *    автоматических ступеней от маржи; считается от чистого остатка;
 *  - deals.branch — филиал сделки (Шымкент / Алматы / Тараз);
 *  - deals.area_m2 — площадь объекта в м² рядом с количеством;
 *  - deals.product_id — товар выбирается из каталога, а не пишется руками;
 *  - deal_stages.requires_document — без прикреплённого документа сделка
 *    с этого этапа дальше не идёт.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('bonus_percent', 5, 2)->nullable()->after('salary');
        });

        Schema::table('deals', function (Blueprint $table) {
            $table->string('branch', 80)->nullable()->after('company_id')->index();
            $table->decimal('area_m2', 12, 2)->nullable()->after('unit');
            $table->foreignId('product_id')->nullable()->after('client_name')->constrained('products')->nullOnDelete();
        });

        Schema::table('deal_stages', function (Blueprint $table) {
            $table->boolean('requires_document')->default(false)->after('gate_task_days');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('bonus_percent'));
        Schema::table('deal_stages', fn (Blueprint $t) => $t->dropColumn('requires_document'));
        Schema::table('deals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_id');
            $table->dropColumn(['branch', 'area_m2']);
        });
    }
};
