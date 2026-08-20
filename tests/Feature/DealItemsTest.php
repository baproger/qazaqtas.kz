<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\PreDeal;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Несколько товаров в одной сделке и заявке.
 *
 * Клиент берёт брусчатку (м²) и урны (штук) сразу — у каждой позиции своя
 * единица и своя цена, а сумма заказа складывается из строк.
 */
class DealItemsTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private Product $paving;   // брусчатка, м²

    private Product $bin;      // урна, штук

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);

        $this->manager = User::factory()->create();
        $this->manager->assignRole('manager');
        $this->manager->companies()->attach(Company::where('code', 'QT')->value('id'));

        $paving = ProductCategory::create(['name' => 'Брусчатка', 'slug' => 'brus', 'is_active' => true]);
        $bins = ProductCategory::create(['name' => 'Урны', 'slug' => 'urny', 'is_active' => true]);

        $this->paving = Product::create([
            'category_id' => $paving->id, 'name' => 'Брусчатка «Квадрат»', 'slug' => 'kvadrat',
            'unit' => 'м²', 'price' => 12000, 'is_active' => true,
        ]);
        $this->bin = Product::create([
            'category_id' => $bins->id, 'name' => 'Урна «Тау»', 'slug' => 'urna-tau',
            'unit' => 'штук', 'price' => 45000, 'is_active' => true,
        ]);
    }

    private function dealPayload(array $items): array
    {
        return [
            'company_name' => 'Асхат', 'client_name' => 'Асхат', 'address' => 'ЖК Керемет', 'budget' => 0,
            'deal_stage_id' => DealStage::orderBy('order')->value('id'),
            'items' => $items,
        ];
    }

    /** Каждая позиция несёт свою единицу из каталога — менеджер её не вводит. */
    public function test_each_item_keeps_its_own_unit_from_the_catalog(): void
    {
        $this->actingAs($this->manager)->post(route('deals.store'), $this->dealPayload([
            ['product_id' => $this->paving->id, 'quantity' => 120],
            ['product_id' => $this->bin->id, 'quantity' => 4],
        ]))->assertSessionHasNoErrors();

        $items = Deal::firstOrFail()->items;

        $this->assertSame(2, $items->count());
        $this->assertSame('м²', $items[0]->unit);
        $this->assertSame('штук', $items[1]->unit);
        $this->assertSame('Брусчатка «Квадрат»', $items[0]->name);
    }

    /** Сумма сделки считается по строкам, а не берётся из формы. */
    public function test_deal_budget_is_the_sum_of_its_items(): void
    {
        $this->actingAs($this->manager)->post(route('deals.store'), $this->dealPayload([
            ['product_id' => $this->paving->id, 'quantity' => 100],   // 100 × 12 000
            ['product_id' => $this->bin->id, 'quantity' => 2],        // 2 × 45 000
        ]))->assertSessionHasNoErrors();

        $this->assertSame(1290000.0, (float) Deal::firstOrFail()->budget);
    }

    /** Цена из каталога правится в строке: скидку дают заказу, а не товару. */
    public function test_price_can_be_overridden_per_line(): void
    {
        $this->actingAs($this->manager)->post(route('deals.store'), $this->dealPayload([
            ['product_id' => $this->paving->id, 'quantity' => 10, 'price' => 10000],
        ]))->assertSessionHasNoErrors();

        $deal = Deal::firstOrFail();
        $this->assertSame(10000.0, (float) $deal->items[0]->price);
        $this->assertSame(100000.0, (float) $deal->budget);
        $this->assertSame(12000.0, (float) $this->paving->fresh()->price, 'Каталог трогать нельзя.');
    }

    /** Правка сделки переписывает состав и пересчитывает сумму. */
    public function test_updating_a_deal_rewrites_its_items(): void
    {
        $this->actingAs($this->manager)->post(route('deals.store'), $this->dealPayload([
            ['product_id' => $this->paving->id, 'quantity' => 10],
        ]))->assertSessionHasNoErrors();

        $deal = Deal::firstOrFail();
        $this->actingAs($this->manager)->put(route('deals.update', $deal->id), $this->dealPayload([
            ['product_id' => $this->bin->id, 'quantity' => 3],
        ]))->assertSessionHasNoErrors();

        $deal->refresh()->load('items');
        $this->assertSame(1, $deal->items->count());
        $this->assertSame('Урна «Тау»', $deal->items[0]->name);
        $this->assertSame(135000.0, (float) $deal->budget);
    }

    /** Заявка тоже многопозиционная: сумма КП и закуп — по строкам. */
    public function test_pre_deal_sums_and_margin_come_from_items(): void
    {
        $this->actingAs($this->manager)->post(route('preDeals.store'), [
            'customer' => 'Асхат',
            'items' => [
                ['product_id' => $this->paving->id, 'quantity' => 100, 'purchase_price' => 8000],
                ['product_id' => $this->bin->id, 'quantity' => 2, 'purchase_price' => 30000],
            ],
        ]);
        $preDeal = PreDeal::firstOrFail();

        // Продажа: 100 × 12 000 + 2 × 45 000 = 1 290 000
        $this->assertSame(1290000.0, (float) $preDeal->contract_sum);
        // Закуп: 100 × 8 000 + 2 × 30 000 = 860 000
        $this->assertSame(860000.0, (float) $preDeal->purchase_price);
        $this->assertGreaterThan(0, (float) $preDeal->margin);
        $this->assertSame(2, $preDeal->items->count());
    }

    /** «В работу ✓» переносит товары в сделку — второй раз их не вводят. */
    public function test_confirming_a_pre_deal_carries_items_into_the_deal(): void
    {
        $this->actingAs($this->manager)->post(route('preDeals.store'), [
            'customer' => 'Асхат',
            'items' => [
                ['product_id' => $this->paving->id, 'quantity' => 50, 'purchase_price' => 5000],
                ['product_id' => $this->bin->id, 'quantity' => 1, 'purchase_price' => 20000],
            ],
        ])->assertSessionHasNoErrors();

        $preDeal = PreDeal::firstOrFail();
        $this->actingAs($this->manager)->post(route('preDeals.confirm', $preDeal->id))
            ->assertSessionHasNoErrors();

        $deal = Deal::firstOrFail()->load('items');

        $this->assertSame(2, $deal->items->count());
        $this->assertSame('м²', $deal->items[0]->unit);
        $this->assertSame((float) $preDeal->contract_sum, (float) $deal->budget);
    }

    /** Пустые строки формы не создают позиций-призраков. */
    public function test_empty_rows_are_ignored(): void
    {
        $this->actingAs($this->manager)->post(route('deals.store'), $this->dealPayload([
            ['product_id' => $this->paving->id, 'quantity' => 5],
            ['product_id' => null, 'name' => '', 'quantity' => 0],
        ]))->assertSessionHasNoErrors();

        $this->assertSame(1, Deal::firstOrFail()->items->count());
    }
}
