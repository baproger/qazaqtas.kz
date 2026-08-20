<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Несколько товаров в одной сделке и в одной заявке.
 *
 * Клиент редко берёт что-то одно: брусчатка идёт вместе с бордюром и урнами,
 * и у каждой позиции своя единица (м², штук, п.м.) и своя цена. До сих пор в
 * сделке помещался ровно один товар, а остальное менеджер дописывал в
 * описание — из описания не посчитать ни сумму, ни маржу.
 *
 * Название, единица и цена КОПИРУЮТСЯ в позицию, а не читаются из каталога:
 * товар потом переименуют или переоценят, а уже проданное меняться не должно
 * (тот же приём, что у цены продажи склада в expenses.sale_amount).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pre_deal_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pre_deal_id')->constrained()->cascadeOnDelete();
            // Товар каталога; nullOnDelete — позиция переживает удаление товара.
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('name');
            $table->string('unit', 32)->nullable();
            $table->decimal('quantity', 15, 2)->default(0);
            $table->decimal('price', 15, 2)->default(0);          // цена продажи за единицу
            $table->decimal('purchase_price', 15, 2)->nullable(); // закуп за единицу — для маржи
            $table->decimal('amount', 15, 2)->default(0);         // количество × цена
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();

            $table->index(['pre_deal_id', 'sort']);
        });

        Schema::create('deal_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('name');
            $table->string('unit', 32)->nullable();
            $table->decimal('quantity', 15, 2)->default(0);
            $table->decimal('price', 15, 2)->default(0);
            $table->decimal('amount', 15, 2)->default(0);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();

            $table->index(['deal_id', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_items');
        Schema::dropIfExists('pre_deal_items');
    }
};
