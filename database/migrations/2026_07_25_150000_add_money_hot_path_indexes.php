<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Индексы под горячие денежные запросы (касса/банк, ЗП, аналитика, счета):
 * morph-связки и фильтры по статусу/способу оплаты гоняются на каждом открытии
 * Финансов/Аналитики/ЗП — без индексов при росте данных начнутся фулсканы.
 * Все шаги идемпотентны (try/catch): MySQL DDL нетранзакционен.
 */
return new class extends Migration
{
    public function up(): void
    {
        $add = function (string $table, array $columns): void {
            try {
                Schema::table($table, fn (Blueprint $t) => $t->index($columns));
            } catch (\Throwable) {
                // индекс уже существует
            }
        };

        $add('expenses', ['status', 'payment_method']);          // суммы кассы/банка
        $add('expenses', ['expenseable_type', 'expenseable_id']); // расходы по сделкам (ЗП/аналитика/отчёт)
        $add('payments', ['payment_method']);                     // касса/банк по платежам
        $add('invoices', ['invoiceable_type', 'invoiceable_id']); // счета сделок (везде)
        $add('chat_messages', ['chat_id', 'user_id']);            // непрочитанные в чате
    }

    public function down(): void
    {
        $drop = function (string $table, array $columns): void {
            try {
                Schema::table($table, fn (Blueprint $t) => $t->dropIndex($columns));
            } catch (\Throwable) {
                // индекса нет
            }
        };

        $drop('expenses', ['status', 'payment_method']);
        $drop('expenses', ['expenseable_type', 'expenseable_id']);
        $drop('payments', ['payment_method']);
        $drop('invoices', ['invoiceable_type', 'invoiceable_id']);
        $drop('chat_messages', ['chat_id', 'user_id']);
    }
};
