<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\PayrollAdjustment;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Аванс сотруднику (корректировка ЗП) автоматически фиксируется в Расходах
 * на Финансах: подтверждённый расход категории «Расходы по сотрудникам».
 */
class AdvanceExpenseTest extends TestCase
{
    use RefreshDatabase;

    public function test_advance_creates_confirmed_expense_and_deletes_with_adjustment(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $fin = User::factory()->create();
        $fin->assignRole('financist');
        $emp = User::factory()->create(['name' => 'Иван Цех']);
        $emp->assignRole('employee');

        // Аванс 100 000 наличными.
        $this->actingAs($fin)->post(route('payroll.adjustments.store'), [
            'user_id' => $emp->id, 'type' => 'advance', 'amount' => 100000,
            'date' => '2026-07-24', 'note' => 'на бензин', 'payment_method' => 'cash',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $adj = PayrollAdjustment::first();
        $expense = Expense::find($adj->expense_id);
        $this->assertNotNull($expense);
        $this->assertSame('confirmed', $expense->status);
        $this->assertSame('cash', $expense->payment_method);
        $this->assertSame(100000.0, (float) $expense->amount);
        $this->assertStringContainsString('Иван Цех', $expense->description);
        $this->assertSame('Расходы по сотрудникам', \App\Models\ExpenseCategory::find($expense->category_id)->name);

        // Прочие типы корректировок расход НЕ создают.
        $this->actingAs($fin)->post(route('payroll.adjustments.store'), [
            'user_id' => $emp->id, 'type' => 'fine', 'amount' => 5000, 'date' => '2026-07-24',
        ])->assertRedirect();
        $this->assertSame(1, Expense::count());

        // Удалили аванс — расход тоже удалён (деньги «вернулись»).
        $this->actingAs($fin)->delete(route('payroll.adjustments.destroy', $adj->id))->assertRedirect();
        $this->assertNull(Expense::find($expense->id));
    }
}
