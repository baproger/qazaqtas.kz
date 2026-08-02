<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Экраны цехов (ТВ-мониторы): у каждого цеха свой код доступа. На мониторе
 * открывают /screen, вводят код — видят канбан ТОЛЬКО своего цеха (без сумм).
 * Коды выдаёт админ в Настройки → Этапы → Цех.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workshop_screens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('workshop', 100)->nullable(); // null = единый цех компании
            $table->string('code', 20)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'workshop']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workshop_screens');
    }
};
