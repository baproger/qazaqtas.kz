<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Персональный код входа ключевых сотрудников.
 *
 * В базе лежит только хэш (bcrypt), сам код показывается администратору
 * один раз при выдаче. Наличие хэша = вход в два шага: пароль, затем код.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('access_code')->nullable()->after('remember_token');
            $table->timestamp('access_code_issued_at')->nullable()->after('access_code');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['access_code', 'access_code_issued_at']);
        });
    }
};
