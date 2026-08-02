<?php

namespace Tests\Feature;

use App\Models\CashReceipt;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CashReceiptTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);

        return $u;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);
    }

    public function test_financist_adds_receipt_and_balances_grow(): void
    {
        $fin = $this->user('financist');

        $this->actingAs($fin)->post(route('finance.receipts.store'), [
            'amount' => 500000, 'method' => 'cash', 'source' => 'Учредитель', 'date' => now()->toDateString(),
            'note' => 'взнос',
        ])->assertRedirect();

        $this->assertEquals(1, CashReceipt::count());

        $this->actingAs($fin)->get(route('finance.index'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->where('summary.cash', 500000)
                ->where('summary.incomeManual', 500000)
                ->has('receiptsToday', 1)
                ->has('receiptsPast', 0));
    }

    public function test_fin_month_filter_scopes_income_and_expenses(): void
    {
        $fin = $this->user('financist');
        $past = now()->subMonthNoOverflow();

        CashReceipt::create(['amount' => 1000, 'method' => 'cash', 'source' => 'сегодня', 'date' => now()->toDateString()]);
        CashReceipt::create(['amount' => 500, 'method' => 'bank', 'source' => 'прошлый месяц', 'date' => $past->toDateString()]);
        \App\Models\Expense::create(['amount' => 200, 'date' => $past->toDateString(), 'status' => 'confirmed', 'payment_method' => 'cash']);

        // «Доход» тоже месячный: сделка прошлого месяца (по дате договора)
        // попадает, сегодняшняя — нет. 100000 − 3% − бонус 15% от остатка = 82450.
        $stage = \App\Models\DealStage::orderBy('order')->first()->id;
        \App\Models\Deal::create(['number' => 'BAIA-101', 'name' => 'X', 'company_name' => 'Т', 'client_name' => 'И', 'budget' => 100000, 'status' => 'active', 'deal_stage_id' => $stage, 'contract_date' => $past->toDateString()]);
        \App\Models\Deal::create(['number' => 'BAIA-102', 'name' => 'Y', 'company_name' => 'Т', 'client_name' => 'И', 'budget' => 500000, 'status' => 'active', 'deal_stage_id' => $stage, 'contract_date' => now()->toDateString()]);

        // Сводка за прошлый месяц: только его поступления и расходы; ЗП/налог скрыты (0).
        $this->actingAs($fin)->get(route('finance.index', ['fin_month' => $past->format('Y-m')]))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->where('summary.income', 500)
                ->where('summary.incomeManual', 500)
                ->where('summary.dealsIncome', 82450)
                ->where('summary.expensesTotal', 200)
                ->where('summary.net', 300)
                ->where('summary.payroll', 0)
                ->where('summary.tax', 0)
                // Остатки касса/банк — всегда накопительные, месяц их не режет.
                ->where('summary.cash', 800));
    }

    public function test_manager_cannot_add_or_delete_receipt(): void
    {
        $mgr = $this->user('manager');

        $this->actingAs($mgr)->post(route('finance.receipts.store'), [
            'amount' => 100, 'method' => 'bank', 'source' => 'x', 'date' => now()->toDateString(),
        ])->assertForbidden();

        $r = CashReceipt::create(['amount' => 100, 'method' => 'bank', 'source' => 'x', 'date' => now()->toDateString()]);
        $this->actingAs($mgr)->delete(route('finance.receipts.destroy', $r))->assertForbidden();
    }

    public function test_financist_can_edit_deal_and_add_expense_and_invoice(): void
    {
        // «Доступ менеджера финансисту»: редактирует чужую сделку, вносит
        // расход и счёт (аванс) сам — без участия менеджера.
        $fin = $this->user('financist');
        $mgr = $this->user('manager');
        $deal = Deal::create([
            'number' => 'D-1', 'name' => 'ТОО', 'company_name' => 'ТОО', 'client_name' => 'товар',
            'budget' => 100000, 'status' => 'active', 'responsible_user_id' => $mgr->id,
            'deal_stage_id' => DealStage::orderBy('order')->first()->id,
        ]);

        $this->actingAs($fin)->put(route('deals.update', $deal), [
            'client_name' => 'товар', 'company_name' => 'ТОО Новое', 'address' => 'Алматы', 'budget' => 120000,
        ])->assertRedirect();
        $this->assertEquals('ТОО Новое', $deal->fresh()->company_name);

        $this->actingAs($fin)->post(route('expenses.store'), [
            'expenseable_type' => 'deal', 'expenseable_id' => $deal->id,
            'amount' => 5000, 'date' => now()->toDateString(), 'payment_method' => 'cash', 'status' => 'confirmed',
        ])->assertRedirect();
        $this->assertEquals('confirmed', $deal->expenses()->first()->status);

        $this->actingAs($fin)->post(route('invoices.store'), [
            'invoiceable_type' => 'deal', 'invoiceable_id' => $deal->id,
            'amount' => 50000, 'issue_date' => now()->toDateString(),
        ])->assertRedirect();
        $this->assertEquals(1, $deal->invoices()->count());
    }
}
