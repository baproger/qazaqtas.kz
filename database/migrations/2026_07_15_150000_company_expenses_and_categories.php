<?php

use App\Models\ExpenseCategory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Расходы КОМПАНИИ (без сделки): аренда, комуслуги, интернет, бензин и т.п.
 * - expenses.company_id — фирма расхода без сделки (у сделочных фирма
 *   определяется через сделку);
 * - сидим базовые категории (идемпотентно) — появятся на проде при деплое.
 */
return new class extends Migration
{
    private const CATEGORIES = [
        'Аренда', 'Комуслуги', 'Интернет', 'Бензин / ГСМ', 'Канцтовары',
        'Хозтовары', 'Продукты питания', 'Офисные расходы', 'Налоги', 'Кредит', 'Прочее',
    ];

    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('expenseable_id')
                ->constrained('companies')->nullOnDelete();
        });

        foreach (self::CATEGORIES as $name) {
            ExpenseCategory::firstOrCreate(['name' => $name], ['is_active' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_id');
        });
    }
};
