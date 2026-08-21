<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Выплаты бонуса по месяцам.
 *
 * Бонус не обязательно забирают сразу: сотрудник может копить его месяцами и
 * получить разом. Значит нужно различать НАЧИСЛЕНО (сделки принесли) и
 * ВЫПЛАЧЕНО (деньги отданы) — иначе «к выплате» показывает одно и то же
 * каждый месяц, и понять, что человеку ещё должны, нельзя.
 *
 * Строк на месяц может быть несколько: бонус выдают и частями.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bonus_payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            // Месяц, ЗА который выплачен бонус (YYYY-MM), а не дата выдачи.
            $table->char('month', 7);
            $table->decimal('amount', 15, 2);
            $table->string('payment_method', 4);  // cash | bank
            // Расход, которым деньги ушли из кассы; удалили расход — выплата
            // остаётся видна как строка, но без денег (nullOnDelete).
            $table->foreignId('expense_id')->nullable()->constrained('expenses')->nullOnDelete();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bonus_payouts');
    }
};
