<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * План без бригады: очередь «кому поручить».
 *
 * Менеджер, увидев нехватку в сделке, нажимает «Добавить недостающее в план
 * производства» — и объём должен попасть в цех сразу. Кто именно его сделает,
 * менеджер не знает: он не видит, какая бригада сейчас свободна. Поэтому план
 * рождается БЕЗ бригады и ждёт, пока начальник производства её назначит.
 *
 * `deal_id` — откуда пришёл объём. Нужен не для красоты: увидев план, мастер
 * должен понимать, чей это заказ и когда срок.
 *
 * Про уникальность. Индекс `(месяц, бригада, товар)` остаётся: два одинаковых
 * плана у одной бригады удвоили бы и задание, и процент. Но в MySQL NULL в
 * уникальном индексе не считается совпадением, поэтому нераспределённые планы
 * он не удержит — их сложение делает код в транзакции
 * (`ProductionPlanService::addShortage`). Дубль здесь не денежная ошибка: две
 * строки очереди мастер сведёт одним назначением.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_plans', function (Blueprint $table) {
            $table->foreignId('brigade_id')->nullable()->change();
            $table->foreignId('deal_id')->nullable()->after('product_id')
                ->constrained()->nullOnDelete();
            $table->index(['period_month', 'product_id'], 'production_plans_queue');
        });
    }

    public function down(): void
    {
        Schema::table('production_plans', function (Blueprint $table) {
            $table->dropIndex('production_plans_queue');
            $table->dropConstrainedForeignId('deal_id');
        });
    }
};
