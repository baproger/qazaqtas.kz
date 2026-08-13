<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Переводы контента каталога и объектов сайта.
 *
 * Базовые колонки (`products.name`, `product_categories.name`…) остаются на
 * месте и работают как запасной вариант: пока перевод на язык не заведён,
 * витрина показывает их, а не пустоту. Так уже заведённый каталог продолжает
 * работать в день выкатки, а владелец заполняет второй язык постепенно.
 *
 * Тот же приём уже используют этапы сделок и категории расходов
 * (deal_stage_translations и соседние таблицы) — схема намеренно такая же.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 5);
            $table->string('name')->nullable();
            $table->string('short_description')->nullable();
            $table->text('description')->nullable();
            // Значения характеристик и названия цветов: ключи и коды остаются
            // общими, переводится только то, что видит покупатель.
            $table->json('specs')->nullable();
            $table->json('colors')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'locale']);
        });

        Schema::create('product_category_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_category_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 5);
            $table->string('name')->nullable();
            $table->string('tagline')->nullable();
            $table->text('description')->nullable();
            // Выноски вокруг изделия на витрине.
            $table->json('specs')->nullable();
            $table->timestamps();

            $table->unique(['product_category_id', 'locale'], 'pc_translations_unique');
        });

        Schema::create('site_project_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_project_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 5);
            $table->string('title')->nullable();
            $table->string('city')->nullable();
            $table->string('products')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['site_project_id', 'locale'], 'sp_translations_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_project_translations');
        Schema::dropIfExists('product_category_translations');
        Schema::dropIfExists('product_translations');
    }
};
