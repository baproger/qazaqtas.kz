<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Позиция заказа: отметка «этот товар закончен».
 *
 * Это НЕ то же самое, что «сделано 24 из 24». Бывает 22 из 24 и «больше не
 * будет» — брак, пересорт, договорились с клиентом. Закрывает позицию
 * человек, а не счётчик: только бригадир знает, что работа по этому товару
 * действительно кончилась.
 *
 * Пока не закрыты все позиции, заказ не уходит на «Логистику» — иначе машина
 * выезжает, а по бумагам половина заказа не сделана.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deal_items', function (Blueprint $table) {
            $table->timestamp('finished_at')->nullable()->after('sort');
            // Кто закрыл: за отметкой стоит выезд машины, автор должен быть
            // виден без похода в журнал.
            $table->foreignId('finished_by')->nullable()->after('finished_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('deal_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('finished_by');
            $table->dropColumn('finished_at');
        });
    }
};
