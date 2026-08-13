<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\CartService;
use App\Services\OrderService;
use App\Support\SiteContent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Оформление заказа: корзина → заказ в ERP → страница «спасибо». */
class CheckoutController extends Controller
{
    public function __construct(private CartService $cart, private OrderService $orders) {}

    public function show(): Response|RedirectResponse
    {
        $contents = $this->cart->contents();
        if (! $contents['items']) {
            return redirect()->route('site.cart');
        }

        return Inertia::render('Site/Checkout', [
            'cart' => $contents,
            'cities' => array_column(SiteContent::branches(), 'city'),
            'delivery' => SiteContent::delivery(),
            'seo' => ['title' => __('site.seo.checkout_title'), 'description' => __('site.seo.checkout_description')],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $contents = $this->cart->contents();
        if (! $contents['items']) {
            return redirect()->route(\App\Support\Locales::routeName('site.cart', app()->getLocale()))
                ->with('error', __('site.flash.cart_empty'));
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:120'],
            'city' => ['nullable', 'string', 'max:80'],
            'address' => ['nullable', 'string', 'max:255'],
            'delivery' => ['required', 'in:delivery,pickup'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $order = $this->orders->createFromCart($contents, $data);
        $this->cart->clear();

        return redirect()->route('site.thanks', ['order' => $order->number]);
    }

    public function thanks(Request $request): Response
    {
        $order = Order::where('number', (string) $request->query('order'))->with('items')->first();

        return Inertia::render('Site/Thanks', [
            'order' => $order ? [
                'number' => $order->number,
                'total' => (float) $order->total,
                'items' => $order->items->map(fn ($i) => [
                    'name' => $i->name, 'quantity' => (float) $i->quantity, 'unit' => $i->unit, 'sum' => (float) $i->sum,
                ]),
            ] : null,
            'seo' => ['title' => __('site.seo.thanks_title'), 'description' => __('site.seo.thanks_description')],
        ]);
    }
}
