<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * expenses.type был enum('direct','indirect') — новые виды расхода
 * «доставка»/«закуп» (delivery/purchase) в него не влезали (MySQL 1265
 * Data truncated). Меняем на string: значения направляет валидация
 * ExpenseRequest (direct | indirect | delivery | purchase).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->string('type', 20)->default('direct')->change();
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->enum('type', ['direct', 'indirect'])->default('direct')->change();
        });
    }
};
