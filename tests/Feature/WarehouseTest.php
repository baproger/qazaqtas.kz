<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Material;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WarehouseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function user(string $role, ?Company $company = null): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);
        if ($company) {
            $u->companies()->attach($company->id);
        }

        return $u;
    }

    public function test_financist_receipt_creates_material_and_stock(): void
    {
        $company = Company::where('code', 'BAIA')->firstOrFail();
        $fin = $this->user('financist', $company);

        $this->actingAs($fin)
            ->withSession(['company_id' => $company->id])
            ->post(route('warehouse.receipt'), ['name' => 'ЛДСП 16мм', 'unit' => 'штук', 'quantity' => 50])
            ->assertRedirect();

        $material = Material::where('name', 'ЛДСП 16мм')->first();
        $this->assertNotNull($material);
        $this->assertEquals(50.0, (float) $material->quantity);
        $this->assertSame($company->id, (int) $material->company_id);

        // Повторный приход существующего материала суммирует остаток.
        $this->actingAs($fin)->withSession(['company_id' => $company->id])
            ->post(route('warehouse.receipt'), ['material_id' => $material->id, 'quantity' => 25])
            ->assertRedirect();
        $this->assertEquals(75.0, (float) $material->fresh()->quantity);
    }

    public function test_manager_sees_warehouse_but_cannot_receipt(): void
    {
        $company = Company::where('code', 'BAIA')->firstOrFail();
        $mgr = $this->user('manager', $company);

        $this->actingAs($mgr)->withSession(['company_id' => $company->id])
            ->get(route('warehouse.index'))->assertOk();

        $this->actingAs($mgr)->withSession(['company_id' => $company->id])
            ->post(route('warehouse.receipt'), ['name' => 'МДФ', 'quantity' => 10])
            ->assertForbidden();
    }

    public function test_employee_has_no_warehouse_access(): void
    {
        $emp = $this->user('employee');

        $this->actingAs($emp)->get(route('warehouse.index'))->assertForbidden();
    }

    public function test_financist_can_edit_and_delete_receipt_with_stock_recalc(): void
    {
        $company = Company::where('code', 'BAIA')->firstOrFail();
        $fin = $this->user('financist', $company);
        $material = Material::create(['company_id' => $company->id, 'name' => 'ЛДСП', 'unit' => 'штук', 'quantity' => 50]);
        $receipt = $material->receipts()->create(['user_id' => $fin->id, 'quantity' => 50, 'date' => now()->toDateString()]);

        // 50 → 30: остаток уменьшается на 20.
        $this->actingAs($fin)->put(route('warehouse.receipts.update', $receipt->id), ['quantity' => 30])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertEquals(30.0, (float) $material->fresh()->quantity);

        // Удаление прихода снимает его количество с остатка.
        $this->actingAs($fin)->delete(route('warehouse.receipts.destroy', $receipt->id))->assertRedirect();
        $this->assertEquals(0.0, (float) $material->fresh()->quantity);
        $this->assertSame(0, $material->receipts()->count());
    }

    public function test_receipt_edit_cannot_push_stock_negative(): void
    {
        $company = Company::where('code', 'BAIA')->firstOrFail();
        $fin = $this->user('financist', $company);
        $material = Material::create(['company_id' => $company->id, 'name' => 'МДФ', 'unit' => 'штук', 'quantity' => 10]);
        $receipt = $material->receipts()->create(['user_id' => $fin->id, 'quantity' => 50, 'date' => now()->toDateString()]);
        // Остаток 10 (40 уже списано в расходы) — уменьшить приход до 5 нельзя.

        $this->actingAs($fin)->put(route('warehouse.receipts.update', $receipt->id), ['quantity' => 5])
            ->assertSessionHasErrors('quantity');
        $this->assertEquals(10.0, (float) $material->fresh()->quantity);
    }

    public function test_director_cannot_manage_receipts(): void
    {
        $company = Company::where('code', 'BAIA')->firstOrFail();
        $director = $this->user('director', $company);
        $material = Material::create(['company_id' => $company->id, 'name' => 'Кромка', 'unit' => 'метр', 'quantity' => 5]);
        $receipt = $material->receipts()->create(['quantity' => 5, 'date' => now()->toDateString()]);

        $this->actingAs($director)->post(route('warehouse.receipt'), ['material_id' => $material->id, 'quantity' => 1])->assertForbidden();
        $this->actingAs($director)->put(route('warehouse.receipts.update', $receipt->id), ['quantity' => 1])->assertForbidden();
        $this->actingAs($director)->delete(route('warehouse.receipts.destroy', $receipt->id))->assertForbidden();
        // Просмотр склада директору доступен.
        $this->actingAs($director)->withSession(['company_id' => $company->id])->get(route('warehouse.index'))->assertOk();
    }

    public function test_receipt_price_becomes_last_purchase_price(): void
    {
        $company = Company::where('code', 'BAIA')->firstOrFail();
        $fin = $this->user('financist', $company);

        $this->actingAs($fin)->withSession(['company_id' => $company->id])
            ->post(route('warehouse.receipt'), ['name' => 'ЛДСП', 'unit' => 'штук', 'quantity' => 10, 'price' => 1200])
            ->assertSessionHasNoErrors()->assertRedirect();

        $material = Material::where('name', 'ЛДСП')->firstOrFail();
        $this->assertEquals(1200.0, (float) $material->price);
        $this->assertEquals(1200.0, (float) $material->receipts()->first()->price);

        // Новый приход с другой ценой — материал хранит последнюю закупочную.
        $this->actingAs($fin)->withSession(['company_id' => $company->id])
            ->post(route('warehouse.receipt'), ['material_id' => $material->id, 'quantity' => 5, 'price' => 1500])
            ->assertRedirect();
        $this->assertEquals(1500.0, (float) $material->fresh()->price);

        // Приход без цены закупочную не сбрасывает.
        $this->actingAs($fin)->withSession(['company_id' => $company->id])
            ->post(route('warehouse.receipt'), ['material_id' => $material->id, 'quantity' => 3])
            ->assertRedirect();
        $this->assertEquals(1500.0, (float) $material->fresh()->price);
    }

    public function test_editing_receipt_price_resyncs_material_price(): void
    {
        $company = Company::where('code', 'BAIA')->firstOrFail();
        $fin = $this->user('financist', $company);
        $material = Material::create(['company_id' => $company->id, 'name' => 'МДФ', 'unit' => 'штук', 'quantity' => 10, 'price' => 900]);
        $receipt = $material->receipts()->create(['user_id' => $fin->id, 'quantity' => 10, 'price' => 900, 'date' => now()->toDateString()]);

        $this->actingAs($fin)->put(route('warehouse.receipts.update', $receipt->id), ['quantity' => 10, 'price' => 950])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertEquals(950.0, (float) $material->fresh()->price);
    }

    public function test_deleting_only_priced_receipt_resets_material_price(): void
    {
        // Фантомная цена: удалили единственный приход с ценой — цена сбрасывается,
        // иначе расходы продолжали бы считаться по цене несуществующего прихода.
        $company = Company::where('code', 'BAIA')->firstOrFail();
        $fin = $this->user('financist', $company);
        $material = Material::create(['company_id' => $company->id, 'name' => 'Кромка', 'unit' => 'метр', 'quantity' => 10, 'price' => 90000]);
        $receipt = $material->receipts()->create(['user_id' => $fin->id, 'quantity' => 10, 'price' => 90000, 'date' => now()->toDateString()]);

        $this->actingAs($fin)->delete(route('warehouse.receipts.destroy', $receipt->id))->assertRedirect();
        $this->assertEquals(0.0, (float) $material->fresh()->price);
    }

    public function test_clearing_receipt_price_resets_material_price(): void
    {
        $company = Company::where('code', 'BAIA')->firstOrFail();
        $fin = $this->user('financist', $company);
        $material = Material::create(['company_id' => $company->id, 'name' => 'Клей', 'unit' => 'штук', 'quantity' => 10, 'price' => 500]);
        $receipt = $material->receipts()->create(['user_id' => $fin->id, 'quantity' => 10, 'price' => 500, 'date' => now()->toDateString()]);

        // Очистка поля цены ('' → null через ConvertEmptyStringsToNull).
        $this->actingAs($fin)->put(route('warehouse.receipts.update', $receipt->id), ['quantity' => 10, 'price' => ''])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertEquals(0.0, (float) $material->fresh()->price);
    }

    public function test_warehouse_scoped_by_current_company(): void
    {
        $baia = Company::where('code', 'BAIA')->firstOrFail();
        $asu = Company::where('code', 'ASU')->firstOrFail();
        Material::create(['company_id' => $baia->id, 'name' => 'ЛДСП BAIA', 'unit' => 'штук', 'quantity' => 5]);
        Material::create(['company_id' => $asu->id, 'name' => 'МДФ ASU', 'unit' => 'штук', 'quantity' => 7]);

        $fin = $this->user('financist', $baia);

        $this->actingAs($fin)->withSession(['company_id' => $baia->id])
            ->get(route('warehouse.index'))
            ->assertInertia(fn ($p) => $p->component('Warehouse/Index')->has('materials', 1)
                ->where('materials.0.name', 'ЛДСП BAIA'));
    }
}
