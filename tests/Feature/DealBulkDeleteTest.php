<?php

namespace Tests\Feature;

use App\Models\Deal;
use App\Models\DealStage;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DealBulkDeleteTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);

        return $u;
    }

    private function deal(string $n): Deal
    {
        return Deal::create([
            'number' => $n, 'name' => 'ТОО', 'company_name' => 'ТОО', 'client_name' => 'товар',
            'budget' => 100, 'status' => 'active',
            'deal_stage_id' => DealStage::orderBy('order')->first()->id,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);
    }

    public function test_admin_bulk_deletes_deals(): void
    {
        $admin = $this->user('admin');
        $a = $this->deal('D-1');
        $b = $this->deal('D-2');
        $keep = $this->deal('D-3');

        $this->actingAs($admin)->delete(route('deals.bulkDestroy'), ['ids' => [$a->id, $b->id]])
            ->assertRedirect();

        $this->assertSoftDeleted('deals', ['id' => $a->id]);
        $this->assertSoftDeleted('deals', ['id' => $b->id]);
        $this->assertNull($keep->fresh()->deleted_at);
    }

    public function test_manager_cannot_bulk_delete(): void
    {
        $mgr = $this->user('manager');
        $a = $this->deal('D-1');

        $this->actingAs($mgr)->delete(route('deals.bulkDestroy'), ['ids' => [$a->id]])
            ->assertForbidden();
        $this->assertNull($a->fresh()->deleted_at);
    }

    public function test_deleting_deal_cancels_its_workshop_order_and_warehouse_survives(): void
    {
        $admin = $this->user('admin');
        $deal = $this->deal('D-1');
        $project = \App\Models\Project::create([
            'number' => 'PRJ-T-1', 'name' => 'ТОО', 'deal_id' => $deal->id, 'status' => 'active',
        ]);
        // Материальное списание на сделку — после удаления сделки Склад не должен падать.
        $material = \App\Models\Material::create(['name' => 'Труба', 'unit' => 'штук', 'quantity' => 10, 'price' => 100]);
        \App\Models\Expense::create([
            'expenseable_type' => 'deal', 'expenseable_id' => $deal->id, 'material_id' => $material->id,
            'qty' => 2, 'amount' => 200, 'date' => now()->toDateString(), 'status' => 'confirmed', 'type' => 'direct',
        ]);

        $this->actingAs($admin)->delete(route('deals.destroy', $deal))->assertRedirect(route('deals.index'));

        // Заказ цеха отменён каскадом, Склад открывается без 404/500.
        $this->assertEquals('cancelled', $project->fresh()->status);
        $this->actingAs($admin)->get(route('warehouse.index'))->assertOk();
    }
}
