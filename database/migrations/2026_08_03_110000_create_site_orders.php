<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Заказы с сайта. Попадают в ERP сразу же (страница «Заказы с сайта»),
 * менеджер обрабатывает и одной кнопкой превращает заказ в сделку —
 * дальше работает обычная воронка ERP (номер выдаёт DealNumberService).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();          // ZT-2026-0001
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');                          // как зовут заказчика
            $table->string('phone', 40);
            $table->string('email')->nullable();
            $table->string('city', 80)->nullable();          // Шымкент / Алматы / Тараз
            $table->string('address')->nullable();           // объект: адрес доставки
            $table->string('delivery', 30)->default('delivery'); // delivery | pickup
            $table->text('comment')->nullable();
            $table->decimal('total', 15, 2)->default(0);
            // new | in_work | done | cancelled — статус обработки менеджером
            $table->string('status', 20)->default('new')->index();
            $table->string('source', 20)->default('site')->index(); // site | whatsapp | configurator
            $table->foreignId('deal_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');            // снимок названия на момент заказа
            $table->string('unit', 50)->default('шт');
            $table->string('color')->nullable();
            $table->decimal('price', 15, 2)->default(0);
            $table->decimal('quantity', 12, 2)->default(1);
            $table->decimal('sum', 15, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
