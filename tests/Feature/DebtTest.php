<?php

namespace Tests\Feature;

use App\Models\Debt;
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
            'date' => now()->toDateString(), 'note' => 'за ЛДСП',
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
                ->has('debts.payables', 1)
                ->has('summary.dealsIncome'));
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
        $r = \App\Models\CashReceipt::create(['amount' => 5000, 'method' => 'cash', 'source' => 'Учредитель', 'date' => now()->toDateString()]);

        $this->actingAs($fin)->delete(route('finance.receipts.destroy', $r))->assertRedirect();
        $this->assertEquals(1, $ceo->notifications()->count());
    }
}
