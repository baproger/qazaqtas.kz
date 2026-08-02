<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «№ договора» (историческая колонка bin) была varchar(20), а валидация
 * разрешает до 100 символов — длинные номера («990440002867/260024/00»)
 * падали с SQLSTATE 1406 Data too long (105 ошибок в prod-логе 17.07).
 * Расширяем до 100 — в один размер с DealRequest.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->string('bin', 100)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->string('bin', 20)->nullable()->change();
        });
    }
};
