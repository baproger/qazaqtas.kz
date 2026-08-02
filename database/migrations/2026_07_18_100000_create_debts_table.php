<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Задолженности (ведёт финансист): receivable — дебиторка (кто должен НАМ,
 * вручную, в дополнение к автоматической по счетам сделок); payable —
 * кредиторка (кому должны МЫ). Удаление шлёт уведомление СЕО/директору.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('debts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 15); // receivable | payable
            $table->string('counterparty'); // кто должен / кому должны
            $table->decimal('amount', 14, 2);
            $table->date('date')->nullable();
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('debts');
    }
};
