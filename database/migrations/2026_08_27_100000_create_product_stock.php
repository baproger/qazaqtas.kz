<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Склад ГОТОВОЙ ПРОДУКЦИИ — отдельно от склада сырья.
 *
 * В `materials` лежат крошка, цемент и смола: их закупают, за них платят
 * поставщику, они уходят в себестоимость. Готовая продукция — другая
 * экономика: её делает цех, и появляется она из подтверждённой выработки.
 * Смешать их значило бы считать цемент и вазоны в одном остатке.
 *
 * ОСТАТОК НЕ МЕНЯЕТСЯ ЧИСЛОМ. Любое изменение — строка движения со ссылкой на
 * источник: наряд, позиция сделки, инвентаризация. Иначе расхождение в конце
 * месяца объяснить нечем, а откатить невозможно.
 *
 * `product_stocks` — денормализация ради скорости: остаток спрашивают в
 * выпадающем списке товаров при каждом открытии. Пишется в той же транзакции,
 * что и движение.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            // Склад у каждой фирмы свой, а каталог общий на холдинг.
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            // Со знаком: плюс приход, минус расход. Одна колонка, а не две, —
            // остаток тогда просто сумма, и «забыть про знак» негде.
            $table->decimal('qty', 12, 2);
            $table->string('type');   // production_in | deal_out | deal_return | manual_adjust | reversal
            $table->nullableMorphs('source');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamps();

            // Идемпотентность: повторное подтверждение того же наряда не
            // создаёт второй приход. Дубль удвоил бы остаток молча.
            $table->unique(['source_type', 'source_id', 'type'], 'stock_movements_source_unique');
            $table->index(['product_id', 'company_id']);
        });

        Schema::create('product_stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('qty', 12, 2)->default(0);
            $table->timestamps();

            $table->unique(['product_id', 'company_id']);
        });

        Schema::table('products', function (Blueprint $table) {
            // Ниже этого остатка товар подсвечивается и о нём предупреждают.
            $table->decimal('min_stock', 12, 2)->nullable()->after('in_stock');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'min_stock')) {
                $table->dropColumn('min_stock');
            }
        });
        Schema::dropIfExists('product_stocks');
        Schema::dropIfExists('stock_movements');
    }
};
