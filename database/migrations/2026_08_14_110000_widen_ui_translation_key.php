<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ключ перевода становится длиннее.
 *
 * У интерфейса ERP ключом служит сам русский текст, а это бывает целый абзац
 * пояснения под полем. В 255 символов такие строки не помещались, и правка
 * длинного текста через админку молча обрезалась бы.
 *
 * 500 символов в utf8mb4 — это 2000 байт, и уникальный индекс в них
 * укладывается (предел MySQL — 3072 байта).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ui_translations', function (Blueprint $table) {
            $table->string('key', 500)->change();
        });
    }

    public function down(): void
    {
        Schema::table('ui_translations', function (Blueprint $table) {
            $table->string('key')->change();
        });
    }
};
