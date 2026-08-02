<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Почасовой расчёт ЗП (Excel владельца): отработанные часы за месяц вводятся
// вручную, ставка/час = оклад ÷ норма часов месяца, начислено = часы × ставка.
// Часы не введены — платится полный оклад (почасовой режим по сотруднику опционален).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('month', 7); // YYYY-MM
            $table->decimal('hours', 6, 2);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_hours');
    }
};
