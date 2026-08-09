<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Реализованные объекты QAZAQ TAS. Раньше это был массив в настройках без
 * фотографий; теперь — таблица с полноценными снимками, потому что главная
 * страница показывает их крупным планом при скролле.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_projects', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('city', 80)->nullable();
            $table->string('year', 10)->nullable();
            $table->string('area', 40)->nullable();       // «4 200 м²» — как показывать
            $table->string('products')->nullable();       // что уложено на объекте
            $table->text('description')->nullable();
            $table->string('image')->nullable();          // фото объекта
            $table->string('thumb')->nullable();
            $table->unsignedInteger('order')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_projects');
    }
};
