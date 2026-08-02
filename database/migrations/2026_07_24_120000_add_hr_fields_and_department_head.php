<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// HR-поля сотрудника (день рождения, дата приёма) + руководитель отдела.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->date('birth_date')->nullable()->after('phone');
            $table->date('hired_at')->nullable()->after('birth_date');
        });
        Schema::table('departments', function (Blueprint $table) {
            // nullOnDelete: удалили сотрудника — отдел просто без руководителя.
            $table->foreignId('head_user_id')->nullable()->after('description')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('head_user_id');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['birth_date', 'hired_at']);
        });
    }
};
