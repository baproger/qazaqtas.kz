<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Каталог сайта = справочник продукции ERP. Таблица products уже была
 * (номенклатура), здесь она дорастает до полноценной карточки товара:
 * категория, слаг, витринные тексты, характеристики, цвета, фото.
 *
 * Единственный источник правды — ERP: сайт только читает эти данные.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('tagline')->nullable();        // короткая строка под заголовком
            $table->text('description')->nullable();
            $table->string('image')->nullable();
            $table->string('accent', 20)->nullable();     // цвет-акцент плитки категории
            $table->unsignedInteger('order')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('category_id')->nullable()->after('id')
                ->constrained('product_categories')->nullOnDelete();
            $table->string('slug')->nullable()->unique()->after('name');
            $table->string('short_description')->nullable()->after('description');
            // Характеристики карточки: размер, толщина, шт/м², вес, морозостойкость…
            $table->json('specs')->nullable()->after('short_description');
            // Цвета изделия для конфигуратора: [{name, hex}]
            $table->json('colors')->nullable()->after('specs');
            $table->json('images')->nullable()->after('colors');
            // Документы (паспорт, сертификат, схема укладки): [{name, path}]
            $table->json('documents')->nullable()->after('images');
            $table->decimal('old_price', 15, 2)->nullable()->after('price');
            $table->decimal('min_order', 12, 2)->default(0)->after('old_price'); // минимальный заказ в ед. изм.
            $table->boolean('is_active')->default(true)->index()->after('is_service');
            $table->boolean('is_featured')->default(false)->index()->after('is_active');
            $table->boolean('in_stock')->default(true)->after('is_featured');
            $table->unsignedInteger('order')->default(0)->after('in_stock');
            $table->index(['category_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['category_id', 'is_active']);
            $table->dropConstrainedForeignId('category_id');
            $table->dropColumn([
                'slug', 'short_description', 'specs', 'colors', 'images', 'documents',
                'old_price', 'min_order', 'is_active', 'is_featured', 'in_stock', 'order',
            ]);
        });
        Schema::dropIfExists('product_categories');
    }
};
