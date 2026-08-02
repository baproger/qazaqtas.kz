<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Корректировки ЗП за месяц: отгул / больничный / штраф / премия.
 * Вводит бухгалтер (financist) или админ; отгул/больничный можно задать
 * днями — сумма удержания считается автоматически: оклад / 22 × дни.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20); // absence | sick | fine | bonus
            $table->decimal('days', 5, 2)->nullable();
            $table->decimal('amount', 14, 2);
            $table->date('date');
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['user_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_adjustments');
    }
};
