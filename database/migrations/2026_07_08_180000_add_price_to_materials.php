<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Цена закупки: указывается при приходе (за единицу), хранится на материале
     * (последняя закупочная). Расход по материалам в сделке считается
     * автоматически: количество × цена.
     */
    public function up(): void
    {
        Schema::table('materials', function (Blueprint $table) {
            $table->decimal('price', 12, 2)->default(0)->after('quantity'); // цена за единицу (последняя закупочная)
        });
        Schema::table('material_receipts', function (Blueprint $table) {
            $table->decimal('price', 12, 2)->nullable()->after('quantity'); // цена за единицу при этом приходе
        });
    }

    public function down(): void
    {
        Schema::table('materials', fn (Blueprint $table) => $table->dropColumn('price'));
        Schema::table('material_receipts', fn (Blueprint $table) => $table->dropColumn('price'));
    }
};
