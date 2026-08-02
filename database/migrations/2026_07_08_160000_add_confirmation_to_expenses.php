<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Этап 4: прочий расход менеджера ждёт подтверждения бухгалтера (status
     * 'pending'); бухгалтер подтверждает с чеком и способом оплаты нал/банк.
     */
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->string('payment_method', 10)->nullable()->after('status'); // cash | bank
            $table->foreignId('confirmed_by')->nullable()->after('payment_method')->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable()->after('confirmed_by');
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('confirmed_by');
            $table->dropColumn(['payment_method', 'confirmed_at']);
        });
    }
};
