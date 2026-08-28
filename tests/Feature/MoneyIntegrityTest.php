<?php

namespace Tests\Feature;

use App\Models\CashReceipt;
use App\Models\Company;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\FinanceService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Целостность денег: переплата счёта, дубли форм, гонка «В работу ✓»,
 * дебиторка без отменённых счетов и итог без двойного счёта ЗП.
 *
 * Всё это — ошибки, которые не видно глазами: цифра просто становится не той,
 * и спорить потом приходится о том, какая страница врёт.
 */
class MoneyIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private User $accountant;

    private Deal $deal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);

        $this->accountant = User::factory()->create();
        $this->accountant->assignRole('financist');
        $this->accountant->companies()->attach(Company::where('code', 'QT')->value('id'));

        $this->deal = Deal::create([
            'company_id' => Company::where('code', 'QT')->value('id'),
            'number' => 'QT-1', 'name' => 'Сделка', 'company_name' => 'Клиент',
            'budget' => 100000, 'status' => 'active',
            'deal_stage_id' => DealStage::orderBy('order')->value('id'),
            'responsible_user_id' => $this->accountant->id,
        ]);
    }

    private function invoice(float $amount = 100000, string $status = 'sent'): Invoice
    {
        return Invoice::create([
            'invoiceable_type' => 'deal', 'invoiceable_id' => $this->deal->id,
            'number' => 'INV-'.uniqid(), 'amount' => $amount, 'status' => $status,
        ]);
    }

    /** Двойной клик по «Оплатить»: второй платёж отбивается остатком счёта. */
    public function test_double_submit_cannot_pay_the_invoice_twice(): void
    {
        $invoice = $this->invoice(50000);
        $payload = [
            'invoice_id' => $invoice->id, 'amount' => 50000,
            'payment_date' => now()->toDateString(), 'payment_method' => 'bank',
        ];

        $this->actingAs($this->accountant)->post(route('payments.store'), $payload)
            ->assertSessionHasNoErrors();
        $this->actingAs($this->accountant)->post(route('payments.store'), $payload)
            ->assertSessionHasErrors('amount');

        $this->assertSame(1, Payment::where('invoice_id', $invoice->id)->count());
        $this->assertSame(50000.0, (float) Payment::where('invoice_id', $invoice->id)->sum('amount'));
    }

    /** Платёж больше остатка не проходит — переплаты по счёту не бывает. */
    public function test_payment_cannot_exceed_the_remaining_amount(): void
    {
        $invoice = $this->invoice(30000);

        $this->actingAs($this->accountant)->post(route('payments.store'), [
            'invoice_id' => $invoice->id, 'amount' => 30001,
            'payment_date' => now()->toDateString(), 'payment_method' => 'cash',
        ])->assertSessionHasErrors('amount');

        $this->assertSame(0, Payment::count());
    }

    /** Частичная оплата разрешена, остаток можно доплатить. */
    public function test_partial_payments_still_work(): void
    {
        $invoice = $this->invoice(10000);

        foreach ([4000, 6000] as $amount) {
            $this->actingAs($this->accountant)->post(route('payments.store'), [
                'invoice_id' => $invoice->id, 'amount' => $amount,
                'payment_date' => now()->toDateString(), 'payment_method' => 'bank',
            ])->assertSessionHasNoErrors();
        }

        $this->assertSame(10000.0, (float) Payment::where('invoice_id', $invoice->id)->sum('amount'));
        $this->assertSame('paid', $invoice->fresh()->status);
    }

    /** Повторная отправка формы поступления не создаёт второе поступление. */
    public function test_receipt_form_resubmit_is_refused(): void
    {
        $payload = [
            'amount' => 25000, 'method' => 'cash', 'source' => 'учредитель',
            'date' => now()->toDateString(),
        ];

        $this->actingAs($this->accountant)->post(route('finance.receipts.store'), $payload)
            ->assertSessionHasNoErrors();
        $this->actingAs($this->accountant)->post(route('finance.receipts.store'), $payload)
            ->assertSessionHasErrors('amount');

        $this->assertSame(1, CashReceipt::count());
    }

    /** То же для расходов: тот же автор, сумма, дата и описание за минуту. */
    public function test_expense_form_resubmit_is_refused(): void
    {
        $category = ExpenseCategory::create(['name' => 'Канцтовары', 'is_active' => true]);
        $payload = [
            'category_id' => $category->id, 'amount' => 3000,
            'date' => now()->toDateString(), 'description' => 'бумага',
        ];

        $this->actingAs($this->accountant)->post(route('expenses.store'), $payload)
            ->assertSessionHasNoErrors();
        $this->actingAs($this->accountant)->post(route('expenses.store'), $payload)
            ->assertSessionHasErrors('amount');

        $this->assertSame(1, Expense::count());
    }

    /** Другая сумма — не дубль: обычную работу анти-дубль не мешает. */
    public function test_different_amount_is_not_a_duplicate(): void
    {
        $category = ExpenseCategory::create(['name' => 'Канцтовары', 'is_active' => true]);

        foreach ([3000, 4000] as $amount) {
            $this->actingAs($this->accountant)->post(route('expenses.store'), [
                'category_id' => $category->id, 'amount' => $amount,
                'date' => now()->toDateString(), 'description' => 'бумага',
            ])->assertSessionHasNoErrors();
        }

        $this->assertSame(2, Expense::count());
    }

    /** Отменённый счёт клиент не должен: в дебиторку он не идёт. */
    public function test_cancelled_invoice_is_not_a_receivable(): void
    {
        $this->invoice(70000);
        $this->invoice(30000, 'cancelled');

        $this->actingAs($this->accountant)->get(route('finance.index'))
            ->assertInertia(fn ($page) => $page
                ->where('invoiceTotals.invoiced', 70000)
                ->where('invoiceTotals.debt', 70000));
    }

    /**
     * Выплаты сотрудникам видны в разбивке с пометкой, но в итог «Расходы» не
     * входят: зарплата стоит там отдельной строкой.
     */
    public function test_employee_payouts_do_not_double_count_in_the_total(): void
    {
        $employee = ExpenseCategory::firstOrCreate(
            ['code' => ExpenseCategory::EMPLOYEE],
            ['name' => 'Расходы по сотрудникам', 'is_active' => true],
        );
        $other = ExpenseCategory::create(['name' => 'Аренда', 'is_active' => true]);

        foreach ([[$employee->id, 40000], [$other->id, 15000]] as [$categoryId, $amount]) {
            Expense::create([
                'company_id' => Company::where('code', 'QT')->value('id'),
                'category_id' => $categoryId, 'amount' => $amount,
                'date' => now()->toDateString(), 'status' => 'confirmed',
                'payment_method' => 'cash', 'responsible_user_id' => $this->accountant->id,
            ]);
        }

        $this->actingAs($this->accountant)->get(route('finance.index'))
            ->assertInertia(fn ($page) => $page
                ->where('summary.categories', function ($rows) {
                    $rows = collect($rows);

                    return $rows->firstWhere('name', 'Расходы по сотрудникам')['in_payroll'] === true
                        && $rows->firstWhere('name', 'Аренда')['in_payroll'] === false;
                })
                // В итоге — только «Аренда»: 40 000 выплат уже учтены строкой ЗП.
                ->where('summary.expensesTotal', 15000));
    }

    /** Касса от выплат сотрудникам уменьшается честно — это реальные деньги. */
    public function test_employee_payout_still_reduces_the_cash(): void
    {
        $employee = ExpenseCategory::firstOrCreate(
            ['code' => ExpenseCategory::EMPLOYEE],
            ['name' => 'Расходы по сотрудникам', 'is_active' => true],
        );

        $before = app(FinanceService::class)->companyBalances(null)['cash'];

        Expense::create([
            'company_id' => Company::where('code', 'QT')->value('id'),
            'category_id' => $employee->id, 'amount' => 40000,
            'date' => now()->toDateString(), 'status' => 'confirmed',
            'payment_method' => 'cash', 'responsible_user_id' => $this->accountant->id,
        ]);

        $after = app(FinanceService::class)->companyBalances(null)['cash'];
        $this->assertSame(round($before - 40000, 2), round($after, 2));
    }

    /** Самопроверка зелёная на нормальных данных. */
    public function test_selfcheck_passes_on_consistent_data(): void
    {
        $invoice = $this->invoice(20000);
        Payment::create([
            'invoice_id' => $invoice->id, 'amount' => 20000,
            'payment_date' => now()->toDateString(), 'payment_method' => 'cash',
        ]);

        $this->artisan('finance:selfcheck')->assertSuccessful();
    }

    /** И красная, если деньги разошлись (правка мимо интерфейса). */
    public function test_selfcheck_catches_an_overpaid_invoice(): void
    {
        $invoice = $this->invoice(10000);
        // Мимо контроллера — как ручная правка в базе.
        Payment::create([
            'invoice_id' => $invoice->id, 'amount' => 15000,
            'payment_date' => now()->toDateString(), 'payment_method' => 'cash',
        ]);

        $this->artisan('finance:selfcheck')->assertFailed();
    }
}
