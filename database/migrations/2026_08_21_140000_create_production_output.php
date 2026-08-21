<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Производство: бригады и сменные наряды.
 *
 * Бонус производства платится за СДЕЛАННОЕ — за квадратные метры и штуки. Без
 * учёта выработки его не с чего считать: заказ цеха знает, что изделие готово,
 * но не знает, кто и сколько сделал.
 *
 * Ставки копируются в строку наряда снимком: подняли цену за м² в марте —
 * февральские наряды пересчитываться не должны, иначе прошлая зарплата
 * начинает «плыть» задним числом.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brigades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            // Цех (город) — те же площадки, что у воронки производства.
            $table->string('workshop')->nullable();
            $table->foreignId('foreman_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Состав бригады — для удобного ввода наряда; кто сколько сделал,
        // всё равно пишется построчно.
        Schema::create('brigade_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brigade_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unique(['brigade_id', 'user_id']);
        });

        Schema::create('work_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('brigade_id')->constrained()->cascadeOnDelete();
            // Заказ цеха, если смена делала конкретный заказ; не обязателен —
            // бригада может лить продукцию на склад.
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->date('date');
            $table->string('product')->nullable();
            // Наряд без подтверждения мастера бонуса не даёт: это защита от
            // приписок, а не бюрократия.
            $table->string('status', 12)->default('draft');   // draft | confirmed
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index(['date', 'brigade_id']);
            $table->index('status');
        });

        Schema::create('work_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained()->cascadeOnDelete();
            // Кто сделал. Пусто — объём бригады целиком (бонус бригадира).
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('qty_pcs', 15, 2)->default(0);
            $table->decimal('qty_m2', 15, 2)->default(0);
            // Ставки — снимок на момент наряда.
            $table->decimal('rate_pcs', 15, 2)->default(0);
            $table->decimal('rate_m2', 15, 2)->default(0);
            $table->decimal('amount', 15, 2)->default(0);
            // foreman — строка бонуса бригадира за весь объём смены.
            $table->string('role', 12)->default('worker');    // worker | foreman
            $table->timestamps();

            $table->index('work_order_id');
            $table->index(['user_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_lines');
        Schema::dropIfExists('work_orders');
        Schema::dropIfExists('brigade_user');
        Schema::dropIfExists('brigades');
    }
};
