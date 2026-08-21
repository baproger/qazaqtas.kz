<?php

namespace Tests\Feature;

use App\Models\Deal;
use App\Models\DealStage;
use App\Models\Material;
use App\Models\Product;
use App\Models\User;
use App\Services\PayrollService;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Доработки от 10.08.2026: личный % бонуса сотрудника от чистого остатка,
 * филиал и площадь в сделке, товар из каталога, требование документа на
 * этапе и правка позиции склада.
 */
class ManualBonusAndDealFieldsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    // ---- Бонус ----

    public function test_employee_percent_is_applied_to_the_net_remainder(): void
    {
        // Личный % сотрудника: 1% от остатка 1 000 000 → 10 000.
        $this->assertSame(10000.0, PayrollService::dealBonus(1000000, null, 1.0)['total']);
    }

    public function test_deal_override_beats_the_employee_percent(): void
    {
        $this->assertSame(
            50000.0,
            PayrollService::dealBonus(1000000, 5.0, 1.0)['total'],
            'Ручной % по сделке должен перекрывать личный % сотрудника.'
        );
    }

    /**
     * Без личного % платит ставка ТИПА сделки, а не маржа.
     *
     * Раньше здесь была ступень от маржи (53% → 15%). Ступени отменены
     * 21.08.2026: своё производство 1%, перепродажа 2%.
     */
    public function test_without_a_percent_the_deal_type_rate_applies(): void
    {
        $this->assertSame(10000.0, PayrollService::dealBonus(1000000)['total']);
        $this->assertSame(
            20000.0,
            PayrollService::dealBonus(1000000, null, null, PayrollService::TYPE_RESALE)['total']
        );
    }

    public function test_payroll_uses_the_percent_saved_on_the_employee(): void
    {
        $manager = $this->user('manager');
        $manager->update(['bonus_percent' => 1]);

        $this->assertSame(1.0, PayrollService::userBonusPercent($manager->id));
        $this->assertNull(PayrollService::userBonusPercent(null));
    }

    public function test_admin_saves_the_percent_from_the_employee_form(): void
    {
        $admin = $this->user('admin');
        $manager = $this->user('manager');

        $this->actingAs($admin)->put(route('users.update', $manager), [
            'name' => $manager->name,
            'email' => $manager->email,
            'role' => 'manager',
            'salary' => 300000,
            'bonus_percent' => 1.5,
            'is_active' => true,
        ])->assertRedirect();

        $this->assertSame(1.5, (float) $manager->fresh()->bonus_percent);
    }

    // ---- Поля сделки ----

    public function test_deal_keeps_branch_area_and_catalogue_product(): void
    {
        $this->seed(CatalogSeeder::class);
        $admin = $this->user('admin');
        $product = Product::where('code', 'QT-P-300')->firstOrFail();

        $this->actingAs($admin)->post(route('deals.store'), [
            'name' => 'Двор ЖК',
            'company_name' => 'ТОО Курылыс',
            'client_name' => $product->name,
            'product_id' => $product->id,
            'address' => 'Шымкент, ул. Промышленная 1',
            'branch' => 'Шымкент',
            'area_m2' => 320.5,
            'unit' => 'м²',
            'budget' => 2800000,
        ])->assertRedirect();

        $deal = Deal::firstOrFail();
        $this->assertSame('Шымкент', $deal->branch);
        $this->assertSame(320.5, (float) $deal->area_m2);
        $this->assertSame($product->id, $deal->product_id);
    }

    public function test_unknown_branch_is_rejected(): void
    {
        $admin = $this->user('admin');

        $this->actingAs($admin)->post(route('deals.store'), [
            'name' => 'X', 'company_name' => 'ТОО', 'client_name' => 'Плитка',
            'address' => 'Адрес', 'budget' => 100000, 'branch' => 'Караганда',
        ])->assertSessionHasErrors('branch');
    }

    // ---- Документ на этапе ----

    public function test_stage_with_required_document_blocks_the_deal(): void
    {
        Storage::fake('local');
        $admin = $this->user('admin');

        $first = DealStage::orderBy('order')->first();
        $second = DealStage::orderBy('order')->skip(1)->first();
        $first->update(['requires_document' => true]);

        $deal = Deal::create([
            'number' => 'QT-D-1', 'name' => 'Двор', 'company_name' => 'ТОО',
            'client_name' => 'Плитка', 'budget' => 500000, 'status' => 'active',
            'deal_stage_id' => $first->id,
        ]);

        // Документа нет — сделка стоит. Причина показывается красным баннером
        // (DealController ловит ValidationException и кладёт её во flash).
        $this->actingAs($admin)->patch(route('deals.stage', $deal), ['deal_stage_id' => $second->id])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'прикрепите документ'));
        $this->assertSame($first->id, $deal->fresh()->deal_stage_id);

        $this->actingAs($admin)->post(route('documents.store'), [
            'documentable_type' => 'deal',
            'documentable_id' => $deal->id,
            'name' => 'Договор',
            'file' => UploadedFile::fake()->create('dogovor.pdf', 64, 'application/pdf'),
        ])->assertRedirect();

        // Документ приложен — переход разрешён.
        $this->actingAs($admin)->patch(route('deals.stage', $deal), ['deal_stage_id' => $second->id])
            ->assertSessionMissing('error');
        $this->assertSame($second->id, $deal->fresh()->deal_stage_id);
    }

    public function test_stage_without_the_flag_does_not_ask_for_documents(): void
    {
        $admin = $this->user('admin');
        $first = DealStage::orderBy('order')->first();
        $second = DealStage::orderBy('order')->skip(1)->first();

        $deal = Deal::create([
            'number' => 'QT-D-2', 'name' => 'Двор', 'company_name' => 'ТОО',
            'client_name' => 'Плитка', 'budget' => 500000, 'status' => 'active',
            'deal_stage_id' => $first->id,
        ]);

        $this->actingAs($admin)->patch(route('deals.stage', $deal), ['deal_stage_id' => $second->id])
            ->assertSessionMissing('error');
        $this->assertSame($second->id, $deal->fresh()->deal_stage_id);
    }

    // ---- Склад ----

    public function test_material_can_be_edited_without_touching_the_balance(): void
    {
        $admin = $this->user('admin');
        $material = Material::create([
            'name' => 'Цемент М400', 'unit' => 'мешок', 'quantity' => 40, 'price' => 1200,
        ]);

        $this->actingAs($admin)->put(route('warehouse.materials.update', $material), [
            'name' => 'Цемент белый М500',
            'unit' => 'кг',
            'price' => 1450,
            'note' => 'Новый поставщик',
        ])->assertRedirect();

        $material->refresh();
        $this->assertSame('Цемент белый М500', $material->name);
        $this->assertSame('кг', $material->unit);
        $this->assertSame(1450.0, (float) $material->price);
        // Остаток считается приходами и списаниями — правка его не трогает.
        $this->assertSame(40.0, (float) $material->quantity);
    }

    public function test_duplicate_material_name_is_rejected(): void
    {
        $admin = $this->user('admin');
        Material::create(['name' => 'Пигмент', 'unit' => 'кг', 'quantity' => 5]);
        $second = Material::create(['name' => 'Пластификатор', 'unit' => 'литр', 'quantity' => 5]);

        $this->actingAs($admin)->put(route('warehouse.materials.update', $second), [
            'name' => 'Пигмент',
        ])->assertSessionHasErrors('name');
    }

    public function test_manager_cannot_edit_warehouse_items(): void
    {
        $manager = $this->user('manager');
        $material = Material::create(['name' => 'Крошка', 'unit' => 'тонна', 'quantity' => 3]);

        $this->actingAs($manager)->put(route('warehouse.materials.update', $material), [
            'name' => 'Другое название',
        ])->assertForbidden();
    }
}
