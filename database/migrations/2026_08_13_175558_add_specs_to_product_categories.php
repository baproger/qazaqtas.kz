<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Характеристики для выносок на витрине. Снимок категории — свой кадр, и
 * подписи к нему тоже должны быть свои: размер бордюра на фото не обязан
 * совпадать с размером самой дешёвой позиции раздела.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_categories', function (Blueprint $table) {
            $table->json('specs')->nullable()->after('thumb');
        });
    }

    public function down(): void
    {
        Schema::table('product_categories', function (Blueprint $table) {
            $table->dropColumn('specs');
        });
    }
};
