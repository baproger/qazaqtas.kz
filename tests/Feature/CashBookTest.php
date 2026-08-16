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
 * Кассовая книга: остаток на начало → операции дня → остаток на конец.
 * Её цифры обязаны сходиться с плитками Финансов — иначе владелец не знает,
 * какой странице верить.
 */
class CashBookTest extends TestCase
{
    use RefreshDatabase;

    private User $accountant;

    private Deal $deal;

    private ExpenseCategory $category;

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
            'budget' => 500000, 'status' => 'active',
            'deal_stage_id' => DealStage::orderBy('order')->value('id'),
            'responsible_user_id' => $this->accountant->id,
        ]);

        $this->category = ExpenseCategory::create(['name' => 'Канцтовары', 'is_active' => true]);
    }

    private function payment(string $date, float $amount, string $method): Payment
    {
        $invoice = Invoice::create([
            'invoiceable_type' => 'deal', 'invoiceable_id' => $this->deal->id,
            'number' => 'INV-'.uniqid(), 'amount' => $amount, 'status' => 'sent',
            'issue_date' => $date, 'due_date' => $date,
        ]);

        return Payment::create([
            'invoice_id' => $invoice->id, 'amount' => $amount,
            'payment_date' => $date, 'payment_method' => $method,
        ]);
    }

    private function expense(string $date, float $amount, string $method): Expense
    {
        return Expense::create([
            'company_id' => Company::where('code', 'QT')->value('id'),
            'category_id' => $this->category->id,
            'responsible_user_id' => $this->accountant->id,
            'amount' => $amount, 'date' => $date, 'description' => 'расход',
            'status' => 'confirmed', 'payment_method' => $method,
            'confirmed_by' => $this->accountant->id, 'confirmed_at' => now(),
        ]);
    }

    private function receipt(string $date, float $amount, string $method): CashReceipt
    {
        return CashReceipt::create([
            'company_id' => Company::where('code', 'QT')->value('id'),
            'amount' => $amount, 'method' => $method, 'source' => 'учредитель',
            'date' => $date, 'created_by' => $this->accountant->id,
        ]);
    }

    /** Конец дня N == начало дня N+1: книга стыкуется день в день. */
    public function test_closing_balance_becomes_the_next_days_opening(): void
    {
        $day = now()->toDateString();
        $next = now()->addDay()->toDateString();

        $this->payment($day, 100000, 'cash');
        $this->expense($day, 30000, 'cash');

        $closing = null;
        $this->actingAs($this->accountant)->get(route('cashBook.index', ['date' => $day, 'mode' => 'cash']))
            ->assertInertia(function ($page) use (&$closing) {
                $closing = $page->toArray()['props']['totals']['closing'];
            });

        $this->assertSame(70000.0, (float) $closing);

        $this->actingAs($this->accountant)->get(route('cashBook.index', ['date' => $next, 'mode' => 'cash']))
            ->assertInertia(fn ($page) => $page->where('totals.opening', $closing));
    }

    /** Заявка, ждущая бухгалтера, денег не двигала — в книге её нет. */
    public function test_pending_expense_never_enters_the_book(): void
    {
        $day = now()->toDateString();
        $this->expense($day, 5000, 'cash');
        Expense::create([
            'company_id' => Company::where('code', 'QT')->value('id'),
            'category_id' => $this->category->id,
            'responsible_user_id' => $this->accountant->id,
            'amount' => 99000, 'date' => $day, 'description' => 'заявка',
            'status' => 'pending',
        ]);

        $this->actingAs($this->accountant)->get(route('cashBook.index', ['date' => $day, 'mode' => 'cash']))
            ->assertInertia(fn ($page) => $page
                ->has('rows', 1)
                ->where('totals.outcome', 5000));
    }

    /** «Общее» == Наличные + Банк, каждый поток по своему правилу. */
    public function test_all_mode_is_cash_plus_bank(): void
    {
        $day = now()->toDateString();
        $this->payment($day, 40000, 'cash');
        $this->payment($day, 60000, 'bank');
        $this->expense($day, 10000, 'cash');
        $this->receipt($day, 5000, 'bank');

        $totals = [];
        foreach (['cash', 'bank', 'all'] as $mode) {
            $this->actingAs($this->accountant)->get(route('cashBook.index', ['date' => $day, 'mode' => $mode]))
                ->assertInertia(function ($page) use (&$totals, $mode) {
                    $totals[$mode] = $page->toArray()['props']['totals'];
                });
        }

        $this->assertSame(30000.0, (float) $totals['cash']['closing']);
        $this->assertSame(65000.0, (float) $totals['bank']['closing']);
        $this->assertSame(
            round($totals['cash']['closing'] + $totals['bank']['closing'], 2),
            round((float) $totals['all']['closing'], 2),
        );
    }

    /** Итог книги == остаткам FinanceService (тем же, что на плитках Финансов). */
    public function test_book_agrees_with_finance_service_balances(): void
    {
        $day = now()->toDateString();
        $this->payment($day, 120000, 'cash');
        $this->payment($day, 80000, 'bank');
        $this->expense($day, 20000, 'cash');
        $this->expense($day, 15000, 'bank');
        $this->receipt($day, 7000, 'cash');

        $balances = app(FinanceService::class)->companyBalances(Company::where('code', 'QT')->value('id'));

        $this->actingAs($this->accountant)->get(route('cashBook.index', ['date' => $day, 'mode' => 'cash']))
            ->assertInertia(fn ($page) => $page->where('totals.closing', (int) $balances['cash']));

        $this->actingAs($this->accountant)->get(route('cashBook.index', ['date' => $day, 'mode' => 'bank']))
            ->assertInertia(fn ($page) => $page->where('totals.closing', (int) $balances['bank']));
    }

    /** Промежуточный баланс по строкам считает сервер. */
    public function test_running_balance_is_computed_on_the_server(): void
    {
        $day = now()->toDateString();
        $this->payment($day, 10000, 'cash');
        $this->expense($day, 4000, 'cash');

        $this->actingAs($this->accountant)->get(route('cashBook.index', ['date' => $day, 'mode' => 'cash']))
            ->assertInertia(fn ($page) => $page
                ->has('rows', 2)
                ->where('rows.0.balance', 10000)
                ->where('rows.1.balance', 6000));
    }

    /** Выплата сотруднику приходит с бейджем и человеком. */
    public function test_employee_payout_is_marked(): void
    {
        $day = now()->toDateString();
        $worker = User::factory()->create();

        $this->expense($day, 30000, 'cash')->update([
            'employee_id' => $worker->id,
            'employee_payout' => 'debt',
        ]);

        $this->actingAs($this->accountant)->get(route('cashBook.index', ['date' => $day, 'mode' => 'cash']))
            ->assertInertia(fn ($page) => $page
                ->where('rows.0.payout', 'debt')
                ->where('rows.0.employee.id', $worker->id));
    }

    /** Касса — для бухгалтерии и руководства. */
    public function test_page_is_closed_for_staff(): void
    {
        $worker = User::factory()->create();
        $worker->assignRole('employee');

        $this->actingAs($worker)->get(route('cashBook.index'))->assertForbidden();
    }
}
