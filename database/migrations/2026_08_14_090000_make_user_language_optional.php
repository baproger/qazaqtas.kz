<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Язык сотрудника становится необязательным.
 *
 * Раньше колонка была NOT NULL со значением `ru`, то есть каждый заведённый
 * сотрудник считался выбравшим русский, хотя ничего не выбирал. Теперь пусто
 * означает «как в настройках» (Настройки → Язык по умолчанию), и смена языка
 * компании действительно доходит до тех, кто свой язык не задавал.
 *
 * Уже заведённым сотрудникам значение НЕ трогаем: они работают в русском
 * интерфейсе, и молча переводить их на другой язык — не дело миграции.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('language', 5)->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('language', 5)->default('ru')->nullable(false)->change();
        });
    }
};
