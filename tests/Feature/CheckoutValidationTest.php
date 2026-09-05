<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Валидация оформления заказа: данные клиента уходят менеджеру в ERP,
 * поэтому мусор в имени/телефоне/адресе стоит потерянного заказа.
 */
class CheckoutValidationTest extends TestCase
{
    use RefreshDatabase;

    /** Наполняем корзину — без неё оформление уводит в каталог. */
    private function cartWithItem(): Product
    {
        $product = Product::create([
            'name' => 'Плитка «Тест»', 'slug' => 'plitka-test', 'unit' => 'м²',
            'price' => 9000, 'min_order' => 1, 'is_active' => true, 'is_service' => false,
        ]);
        $this->post(route('site.cart.add', $product->slug), ['quantity' => 10])->assertRedirect();

        return $product;
    }

    private function valid(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Ерлан Абаев', 'phone' => '8 707 123 45 67', 'email' => 'client@mail.kz',
            'city' => 'Шымкент', 'address' => 'ул. Промышленная, 1', 'delivery' => 'delivery',
        ], $overrides);
    }

    public function test_phone_is_normalized_to_plus7_format(): void
    {
        $this->cartWithItem();

        $this->post(route('site.checkout.store'), $this->valid())->assertSessionHasNoErrors();

        $this->assertSame('+7 707 123 45 67', Order::firstOrFail()->phone);
    }

    public function test_garbage_phone_is_rejected_with_readable_message(): void
    {
        $this->cartWithItem();

        $this->post(route('site.checkout.store'), $this->valid(['phone' => '12345']))
            ->assertSessionHasErrors(['phone']);
        $this->assertSame(0, Order::count());
    }

    public function test_name_with_digits_is_rejected(): void
    {
        $this->cartWithItem();

        $this->post(route('site.checkout.store'), $this->valid(['name' => 'qwe123']))
            ->assertSessionHasErrors(['name']);
    }

    public function test_delivery_requires_city_and_address(): void
    {
        $this->cartWithItem();

        $this->post(route('site.checkout.store'), $this->valid(['city' => '', 'address' => '']))
            ->assertSessionHasErrors(['city', 'address']);
    }

    public function test_pickup_needs_no_address(): void
    {
        $this->cartWithItem();

        $this->post(route('site.checkout.store'), $this->valid(['delivery' => 'pickup', 'city' => '', 'address' => '']))
            ->assertSessionHasNoErrors();
        $this->assertSame(1, Order::count());
    }
}
