<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Deal;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Notifications\SiteOrderReceived;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Витрина QAZAQ TAS: каталог читается из ERP, корзина живёт в сессии,
 * заказ сразу появляется в ERP и одной кнопкой превращается в сделку.
 */
class SiteShopTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);
        $this->seed(CatalogSeeder::class);
    }

    private function paving(): Product
    {
        return Product::where('code', 'QT-P-300')->firstOrFail();
    }

    // ---- Витрина ----

    public function test_public_pages_open_without_login(): void
    {
        foreach (['site.home', 'site.catalog', 'site.cart', 'site.about', 'site.projects', 'site.contacts'] as $name) {
            $this->get(route($name))->assertOk();
        }
    }

    public function test_catalog_shows_only_published_products(): void
    {
        $hidden = $this->paving();
        $hidden->update(['is_active' => false]);

        $this->get(route('site.catalog'))
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->component('Site/Catalog')
                ->where('products.data', fn ($items) => collect($items)->doesntContain('code', 'QT-P-300')));

        // Скрытая карточка недоступна и по прямой ссылке.
        $this->get(route('site.product', $hidden->slug))->assertNotFound();
    }

    public function test_catalog_filters_by_category_search_and_price(): void
    {
        $this->get(route('site.catalog', ['category' => 'bordyury']))
            ->assertInertia(fn (Assert $p) => $p->where('products.data', fn ($items) => collect($items)->every(
                fn ($i) => $i['category']['slug'] === 'bordyury',
            )));

        $this->get(route('site.catalog', ['search' => 'Кирпичик']))
            ->assertInertia(fn (Assert $p) => $p->has('products.data', 1));

        $this->get(route('site.catalog', ['min' => 100000]))
            ->assertInertia(fn (Assert $p) => $p->where('products.data', fn ($items) => collect($items)->every(
                fn ($i) => (float) $i['price'] >= 100000,
            )));
    }

    public function test_product_page_carries_specs_for_calculators(): void
    {
        $this->get(route('site.product', $this->paving()->slug))
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->component('Site/Product')
                ->where('product.specs.pieces_per_m2', 11.1)
                ->has('related'));
    }

    // ---- Корзина ----

    public function test_cart_keeps_quantity_and_recalculates_totals(): void
    {
        $product = $this->paving();

        $this->post(route('site.cart.add', $product->slug), ['quantity' => 40, 'color' => 'Антрацит'])
            ->assertRedirect();

        $this->get(route('site.cart'))
            ->assertInertia(fn (Assert $p) => $p
                ->where('cart.count', 1)
                ->where('cart.total', 40 * (int) $product->price)
                ->where('cart.items.0.color', 'Антрацит'));
    }

    public function test_cart_never_drops_below_minimum_order(): void
    {
        $product = $this->paving(); // min_order = 10 м²

        $this->post(route('site.cart.add', $product->slug), ['quantity' => 2]);

        $this->get(route('site.cart'))
            ->assertInertia(fn (Assert $p) => $p->where('cart.items.0.quantity', 10));
    }

    /** Массовое добавление позиций (route cart.addMany) живёт и без конфигуратора. */
    public function test_add_many_positions_at_once(): void
    {
        $paving = $this->paving();
        $curb = Product::where('code', 'QT-B-1000')->firstOrFail();

        $this->post(route('site.cart.addMany'), ['items' => [
            ['product_id' => $paving->id, 'quantity' => 84, 'color' => 'Песочный'],
            ['product_id' => $curb->id, 'quantity' => 36],
        ]])->assertRedirect(route('site.cart'));

        $this->get(route('site.cart'))
            ->assertInertia(fn (Assert $p) => $p->where('cart.count', 2));
    }

    public function test_cart_price_always_comes_from_erp(): void
    {
        $product = $this->paving();
        $this->post(route('site.cart.add', $product->slug), ['quantity' => 10]);

        // Менеджер поменял цену в ERP — корзина показывает новую.
        $product->update(['price' => 12000]);

        $this->get(route('site.cart'))
            ->assertInertia(fn (Assert $p) => $p->where('cart.total', 120000));
    }

    // ---- Заказ ----

    public function test_checkout_creates_order_in_erp_and_notifies_managers(): void
    {
        Notification::fake();

        $manager = User::factory()->create(['is_active' => true]);
        $manager->assignRole('manager');

        $this->post(route('site.cart.add', $this->paving()->slug), ['quantity' => 120]);

        $this->post(route('site.checkout.store'), [
            'name' => 'Асхат',
            'phone' => '+7 701 123 45 67',
            'city' => 'Шымкент',
            'address' => 'ЖК Керемет',
            'delivery' => 'delivery',
            'comment' => 'Цвет «Серый графит»',
        ])->assertRedirect();

        $order = Order::with('items')->firstOrFail();
        $this->assertSame('Асхат', $order->name);
        $this->assertSame('new', $order->status);
        $this->assertSame(1068000.0, (float) $order->total);
        $this->assertCount(1, $order->items);
        $this->assertStringStartsWith('ZT-', $order->number);

        Notification::assertSentTo($manager, SiteOrderReceived::class);

        // Корзина очищается — повторная отправка того же заказа невозможна.
        $this->get(route('site.cart'))->assertInertia(fn (Assert $p) => $p->where('cart.count', 0));
    }

    public function test_checkout_requires_name_and_phone(): void
    {
        $this->post(route('site.cart.add', $this->paving()->slug), ['quantity' => 20]);

        $this->post(route('site.checkout.store'), ['delivery' => 'delivery'])
            ->assertSessionHasErrors(['name', 'phone']);

        $this->assertSame(0, Order::count());
    }

    // ---- ERP ----

    public function test_manager_sees_orders_and_converts_them_to_deal(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('manager');
        // Заказ витрины принадлежит фирме — переводить его в сделку может
        // только сотрудник этой фирмы.
        $manager->companies()->attach(Company::orderBy('id')->value('id'));

        $this->post(route('site.cart.add', $this->paving()->slug), ['quantity' => 120]);
        $this->post(route('site.checkout.store'), [
            'name' => 'Асхат', 'phone' => '+77011234567', 'delivery' => 'pickup',
        ]);
        $order = Order::firstOrFail();

        $this->actingAs($manager)->get(route('siteOrders.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('SiteOrders/Index')->has('orders.data', 1));

        $this->actingAs($manager)->post(route('siteOrders.convert', $order))->assertRedirect();

        $order->refresh();
        $this->assertNotNull($order->deal_id);
        $this->assertSame('in_work', $order->status);
        $this->assertSame(1068000.0, (float) $order->deal->budget);
        // Сделка встаёт на первый этап обычной воронки ERP.
        $this->assertSame('Заявка', $order->deal->stage->name);
        $this->assertStringContainsString('Плитка', $order->deal->description);

        // Повторная конвертация не плодит сделки.
        $this->actingAs($manager)->post(route('siteOrders.convert', $order))->assertSessionHas('error');
        $this->assertSame(1, Deal::count());
    }

    public function test_orders_page_is_closed_for_workshop_employees(): void
    {
        $worker = User::factory()->create();
        $worker->assignRole('employee');

        $this->actingAs($worker)->get(route('siteOrders.index'))->assertForbidden();
    }

    // ---- Каталог в ERP ----

    public function test_admin_manages_catalog_and_site_sees_changes(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $category = ProductCategory::where('slug', 'vazony')->firstOrFail();

        $this->actingAs($admin)->post(route('catalog.store'), [
            'category_id' => $category->id,
            'name' => 'Вазон «Тараз» Ø700',
            'unit' => 'шт',
            'price' => 61000,
            'is_active' => true,
            'specs' => ['size' => 'Ø 700 × 600 мм'],
            'colors' => [['name' => 'Песочный', 'hex' => '#D8C3A0']],
        ])->assertRedirect();

        $product = Product::where('name', 'Вазон «Тараз» Ø700')->firstOrFail();
        // Слаг транслитерируется, а не превращается в случайную строку.
        $this->assertSame('vazon-taraz-o700', $product->slug);

        $this->get(route('site.product', $product->slug))->assertOk();

        $this->actingAs($admin)->put(route('catalog.update', $product), [
            'category_id' => $category->id, 'name' => $product->name, 'unit' => 'шт',
            'price' => 61000, 'is_active' => false,
        ])->assertRedirect();

        // Снятая с публикации позиция пропадает с витрины.
        $this->get(route('site.product', $product->slug))->assertNotFound();
    }

    public function test_catalog_is_closed_for_users_without_product_rights(): void
    {
        $worker = User::factory()->create();
        $worker->assignRole('employee');

        $this->actingAs($worker)->get(route('catalog.index'))->assertForbidden();
    }
}
