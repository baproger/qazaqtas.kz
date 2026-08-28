<?php

namespace Tests\Feature;

use App\Models\Brigade;
use App\Models\Company;
use App\Models\Deal;
use App\Models\Product;
use App\Models\ProductionPlan;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\WorkOrder;
use App\Notifications\ProductShortage;
use App\Services\ProductionBonusService;
use App\Services\StockService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Склад готовой продукции: приход из подтверждённой выработки.
 *
 * Остаток не меняется числом — только движением со ссылкой на источник.
 * Товар появляется на складе, когда финансист или директор подтвердил наряд
 * по плану: до подтверждения выработка ещё не факт.
 *
 * Наряд под позицию сделки прихода НЕ даёт: тот товар делается под конкретный
 * заказ и уже продан — на складе он оказался бы вторым экземпляром.
 */
class ProductStockTest extends TestCase
{
    use RefreshDatabase;

    private User $director;

    private User $master;

    private User $foreman;

    private User $manager;

    private Brigade $brigade;

    private Product $tile;

    private int $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->company = Company::where('code', 'QT')->value('id');

        foreach (['director', 'production_head', 'manager'] as $role) {
            $user = User::factory()->create();
            $user->assignRole($role);
            $user->companies()->attach($this->company);
            $this->{$role === 'production_head' ? 'master' : $role} = $user;
        }

        $this->foreman = User::factory()->create();
        $this->foreman->assignRole('foreman');
        $this->foreman->companies()->attach($this->company);

        $this->brigade = Brigade::create([
            'company_id' => $this->company, 'name' => 'Бригада №1',
            'workshop' => 'Шымкент', 'foreman_id' => $this->foreman->id, 'is_active' => true,
        ]);
        $this->brigade->members()->attach(User::factory()->create()->id);

        $this->tile = Product::create([
            'name' => 'Плитка «Большой формат» 600×300×80', 'unit' => 'м²',
            'price' => 12000, 'is_active' => true, 'is_service' => false,
        ]);
    }

    private function plan(float $qty = 1000): ProductionPlan
    {
        return ProductionPlan::create([
            'company_id' => $this->company,
            'period_month' => now()->startOfMonth()->toDateString(),
            'brigade_id' => $this->brigade->id,
            'product_id' => $this->tile->id,
            'plan_qty' => $qty, 'unit' => 'м²', 'status' => 'active',
            'created_by' => $this->director->id,
        ]);
    }

    private function report(ProductionPlan $plan, float $qty): WorkOrder
    {
        $this->actingAs($this->foreman)
            ->post(route('production.plans.output', $plan->id), ['qty' => $qty])
            ->assertSessionHasNoErrors();

        return WorkOrder::where('production_plan_id', $plan->id)->latest('id')->firstOrFail();
    }

    private function stock(): float
    {
        return app(StockService::class)->qty($this->tile->id, $this->company);
    }

    /** До подтверждения на складе пусто: выработка ещё не факт. */
    public function test_unconfirmed_output_does_not_reach_the_stock(): void
    {
        $this->report($this->plan(), 1000);

        $this->assertSame(0.0, $this->stock());
        $this->assertSame(0, StockMovement::count());
    }

    /** Финансист подтвердил — 1000 м² легли на склад. */
    public function test_confirmation_puts_the_goods_on_the_stock(): void
    {
        $order = $this->report($this->plan(), 1000);

        $this->actingAs($this->master)
            ->patch(route('production.orders.confirm', $order->id))
            ->assertSessionHasNoErrors();

        $this->assertSame(1000.0, $this->stock());

        $movement = StockMovement::firstOrFail();
        $this->assertSame(StockMovement::PRODUCTION_IN, $movement->type);
        $this->assertSame($this->tile->id, $movement->product_id);
        $this->assertSame($order->id, (int) $movement->source_id);
    }

    /**
     * Повторное подтверждение второго прихода не создаёт.
     *
     * Дубль удвоил бы остаток молча — и объяснить расхождение было бы нечем.
     */
    public function test_confirming_twice_does_not_double_the_stock(): void
    {
        $order = $this->report($this->plan(), 500);

        $this->actingAs($this->master)->patch(route('production.orders.confirm', $order->id));
        $this->actingAs($this->director)->patch(route('production.orders.confirm', $order->id));

        $this->assertSame(500.0, $this->stock());
        $this->assertSame(1, StockMovement::where('type', StockMovement::PRODUCTION_IN)->count());
    }

    /** Наряд под позицию сделки на склад не идёт — товар уже продан. */
    public function test_an_order_for_a_deal_item_gives_no_stock(): void
    {
        $deal = Deal::create([
            'company_id' => $this->company, 'number' => 'QT-800', 'name' => 'Объект',
            'company_name' => 'ТОО «Клиент»', 'status' => 'active',
        ]);
        $item = $deal->items()->create([
            'product_id' => $this->tile->id, 'name' => $this->tile->name,
            'unit' => 'м²', 'quantity' => 100, 'sort' => 0,
        ]);

        $order = WorkOrder::create([
            'company_id' => $this->company, 'brigade_id' => $this->brigade->id,
            'deal_item_id' => $item->id, 'date' => now()->toDateString(),
            'status' => 'draft', 'created_by' => $this->foreman->id,
        ]);
        app(ProductionBonusService::class)->syncLines($order->load('brigade'), [
            ['user_id' => $this->brigade->members()->value('users.id'), 'qty_m2' => 100],
        ]);

        $this->actingAs($this->director)->patch(route('production.orders.confirm', $order->id));

        $this->assertSame(0.0, $this->stock());
        $this->assertSame(0, StockMovement::count());
    }

    /** Удалили подтверждённый наряд — приход сторнируется, остаток сходится. */
    public function test_deleting_a_confirmed_order_reverses_the_stock(): void
    {
        $order = $this->report($this->plan(), 300);
        $this->actingAs($this->master)->patch(route('production.orders.confirm', $order->id));
        $this->assertSame(300.0, $this->stock());

        $this->actingAs($this->director)->delete(route('production.orders.destroy', $order->id))
            ->assertSessionHasNoErrors();

        $this->assertSame(0.0, $this->stock());
        // Приход не стёрли — он был, и его видели; рядом лежит сторно.
        $this->assertSame(1, StockMovement::where('type', StockMovement::PRODUCTION_IN)->count());
        $this->assertSame(1, StockMovement::where('type', StockMovement::REVERSAL)->count());
    }

    /** Остаток сходится с суммой движений. */
    public function test_the_stored_stock_matches_the_movements(): void
    {
        $plan = $this->plan();
        foreach ([120, 80, 55.5] as $qty) {
            $order = $this->report($plan, $qty);
            $this->actingAs($this->master)->patch(route('production.orders.confirm', $order->id));
        }

        $this->assertSame(255.5, $this->stock());
        $this->assertCount(0, app(StockService::class)->drift());
    }

    /** Склад показывает остаток и что произведено за месяц. */
    public function test_the_warehouse_page_shows_the_goods(): void
    {
        $order = $this->report($this->plan(), 640);
        $this->actingAs($this->master)->patch(route('production.orders.confirm', $order->id));

        $this->actingAs($this->director)->get(route('warehouse.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('products', fn ($products) => collect($products)->contains(
                    fn ($p) => $p['name'] === 'Плитка «Большой формат» 600×300×80'
                        && (float) $p['qty'] === 640.0
                        && (float) $p['produced'] === 640.0))
                ->etc());
    }

    /**
     * Новая сделка с нехваткой уведомляет производство.
     *
     * Сделку не блокируем: договор подписан, отменять его складом поздно. Но
     * если менеджер продал 1000 м², а на складе 200, начальник производства
     * должен узнать в тот же день.
     */
    public function test_a_shortage_notifies_production(): void
    {
        Notification::fake();

        // Кладём 200 м² на склад.
        $order = $this->report($this->plan(), 200);
        $this->actingAs($this->master)->patch(route('production.orders.confirm', $order->id));

        $this->actingAs($this->manager)->post(route('deals.store'), [
            'company_name' => 'Акимат',
            'client_name' => 'Плитка',
            'address' => 'Шымкент',
            'budget' => 12000000,
            'items' => [['product_id' => $this->tile->id, 'quantity' => 1000, 'price' => 12000]],
        ])->assertSessionHasNoErrors();

        // Сделка создана — её не режем.
        $this->assertSame(1, Deal::count());

        Notification::assertSentTo($this->director, ProductShortage::class,
            function (ProductShortage $n) {
                return (float) $n->rows[0]['short'] === 800.0
                    && (float) $n->rows[0]['have'] === 200.0;
            });
        Notification::assertNotSentTo($this->manager, ProductShortage::class);
    }

    /** Хватает на складе — никого не дёргаем. */
    public function test_no_notification_when_the_stock_is_enough(): void
    {
        Notification::fake();

        $order = $this->report($this->plan(), 1000);
        $this->actingAs($this->master)->patch(route('production.orders.confirm', $order->id));

        $this->actingAs($this->manager)->post(route('deals.store'), [
            'company_name' => 'Акимат',
            'client_name' => 'Плитка',
            'address' => 'Шымкент',
            'budget' => 3600000,
            'items' => [['product_id' => $this->tile->id, 'quantity' => 300, 'price' => 12000]],
        ])->assertSessionHasNoErrors();

        Notification::assertNothingSent();
    }
}
