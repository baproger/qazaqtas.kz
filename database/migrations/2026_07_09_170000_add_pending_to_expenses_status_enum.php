<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Этап 4 ввёл статус 'pending' (расход менеджера ждёт бухгалтера), но ENUM
     * колонки status остался ('draft','confirmed') — MySQL обрезал значение
     * (Data truncated). На SQLite (тесты) enum не проверяется, поэтому баг
     * всплыл только на живой базе.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE expenses MODIFY status ENUM('draft', 'pending', 'confirmed') NOT NULL DEFAULT 'draft'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            // pending некуда девать в старом ENUM — переводим в draft.
            DB::table('expenses')->where('status', 'pending')->update(['status' => 'draft']);
            DB::statement("ALTER TABLE expenses MODIFY status ENUM('draft', 'confirmed') NOT NULL DEFAULT 'draft'");
        }
    }
};
