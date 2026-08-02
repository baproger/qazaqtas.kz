<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Чат: корзина (мягкое удаление с восстановлением) + привязка группы к
// компании BAIA/ASU (null = видна сотрудникам обеих фирм).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chats', function (Blueprint $table) {
            $table->softDeletes();
            $table->foreignId('company_id')->nullable()->after('type')
                ->constrained('companies')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('chats', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_id');
            $table->dropSoftDeletes();
        });
    }
};
