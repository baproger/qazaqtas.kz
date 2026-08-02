<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('department_id')->nullable()->after('email')
                ->constrained('departments')->nullOnDelete();
            $table->string('phone')->nullable()->after('department_id');
            $table->string('avatar')->nullable()->after('phone');
            $table->string('language', 5)->default('ru')->after('avatar');
            $table->boolean('is_active')->default(true)->index()->after('language');
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['department_id']);
            $table->dropColumn(['department_id', 'phone', 'avatar', 'language', 'is_active', 'deleted_at']);
        });
    }
};
