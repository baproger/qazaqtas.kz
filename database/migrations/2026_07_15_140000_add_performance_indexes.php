<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Индексы под горячие запросы (аудит скорости 15.07.2026):
 * - deals.deadline      — просроченные (страница, счётчик, уведомления);
 * - deals.created_at    — «за период», топ менеджеров, сортировка списков;
 * - deals.contract_date — фильтр по дате договора;
 * - expenses.date       — периоды на Финансах/Аналитике/Складе/Отчёте;
 * - payments.payment_date — «оплачено за период», помесячный график;
 * - tasks.due_date      — просроченные задачи (счётчик + tasks:notify-overdue);
 * - material_receipts.date — поступления склада за период.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->index('deadline');
            $table->index('created_at');
            $table->index('contract_date');
        });
        Schema::table('expenses', fn (Blueprint $t) => $t->index('date'));
        Schema::table('payments', fn (Blueprint $t) => $t->index('payment_date'));
        Schema::table('tasks', fn (Blueprint $t) => $t->index('due_date'));
        Schema::table('material_receipts', fn (Blueprint $t) => $t->index('date'));
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->dropIndex(['deadline']);
            $table->dropIndex(['created_at']);
            $table->dropIndex(['contract_date']);
        });
        Schema::table('expenses', fn (Blueprint $t) => $t->dropIndex(['date']));
        Schema::table('payments', fn (Blueprint $t) => $t->dropIndex(['payment_date']));
        Schema::table('tasks', fn (Blueprint $t) => $t->dropIndex(['due_date']));
        Schema::table('material_receipts', fn (Blueprint $t) => $t->dropIndex(['date']));
    }
};
