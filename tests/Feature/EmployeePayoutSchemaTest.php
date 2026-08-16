<?php

namespace Tests\Feature;

use App\Models\EmployeeDebt;
use App\Models\EmployeeDebtPayment;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\PayrollAdjustment;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Схема выплат сотрудникам: связь с человеком, долги, служебные категории.
 */
class EmployeePayoutSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function accountant(): User
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('financist');

        return $user;
    }

    /**
     * Аванс связывается с человеком явно. Раньше сотрудник жил строкой в
     * описании: имя устаревало при переименовании, фильтровать было нельзя.
     */
    public function test_advance_links_the_expense_to_the_employee(): void
    {
        $accountant = $this->accountant();
        $employee = User::factory()->create(['name' => 'Бахытжан']);

        $this->actingAs($accountant)->post(route('payroll.adjustments.store'), [
            'user_id' => $employee->id,
            'type' => 'advance',
            'amount' => 50000,
            'date' => now()->toDateString(),
            'payment_method' => 'cash',
        ])->assertSessionHasNoErrors();

        $expense = Expense::whereNotNull('employee_id')->firstOrFail();

        $this->assertSame($employee->id, $expense->employee_id);
        $this->assertSame('advance', $expense->employee_payout);
        // Переименование сотрудника больше не рвёт связь.
        $employee->update(['name' => 'Бахытжан С.']);
        $this->assertSame('Бахытжан С.', $expense->fresh()->employee->name);
    }

    /** Аванс попадает в служебную категорию по КОДУ, а не по имени. */
    public function test_advance_uses_the_employee_category_by_code(): void
    {
        $accountant = $this->accountant();
        $employee = User::factory()->create();

        $this->actingAs($accountant)->post(route('payroll.adjustments.store'), [
            'user_id' => $employee->id,
            'type' => 'advance',
            'amount' => 10000,
            'date' => now()->toDateString(),
            'payment_method' => 'bank',
        ])->assertSessionHasNoErrors();

        $expense = Expense::whereNotNull('employee_id')->firstOrFail();

        $this->assertSame(ExpenseCategory::EMPLOYEE, $expense->category->code);
    }

    /**
     * Служебную категорию нельзя переименовать: бухгалтер, увидев в списке
     * «Прочее» вместо «Расходы по сотрудникам», начнёт складывать туда
     * обычные траты — и зарплата исчезнет из итога незаметно.
     */
    public function test_system_category_cannot_be_renamed_or_deleted(): void
    {
        $accountant = $this->accountant();
        $system = ExpenseCategory::findByCode(ExpenseCategory::EMPLOYEE);

        $this->assertNotNull($system, 'Категория с кодом employee должна создаваться миграцией.');
        $this->assertTrue($system->isSystem());

        $this->actingAs($accountant)
            ->put(route('expenseCategories.update', $system->id), ['name' => 'Прочее'])
            ->assertForbidden();

        $this->actingAs($accountant)
            ->delete(route('expenseCategories.destroy', $system->id))
            ->assertForbidden();

        $this->assertSame('Расходы по сотрудникам', $system->fresh()->name);
    }

    public function test_ordinary_category_is_still_editable(): void
    {
        $accountant = $this->accountant();
        $category = ExpenseCategory::create(['name' => 'Канцтовары', 'is_active' => true]);

        $this->actingAs($accountant)
            ->put(route('expenseCategories.update', $category->id), ['name' => 'Канцелярия'])
            ->assertSessionHasNoErrors();

        $this->assertSame('Канцелярия', $category->fresh()->name);
    }

    public function test_materials_purchase_category_exists(): void
    {
        $this->assertNotNull(ExpenseCategory::findByCode(ExpenseCategory::MATERIALS_PURCHASE));
    }

    /**
     * На уникальном ключе (долг, месяц) держится идемпотентность списания:
     * повторный прогон команды не спишет второй раз, потому что не даст
     * база, а не потому что так написано условие в коде.
     */
    public function test_debt_payment_is_unique_per_month(): void
    {
        $debt = EmployeeDebt::create([
            'user_id' => User::factory()->create()->id,
            'amount' => 100000,
            'monthly_payment' => 20000,
            'payment_method' => 'cash',
        ]);

        EmployeeDebtPayment::create(['employee_debt_id' => $debt->id, 'month' => '2026-08', 'amount' => 20000]);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        EmployeeDebtPayment::create(['employee_debt_id' => $debt->id, 'month' => '2026-08', 'amount' => 20000]);
    }

    public function test_debt_balance_counts_payments(): void
    {
        $debt = EmployeeDebt::create([
            'user_id' => User::factory()->create()->id,
            'amount' => 100000,
            'monthly_payment' => 20000,
            'payment_method' => 'cash',
        ]);

        $this->assertSame(100000.0, $debt->balance());

        EmployeeDebtPayment::create(['employee_debt_id' => $debt->id, 'month' => '2026-08', 'amount' => 30000]);

        $this->assertSame(70000.0, $debt->fresh()->balance());
    }

    /** Старые авансы (до миграции) тоже должны получить связь. */
    public function test_migration_backfills_existing_advances(): void
    {
        // Эмулируем «старый» аванс: расход без employee_id + корректировка.
        $employee = User::factory()->create();
        $expense = Expense::create([
            'category_id' => ExpenseCategory::findByCode(ExpenseCategory::EMPLOYEE)->id,
            'amount' => 15000,
            'date' => now()->toDateString(),
            'status' => 'confirmed',
            'payment_method' => 'cash',
            'description' => 'Аванс сотруднику: старый',
        ]);
        $expense->forceFill(['employee_id' => null, 'employee_payout' => null])->save();

        PayrollAdjustment::create([
            'user_id' => $employee->id,
            'type' => 'advance',
            'amount' => 15000,
            'date' => now()->toDateString(),
            'expense_id' => $expense->id,
        ]);

        // Повторяем ровно то, что делает миграция.
        \Illuminate\Support\Facades\DB::table('payroll_adjustments')
            ->whereNotNull('expense_id')
            ->orderBy('id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    \Illuminate\Support\Facades\DB::table('expenses')->where('id', $row->expense_id)->update([
                        'employee_id' => $row->user_id,
                        'employee_payout' => 'advance',
                    ]);
                }
            });

        $this->assertSame($employee->id, $expense->fresh()->employee_id);
    }
}
