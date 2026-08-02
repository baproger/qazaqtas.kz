<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Расход по материалам: ссылка на позицию склада + количество.
     * Создание такого расхода списывает остаток, удаление — возвращает.
     */
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->foreignId('material_id')->nullable()->after('category_id')->constrained()->nullOnDelete();
            $table->decimal('qty', 12, 2)->nullable()->after('material_id');
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('material_id');
            $table->dropColumn('qty');
        });
    }
};
