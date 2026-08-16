<?php

use App\Models\ExpenseCategory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Служебный код категории расхода.
 *
 * Две категории несут логику, а не только название: «Расходы по сотрудникам»
 * (авансы и долги — исключается из итога «Расходы», чтобы зарплата не
 * считалась дважды) и «Закуп материалов» (оплата склада при приходе).
 * Искать их по имени нельзя: имя владелец правит из админки. Код —
 * неизменный ключ, и категорию с кодом нельзя переименовать или удалить.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_categories', function (Blueprint $table) {
            $table->string('code', 40)->nullable()->unique()->after('id');
        });

        // «Расходы по сотрудникам» уже существует у всех установок — находим
        // по имени ОДИН раз, дальше живём по коду.
        $employee = DB::table('expense_categories')->where('name', 'Расходы по сотрудникам')->first();

        if ($employee) {
            DB::table('expense_categories')->where('id', $employee->id)
                ->update(['code' => ExpenseCategory::EMPLOYEE]);
        } else {
            DB::table('expense_categories')->insert([
                'code' => ExpenseCategory::EMPLOYEE,
                'name' => 'Расходы по сотрудникам',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if (! DB::table('expense_categories')->where('code', ExpenseCategory::MATERIALS_PURCHASE)->exists()) {
            DB::table('expense_categories')->insert([
                'code' => ExpenseCategory::MATERIALS_PURCHASE,
                'name' => 'Закуп материалов',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('expense_categories', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->dropColumn('code');
        });
    }
};
