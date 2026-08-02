<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ДДС — ручная сводка финансиста на странице Финансы (как Excel-таблица):
 * счета компаний (банк / фактический остаток / дебиторский) и долги.
 * НАМЕРЕННО без связей со сделками/платежами — цифры вводятся и живут сами.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dds_entries', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20); // account (компания/банк) | debt (долг)
            $table->string('name');
            $table->string('bank')->nullable();
            $table->decimal('balance', 15, 2)->nullable();     // фактический остаток
            $table->decimal('receivable', 15, 2)->nullable();  // дебиторский
            $table->decimal('amount', 15, 2)->nullable();      // сумма долга (kind=debt)
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
            $table->index(['kind', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dds_entries');
    }
};
