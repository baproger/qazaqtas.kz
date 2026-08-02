<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Аванс сотруднику (корректировка ЗП) — это реальные деньги из кассы/банка:
 * при вводе аванса автоматически создаётся подтверждённый расход компании
 * (категория «Расходы по сотрудникам») — Финансы видят полную картину.
 * expense_id — связь корректировки с расходом (удалили аванс — удалился расход).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_adjustments', function (Blueprint $table) {
            $table->foreignId('expense_id')->nullable()->after('note')
                ->constrained('expenses')->nullOnDelete();
            $table->string('payment_method', 10)->nullable()->after('expense_id'); // cash | bank
        });
    }

    public function down(): void
    {
        Schema::table('payroll_adjustments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('expense_id');
            $table->dropColumn('payment_method');
        });
    }
};
