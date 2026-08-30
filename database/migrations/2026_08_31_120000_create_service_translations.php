<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Переводы услуг и их категорий (kk/ru) — той же схемой, что каталог:
 * базовая колонка остаётся значением по умолчанию, перевод перекрывает её
 * на своём языке (HasTranslations).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 5);
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
            $table->unique(['service_id', 'locale']);
        });

        Schema::create('service_category_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_category_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 5);
            $table->string('name')->nullable();
            $table->timestamps();
            $table->unique(['service_category_id', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_category_translations');
        Schema::dropIfExists('service_translations');
    }
};
