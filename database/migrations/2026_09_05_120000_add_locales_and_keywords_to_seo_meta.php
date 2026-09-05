<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEO на двух языках витрины: ru — основной, kk — обязательный дубль.
 * Keywords добавлены по просьбе владельца (чекеры ругаются на отсутствие).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seo_meta', function (Blueprint $table) {
            $table->string('keywords', 500)->nullable()->after('description');
            $table->string('title_kk')->nullable()->after('keywords');
            $table->text('description_kk')->nullable()->after('title_kk');
            $table->string('keywords_kk', 500)->nullable()->after('description_kk');
        });
    }

    public function down(): void
    {
        Schema::table('seo_meta', function (Blueprint $table) {
            $table->dropColumn(['keywords', 'title_kk', 'description_kk', 'keywords_kk']);
        });
    }
};
