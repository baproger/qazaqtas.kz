<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\EmployeeDebt;
use App\Models\EmployeeDebtPayment;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\EmployeeDebtService;
use App\Services\FinanceService;
use App\Services\PayrollService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Долги сотрудников: выдача из кассы и автогашение ТОЛЬКО из бонуса.
 *
 * Главное правило, которое здесь закрепляется: оклад долг не трогает. Нет
 * бонуса в месяце — нет удержания, остаток целиком едет дальше.
 */
class EmployeeDebtTest extends TestCase
{
    use RefreshDatabase;

    private User $accountant;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);

        $this->accountant = User::factory()->create();
        $this->accountant->assignRole('financist');
        $this->accountant->companies()->attach(Company::where('code', 'QT')->value('id'));

        $this->manager = User::factory()->create(['salary' => 200000]);
        $this->manager->assignRole('manager');
        $this->manager->companies()->attach(Company::where('code', 'QT')->value('id'));
    }

    /**
     * Выигранная и оплаченная сделка месяца — источник бонуса.
     *
     * Бонус теперь ставка от остатка (1% на своём производстве), поэтому
     * сделки в тестах крупнее: иначе бонус не покрывал бы платёж по долгу и
     * проверялось бы не то.
     */
    private function wonDeal(string $contractDate, float $budget = 5000000): Deal
    {
        $deal = Deal::create([
            'company_id' => Company::where('code', 'QT')->value('id'),
            'number' => 'QT-'.uniqid(), 'name' => 'Сделка', 'company_name' => 'Клиент',
            'budget' => $budget, 'status' => 'active', 'contract_date' => $contractDate,
            'deal_stage_id' => DealStage::where('is_won', true)->value('id'),
            'responsible_user_id' => $this->manager->id,
        ]);

        $invoice = Invoice::create([
            'invoiceable_type' => 'deal', 'invoiceable_id' => $deal->id,
            'number' => 'INV-'.uniqid(), 'amount' => $budget, 'status' => 'sent',
        ]);
        Payment::create([
            'invoice_id' => $invoice->id, 'amount' => $budget,
            'payment_date' => $contractDate, 'payment_method' => 'bank',
        ]);

        return $deal;
    }

    private function issueDebt(array $extra = []): EmployeeDebt
    {
        $this->actingAs($this->accountant)->post(route('payroll.debts.store'), array_merge([
            'user_id' => $this->manager->id,
            'amount' => 100000,
            'monthly_payment' => 30000,
            'payment_method' => 'cash',
            'note' => 'на ремонт машины',
        ], $extra))->assertSessionHasNoErrors();

        return EmployeeDebt::latest('id')->firstOrFail();
    }

    /** Выдача уменьшает кассу: это подтверждённый расход компании. */
    public function test_issuing_a_debt_takes_money_from_the_cash_desk(): void
    {
        $before = app(FinanceService::class)->companyBalances(null)['cash'];

        $debt = $this->issueDebt();

        $expense = Expense::findOrFail($debt->expense_id);
        $this->assertSame('confirmed', $expense->status);
        $this->assertSame('cash', $expense->payment_method);
        $this->assertSame($this->manager->id, $expense->employee_id);
        $this->assertSame('debt', $expense->employee_payout);

        $after = app(FinanceService::class)->companyBalances(null)['cash'];
        $this->assertSame(round($before - 100000, 2), round($after, 2));
    }

    /** Отмена выдачи убирает и расход — деньги вернулись. */
    public function test_cancelling_the_debt_removes_its_expense(): void
    {
        $debt = $this->issueDebt();

        $this->actingAs($this->accountant)->delete(route('payroll.debts.destroy', $debt->id))
            ->assertSessionHasNoErrors();

        $this->assertNull(EmployeeDebt::find($debt->id));
        $this->assertNull(Expense::find($debt->expense_id));
    }

    /** Расход-выдачу нельзя удалить отдельно: долг остался бы без денег. */
    public function test_the_issuing_expense_cannot_be_deleted_on_its_own(): void
    {
        $debt = $this->issueDebt();

        $this->actingAs($this->accountant)->delete(route('expenses.destroy', $debt->expense_id))
            ->assertSessionHasErrors('expense');

        $this->assertNotNull(Expense::find($debt->expense_id));
    }

    /** Платёж в месяц больше самого долга — ошибка, а не молчаливый приём. */
    public function test_monthly_payment_cannot_exceed_the_debt(): void
    {
        $this->actingAs($this->accountant)->post(route('payroll.debts.store'), [
            'user_id' => $this->manager->id,
            'amount' => 10000, 'monthly_payment' => 20000, 'payment_method' => 'cash',
        ])->assertSessionHasErrors('monthly_payment');
    }

    /** Долги выдаёт бухгалтер: менеджер сам себе долг не выпишет. */
    public function test_only_the_accountant_issues_debts(): void
    {
        $this->actingAs($this->manager)->post(route('payroll.debts.store'), [
            'user_id' => $this->manager->id,
            'amount' => 10000, 'monthly_payment' => 1000, 'payment_method' => 'cash',
        ])->assertForbidden();
    }

    /** Нет бонуса в месяце — удержания нет, остаток переехал целиком. */
    public function test_without_a_bonus_nothing_is_charged(): void
    {
        $debt = $this->issueDebt();

        $this->artisan('debts:charge', ['--month' => '2026-07'])->assertSuccessful();

        $this->assertSame(0, EmployeeDebtPayment::count());
        $this->assertSame(100000.0, $debt->fresh()->balance());
    }

    /** Удержание не больше бонуса месяца — оклад долг не трогает. */
    public function test_charge_never_exceeds_the_month_bonus(): void
    {
        // Небольшая сделка: бонус заведомо меньше платежа 30 000 ₸.
        $this->wonDeal('2026-07-10', 120000);
        $bonus = app(PayrollService::class)->bonusByUserForMonth($this->manager->id, '2026-07');
        $this->assertGreaterThan(0, $bonus);
        $this->assertLessThan(30000, $bonus);

        $debt = $this->issueDebt();
        $this->artisan('debts:charge', ['--month' => '2026-07'])->assertSuccessful();

        $payment = EmployeeDebtPayment::firstOrFail();
        $this->assertSame(round($bonus, 2), (float) $payment->amount);
        $this->assertSame(round(100000 - $bonus, 2), $debt->fresh()->balance());
    }

    /** Повторный прогон не спишет второй раз — держит unique(долг, месяц). */
    public function test_second_run_is_idempotent(): void
    {
        $this->wonDeal('2026-07-10');
        $debt = $this->issueDebt();

        $this->artisan('debts:charge', ['--month' => '2026-07'])->assertSuccessful();
        $balance = $debt->fresh()->balance();
        $this->artisan('debts:charge', ['--month' => '2026-07'])->assertSuccessful();

        $this->assertSame(1, EmployeeDebtPayment::count());
        $this->assertSame($balance, $debt->fresh()->balance());
    }

    /** Пропущенный месяц догоняется параметром --month. */
    public function test_month_option_catches_up_a_missed_month(): void
    {
        $this->wonDeal('2026-06-10');
        $this->wonDeal('2026-07-10');
        $debt = $this->issueDebt();

        $this->artisan('debts:charge', ['--month' => '2026-06'])->assertSuccessful();
        $this->artisan('debts:charge', ['--month' => '2026-07'])->assertSuccessful();

        $this->assertSame(['2026-06', '2026-07'], EmployeeDebtPayment::orderBy('month')->pluck('month')->all());
        $this->assertSame(round(100000 - 60000, 2), $debt->fresh()->balance());
    }

    /** Полное погашение закрывает долг. */
    public function test_debt_closes_when_fully_repaid(): void
    {
        $this->wonDeal('2026-07-10');
        $debt = $this->issueDebt(['amount' => 20000, 'monthly_payment' => 20000]);

        $this->artisan('debts:charge', ['--month' => '2026-07'])->assertSuccessful();

        $debt->refresh();
        $this->assertSame(0.0, $debt->balance());
        $this->assertTrue($debt->isClosed());
    }

    /** «К выплате» в ведомости уменьшается на план удержания этого месяца. */
    public function test_payroll_subtracts_the_planned_charge(): void
    {
        $this->wonDeal('2026-07-10');
        $this->issueDebt();

        $plan = app(EmployeeDebtService::class)->planFor($this->manager->id, '2026-07');
        $this->assertSame(30000.0, $plan['charge'], 'Платёж месяца укладывается в бонус.');

        $this->actingAs($this->accountant)->get(route('payroll.index', ['month' => '2026-07']))
            ->assertInertia(fn ($page) => $page
                ->where('rows', function ($rows) {
                    $row = collect($rows)->firstWhere('uid', $this->manager->id);

                    return (float) $row['debt_charge'] === 30000.0
                        && round((float) $row['final'], 2) === round((float) $row['payout'] - 30000, 2);
                }));
    }

    /** Аванс и долг независимы: аванс не гасит долг и наоборот. */
    public function test_advance_and_debt_do_not_cancel_each_other(): void
    {
        $this->wonDeal('2026-07-10');
        $this->issueDebt();

        $this->actingAs($this->accountant)->post(route('payroll.adjustments.store'), [
            'user_id' => $this->manager->id,
            'type' => 'advance',
            'amount' => 50000,
            'date' => '2026-07-15',
            'payment_method' => 'cash',
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->accountant)->get(route('payroll.index', ['month' => '2026-07']))
            ->assertInertia(fn ($page) => $page
                ->where('rows', function ($rows) {
                    $row = collect($rows)->firstWhere('uid', $this->manager->id);

                    // Аванс — в удержаниях, долг — отдельной строкой расчёта.
                    return (float) $row['deductions'] === 50000.0
                        && (float) $row['debt_charge'] === 30000.0;
                }));
    }
}
