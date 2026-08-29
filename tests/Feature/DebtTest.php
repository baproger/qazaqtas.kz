<?php

namespace Tests\Feature;

use App\Models\CashReceipt;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\Debt;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DebtTest extends TestCase
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

    public function test_financist_crud_and_totals_on_finance_page(): void
    {
        $fin = $this->user('financist');

        $this->actingAs($fin)->post(route('finance.debts.store'), [
            'type' => 'payable', 'counterparty' => 'ТОО Поставщик', 'amount' => 300000,
            'date' => now()->toDateString(), 'note' => 'за Мраморная крошка',
        ])->assertRedirect();
        $debt = Debt::first();
        $this->assertEquals('payable', $debt->type);

        $this->actingAs($fin)->put(route('finance.debts.update', $debt), [
            'type' => 'payable', 'counterparty' => 'ТОО Поставщик', 'amount' => 250000,
        ])->assertRedirect();
        $this->assertEquals(250000.0, (float) $debt->fresh()->amount);

        $this->actingAs($fin)->get(route('finance.index'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->where('summary.payables', 250000)
                ->has('summary.dealsIncome'));

        // Сами записи задолженностей — на своей странице.
        $this->actingAs($fin)->get(route('finance.debts'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->has('debts.payables', 1)
                ->where('totals.payables', 250000));
    }

    public function test_manager_cannot_manage_debts(): void
    {
        $mgr = $this->user('manager');

        $this->actingAs($mgr)->post(route('finance.debts.store'), [
            'type' => 'receivable', 'counterparty' => 'x', 'amount' => 100,
        ])->assertForbidden();
    }

    public function test_deleting_debt_notifies_ceo_and_director(): void
    {
        $fin = $this->user('financist');
        $ceo = $this->user('admin');
        $director = $this->user('director');
        $debt = Debt::create(['type' => 'receivable', 'counterparty' => 'ИП Клиент', 'amount' => 90000]);

        $this->actingAs($fin)->delete(route('finance.debts.destroy', $debt))->assertRedirect();

        $this->assertEquals(0, Debt::count());
        // СЕО и директор получили уведомление об удалении финансовой записи.
        $this->assertEquals(1, $ceo->notifications()->count());
        $this->assertEquals(1, $director->notifications()->count());
        $this->assertStringContainsString('ИП Клиент', $ceo->notifications()->first()->data['message']);
    }

    public function test_deleting_receipt_notifies_leadership_too(): void
    {
        $fin = $this->user('financist');
        $ceo = $this->user('admin');
        $r = CashReceipt::create(['amount' => 5000, 'method' => 'cash', 'source' => 'Учредитель', 'date' => now()->toDateString()]);

        $this->actingAs($fin)->delete(route('finance.receipts.destroy', $r))->assertRedirect();
        $this->assertEquals(1, $ceo->notifications()->count());
    }

    /** Долг по счетам раскрыт по сделкам: номер, заказчик, остаток, ссылка на сделку. */
    public function test_invoice_debts_are_broken_down_by_deal(): void
    {
        $admin = $this->user('admin');
        $deal = Deal::create(['number' => 'QT-777', 'name' => 'X', 'company_name' => 'ТОО Школа', 'client_name' => 'Иванов',
            'budget' => 1600000, 'status' => 'active', 'deal_stage_id' => DealStage::orderBy('order')->value('id'),
            'responsible_user_id' => $admin->id]);
        $inv = Invoice::create(['number' => 'INV-1', 'invoiceable_type' => 'deal', 'invoiceable_id' => $deal->id,
            'amount' => 1600000, 'status' => 'sent', 'issue_date' => now()->toDateString(), 'due_date' => now()->subDays(3)->toDateString()]);
        Payment::create(['invoice_id' => $inv->id, 'amount' => 600000, 'payment_date' => now()->toDateString()]);

        $this->actingAs($admin)->get(route('finance.debts'))->assertOk()
            ->assertInertia(fn ($p) => $p->component('Finance/Debts')
                ->where('invoiceDebts.0.deal.id', $deal->id)
                ->where('invoiceDebts.0.deal.number', 'QT-777')
                ->where('invoiceDebts.0.deal.company', 'ТОО Школа')
                ->where('invoiceDebts.0.left', 1000000)
                ->where('invoiceDebts.0.overdue', true));
    }
}
