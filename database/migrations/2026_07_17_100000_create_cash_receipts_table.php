<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Поступления денег (вводит финансист): сумма, нал/банк, откуда, дата,
 * комментарий. Формируют остатки кассы/банка на Финансах вместе с
 * платежами по счетам; расходы списываются с этих остатков по способу оплаты.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 14, 2);
            $table->string('method', 10); // cash | bank
            $table->string('source');     // откуда поступили деньги
            $table->date('date');
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_receipts');
    }
};
