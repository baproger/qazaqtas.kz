<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Material;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Регрессии на находки аудита безопасности (расходы/счета, права, склад, изоляция фирм). */
class SecurityFixesTest extends TestCase
{
    use RefreshDatabase;

    private Company $baia;

    private Company $asu;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);
        $this->baia = Company::where('code', 'BAIA')->firstOrFail();
        $this->asu = Company::where('code', 'ASU')->firstOrFail();
    }

    private function user(string $role, ?Company $company = null): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);
        $u->companies()->attach(($company ?? $this->baia)->id);

        return $u;
    }

    private function deal(User $owner, ?Company $company = null): Deal
    {
        return Deal::create([
            'company_id' => ($company ?? $this->baia)->id,
            'number' => 'D-'.uniqid(), 'name' => 'Сделка', 'company_name' => 'ТОО', 'budget' => 100000,
            'status' => 'active', 'deal_stage_id' => DealStage::orderBy('order')->first()->id,
            'responsible_user_id' => $owner->id,
        ]);
    }

    // ---- №1: расход/счёт ----

    public function test_manager_cannot_self_confirm_own_expense_via_update(): void
    {
        $mgr = $this->user('manager');
        $deal = $this->deal($mgr);
        $expense = Expense::create([
            'expenseable_type' => 'deal', 'expenseable_id' => $deal->id, 'amount' => 5000,
            'date' => now()->toDateString(), 'status' => 'pending', 'responsible_user_id' => $mgr->id,
        ]);

        $this->actingAs($mgr)->put(route('expenses.update', $expense->id), [
            'expenseable_type' => 'deal', 'expenseable_id' => $deal->id,
            'amount' => 5000, 'date' => now()->toDateString(), 'status' => 'confirmed',
        ])->assertRedirect();

        // Статус остался pending — самоподтверждение не прошло.
        $this->assertSame('pending', $expense->fresh()->status);
    }

    public function test_manager_cannot_reparent_expense_to_other_deal_via_update(): void
    {
        $mgr = $this->user('manager');
        $mine = $this->deal($mgr);
        $other = $this->deal($this->user('manager', $this->asu), $this->asu);
        $expense = Expense::create([
            'expenseable_type' => 'deal', 'expenseable_id' => $mine->id, 'amount' => 3000,
            'date' => now()->toDateString(), 'status' => 'pending', 'responsible_user_id' => $mgr->id,
        ]);

        $this->actingAs($mgr)->put(route('expenses.update', $expense->id), [
            'expenseable_type' => 'deal', 'expenseable_id' => $other->id,
            'amount' => 3000, 'date' => now()->toDateString(),
        ]);

        // Привязка не изменилась — расход не «уехал» на чужую сделку.
        $this->assertSame($mine->id, (int) $expense->fresh()->expenseable_id);
    }

    public function test_invoice_cannot_be_marked_paid_via_update(): void
    {
        $fin = $this->user('financist');
        $deal = $this->deal($this->user('manager'));
        $invoice = Invoice::create([
            'invoiceable_type' => 'deal', 'invoiceable_id' => $deal->id, 'number' => 'INV-1',
            'amount' => 50000, 'status' => 'sent', 'issue_date' => now()->toDateString(),
        ]);

        $this->actingAs($fin)->put(route('invoices.update', $invoice->id), [
            'amount' => 50000, 'status' => 'paid',
        ])->assertRedirect();

        // «Оплачено» без единого платежа не выставляется.
        $this->assertNotSame('paid', $invoice->fresh()->status);
    }

    // ---- №3: эскалация прав ----

    public function test_director_cannot_assign_admin_role(): void
    {
        $director = $this->user('director');
        $target = $this->user('manager');

        $this->actingAs($director)->put(route('users.update', $target->id), [
            'name' => $target->name, 'email' => $target->email, 'role' => 'admin',
        ])->assertForbidden();

        $this->assertFalse($target->fresh()->hasRole('admin'));
    }

    public function test_last_admin_cannot_be_demoted_or_deactivated(): void
    {
        $admin = $this->user('admin');

        $this->actingAs($admin)->put(route('users.update', $admin->id), [
            'name' => $admin->name, 'email' => $admin->email, 'role' => 'manager',
        ])->assertForbidden();
        $this->assertTrue($admin->fresh()->hasRole('admin'));

        $this->actingAs($admin)->delete(route('users.destroy', $admin->id))->assertForbidden();
        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_admin_can_assign_admin_role(): void
    {
        $admin = $this->user('admin');
        $target = $this->user('manager');

        $this->actingAs($admin)->put(route('users.update', $target->id), [
            'name' => $target->name, 'email' => $target->email, 'role' => 'admin',
        ])->assertRedirect();
        $this->assertTrue($target->fresh()->hasRole('admin'));
    }

    // ---- №2: склад (перепроверка под блокировкой отклоняет уход в минус) ----

    public function test_material_writeoff_rejected_when_insufficient(): void
    {
        $mgr = $this->user('manager');
        $deal = $this->deal($mgr);
        $material = Material::create(['company_id' => $this->baia->id, 'name' => 'ЛДСП', 'unit' => 'штук', 'quantity' => 5, 'price' => 100]);

        $this->actingAs($mgr)->post(route('expenses.store'), [
            'expenseable_type' => 'deal', 'expenseable_id' => $deal->id,
            'material_id' => $material->id, 'qty' => 10, 'date' => now()->toDateString(),
        ])->assertSessionHasErrors('qty');

        $this->assertEquals(5.0, (float) $material->fresh()->quantity);
        $this->assertSame(0, Expense::count());
    }

    // ---- №4: изоляция фирм ----

    public function test_bin_lookup_scoped_to_current_company(): void
    {
        $asuMgr = $this->user('manager', $this->asu);
        $asuDeal = $this->deal($asuMgr, $this->asu);
        $asuDeal->update(['bin' => '999888777', 'company_name' => 'ASU Клиент']);

        $baiaMgr = $this->user('manager', $this->baia);
        $resp = $this->actingAs($baiaMgr)->withSession(['company_id' => $this->baia->id])
            ->getJson(route('deals.binLookup', ['bin' => '999888777']));

        // Менеджер BAIA не видит сделку ASU по её БИН.
        $resp->assertOk()->assertJson(['match' => null, 'history' => []]);
    }

    public function test_audit_log_admin_only(): void
    {
        $this->actingAs($this->user('director'))->get(route('audit.index'))->assertForbidden();
        $this->actingAs($this->user('financist'))->get(route('audit.index'))->assertForbidden();
        $this->actingAs($this->user('admin'))->get(route('audit.index'))->assertOk();
    }

    public function test_manager_cannot_write_custom_fields_to_other_company_project(): void
    {
        // IDOR: менеджер BAIA не должен править доп-поля заказа/сделки ASU.
        $asuMgr = $this->user('manager', $this->asu);
        $asuDeal = $this->deal($asuMgr, $this->asu);
        $field = \App\Models\CustomField::create([
            'entity_type' => 'deal', 'name' => 'Секретное', 'type' => 'text', 'is_visible' => true, 'order' => 1,
        ]);

        $baiaMgr = $this->user('manager', $this->baia);
        $this->actingAs($baiaMgr)->post(route('custom-field-values.sync'), [
            'entity_type' => 'deal', 'entity_id' => $asuDeal->id,
            'values' => [$field->id => 'взлом'],
        ])->assertForbidden();

        $this->assertDatabaseMissing('custom_field_values', ['entity_id' => $asuDeal->id, 'value' => 'взлом']);
    }

    public function test_salary_hidden_from_serialized_user(): void
    {
        $u = $this->user('manager');
        $u->update(['salary' => 500000]);
        // При сериализации модели во фронт зарплата не утекает.
        $this->assertArrayNotHasKey('salary', $u->fresh()->toArray());
        $this->assertArrayNotHasKey('contract_path', $u->fresh()->toArray());
    }
}
