<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\CartService;
use App\Services\CatalogService;
use App\Services\OrderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Корзина витрины. Хранится в сессии, цены — из ERP. */
class CartController extends Controller
{
    public function __construct(
        private CartService $cart,
        private OrderService $orders,
        private CatalogService $catalog,
    ) {}

    public function show(): Response
    {
        $contents = $this->cart->contents();

        return Inertia::render('Site/Cart', [
            'cart' => $contents,
            'whatsapp' => $this->orders->whatsappLink($contents),
            'recommended' => $this->catalog->featured(4),
            'delivery' => \App\Support\SiteContent::delivery(),
            'seo' => ['title' => 'Корзина — QAZAQ TAS', 'description' => 'Ваш заказ изделий из мраморного композита.'],
        ]);
    }

    public function add(Request $request, Product $product): RedirectResponse
    {
        abort_unless($product->is_active, 404);

        $data = $request->validate([
            'quantity' => ['nullable', 'numeric', 'min:0.01', 'max:100000'],
            'color' => ['nullable', 'string', 'max:100'],
        ]);

        $this->cart->add($product, (float) ($data['quantity'] ?? $product->min_order ?: 1), $data['color'] ?? null);

        return back()->with('success', $product->name.' — добавлено в корзину.');
    }

    /** Добавление сразу нескольких позиций (конфигуратор двора). */
    public function addMany(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:20'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.01', 'max:100000'],
            'items.*.color' => ['nullable', 'string', 'max:100'],
        ]);

        $products = Product::active()->whereIn('id', array_column($data['items'], 'product_id'))->get()->keyBy('id');

        foreach ($data['items'] as $item) {
            if ($product = $products->get($item['product_id'])) {
                $this->cart->add($product, (float) $item['quantity'], $item['color'] ?? null);
            }
        }

        return redirect()->route('site.cart')->with('success', 'Расчёт добавлен в корзину.');
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:50'],
            'quantity' => ['required', 'numeric', 'min:0', 'max:100000'],
        ]);

        $this->cart->update($data['key'], (float) $data['quantity']);

        return back();
    }

    public function remove(Request $request): RedirectResponse
    {
        $data = $request->validate(['key' => ['required', 'string', 'max:50']]);
        $this->cart->remove($data['key']);

        return back()->with('success', 'Позиция удалена из корзины.');
    }

    public function clear(): RedirectResponse
    {
        $this->cart->clear();

        return back()->with('success', 'Корзина очищена.');
    }
}
