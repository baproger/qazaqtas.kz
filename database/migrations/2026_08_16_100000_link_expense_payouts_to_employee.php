<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Выплата сотруднику связывается с человеком явно.
 *
 * Раньше сотрудник жил только строкой в описании («Аванс сотруднику: …»), а
 * `responsible_user_id` у таких расходов значил «кому выдали», хотя у расхода
 * по сделке та же колонка значит «кто потратил». Одна колонка с двумя
 * смыслами: имя устаревало при переименовании, фильтровать выплаты по
 * человеку было нельзя.
 *
 * `material_id` индексируется здесь же: по нему отбираются материальные
 * списания (карточка сделки, склад), а индекса на колонке не было.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->foreignId('employee_id')->nullable()->after('responsible_user_id')
                ->constrained('users')->nullOnDelete()->index();
            // advance | debt — вид выплаты; null у обычных расходов.
            $table->string('employee_payout', 10)->nullable()->after('employee_id')->index();
            $table->index('material_id');
        });

        // Восстанавливаем связь у существующих авансов: их расход уже знает
        // payroll_adjustments.expense_id. Текст описания не разбираем — только
        // явную связь, иначе можно приписать выплату не тому человеку.
        //
        // Обновляем построчно, а не UPDATE…JOIN: тесты идут на SQLite, а он
        // такой синтаксис не понимает. Таблица корректировок небольшая, и
        // проход разовый — на скорости это не отражается.
        DB::table('payroll_adjustments')
            ->whereNotNull('expense_id')
            ->orderBy('id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('expenses')->where('id', $row->expense_id)->update([
                        'employee_id' => $row->user_id,
                        'employee_payout' => 'advance',
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropIndex(['material_id']);
            $table->dropIndex(['employee_payout']);
            $table->dropColumn('employee_payout');
            $table->dropConstrainedForeignId('employee_id');
        });
    }
};
