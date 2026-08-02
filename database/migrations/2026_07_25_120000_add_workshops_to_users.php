<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Доступ сотрудника к цехам BAIA (Металл / Ағаш): JSON-список названий цехов.
 * null или пустой список = ограничения нет (все цеха; так работают
 * руководство, ASU и все существующие сотрудники — поведение не меняется).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('workshops')->nullable()->after('department_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('workshops');
        });
    }
};
