<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Компании (фирмы). У QAZAQ TAS сейчас одна фирма, но система остаётся
 * мультикомпанийной: у каждой фирмы свои сделки, воронки, склад и финансы —
 * чтобы добавить вторую, достаточно новой строки в companies; код фирмы
 * становится префиксом номеров сделок (QT-001).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // Код — префикс номера сделки: QT-001.
            $table->string('code', 20)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('company_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unique(['company_id', 'user_id']);
            $table->timestamps();
        });

        Schema::table('deals', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('id')->constrained()->nullOnDelete();
        });

        $now = now();
        DB::table('companies')->insert([
            ['name' => 'QAZAQ TAS', 'code' => 'QT', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);
        $companyId = DB::table('companies')->where('code', 'QT')->value('id');
        DB::table('deals')->update(['company_id' => $companyId]);

        // Все существующие пользователи получают доступ к фирме; сузить состав
        // можно на странице «Сотрудники».
        $rows = [];
        foreach (DB::table('users')->pluck('id') as $userId) {
            $rows[] = ['company_id' => $companyId, 'user_id' => $userId, 'created_at' => $now, 'updated_at' => $now];
        }
        if ($rows) {
            DB::table('company_user')->insert($rows);
        }
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_id');
        });
        Schema::dropIfExists('company_user');
        Schema::dropIfExists('companies');
    }
};
