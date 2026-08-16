<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Долг сотрудника перед компанией.
 *
 * Отличается от аванса тем, что переходит из месяца в месяц: бухгалтер
 * выдаёт сумму и фиксированный платёж в месяц, дальше долг гасится сам —
 * ТОЛЬКО из бонуса, оклад не трогается никогда.
 *
 * Погашения хранятся помесячно с уникальным ключом (долг, месяц): на нём
 * держится идемпотентность команды `debts:charge`. Повторный прогон не
 * спишет второй раз — база не даст, а не «если» в коде.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_debts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete()->index();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 15, 2);
            $table->decimal('monthly_payment', 15, 2);
            $table->string('payment_method', 4); // cash | bank
            // Расход выдачи: удаление долга уносит и его, деньги возвращаются.
            $table->foreignId('expense_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('employee_debt_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_debt_id')->constrained()->cascadeOnDelete();
            $table->char('month', 7); // YYYY-MM
            $table->decimal('amount', 15, 2);
            $table->timestamps();

            $table->unique(['employee_debt_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_debt_payments');
        Schema::dropIfExists('employee_debts');
    }
};
