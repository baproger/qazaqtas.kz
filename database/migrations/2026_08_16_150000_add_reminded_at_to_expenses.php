<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Отметка о напоминании по залежавшейся заявке.
 *
 * Напоминаем один раз: ежедневный повтор превращается в шум, который
 * перестают читать, — а заявка сотрудника, ждущего свои деньги, требует
 * внимания, а не привычки.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->timestamp('reminded_at')->nullable()->after('confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('expenses', fn (Blueprint $table) => $table->dropColumn('reminded_at'));
    }
};
