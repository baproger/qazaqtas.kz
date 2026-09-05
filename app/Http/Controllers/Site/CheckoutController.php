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

    public function show(Request $request): Response|RedirectResponse
    {
        $contents = $this->cart->contents();
        if (! $contents['items']) {
            return redirect()->route('site.cart');
        }

        // Тайм-капкан: человек не заполняет форму быстрее пяти секунд.
        $request->session()->put('checkout.opened_at', now()->getTimestamp());

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

        // --- Антиспам, до валидации ---
        // Honeypot: поле «website» скрыто стилями, человек его не видит и не
        // заполняет. Боту отвечаем «успехом» без заказа — пусть считает, что
        // спам прошёл, и не подбирает обход.
        if ($request->filled('website')) {
            return redirect()->route('site.thanks');
        }

        // Тайм-капкан: сабмит без открытой формы или быстрее 5 секунд — бот.
        $openedAt = (int) $request->session()->get('checkout.opened_at', 0);
        if ($openedAt === 0 || now()->getTimestamp() - $openedAt < 5) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'name' => __('site.checkout.err_too_fast'),
            ]);
        }

        // Данные клиента попадают в ERP как есть — валидируем строго, с
        // человеческими подсказками на языке страницы.
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120', "regex:/^[\p{L}][\p{L}\s.'-]*$/u"],
            'phone' => ['required', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:120'],
            'city' => ['nullable', 'required_if:delivery,delivery', 'string', 'max:80'],
            'address' => ['nullable', 'required_if:delivery,delivery', 'string', 'min:5', 'max:255'],
            'delivery' => ['required', 'in:delivery,pickup'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ], [
            'name.regex' => __('site.checkout.err_name'),
            'name.min' => __('site.checkout.err_name'),
            'city.required_if' => __('site.checkout.err_city'),
            'address.required_if' => __('site.checkout.err_address'),
            'address.min' => __('site.checkout.err_address'),
        ]);

        // Телефон приводим к единому виду +7 7XX XXX XX XX: менеджер звонит
        // по нему из ERP, мусор в этом поле стоит потерянного заказа.
        $digits = preg_replace('/\D/', '', $data['phone']);
        if (strlen($digits) === 10 && $digits[0] === '7') {
            $digits = '7'.$digits; // ввели без кода страны: 7XX...
        }
        if (strlen($digits) !== 11 || ! in_array($digits[0], ['7', '8'], true)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'phone' => __('site.checkout.err_phone'),
            ]);
        }
        $data['phone'] = '+7 '.substr($digits, 1, 3).' '.substr($digits, 4, 3).' '.substr($digits, 7, 2).' '.substr($digits, 9, 2);

        // Дубль-защита: повторная отправка с тем же телефоном за две минуты
        // (двойной клик, нетерпеливое обновление) не плодит копии заказа.
        $recent = Order::where('phone', $data['phone'])->where('created_at', '>=', now()->subMinutes(2))->latest('id')->first();
        if ($recent) {
            $this->cart->clear();
            $request->session()->put('site.last_order', $recent->number);

            return redirect()->route('site.thanks', ['order' => $recent->number]);
        }

        $order = $this->orders->createFromCart($contents, $data);
        $this->cart->clear();

        // Пропуск на страницу «спасибо»: состав заказа видит только тот, кто
        // его только что оформил. Номера последовательные (ZT-2026-NNN) — без
        // этой метки чужие заказы читались бы простым перебором адреса.
        $request->session()->put('site.last_order', $order->number);

        return redirect()->route('site.thanks', ['order' => $order->number]);
    }

    public function thanks(Request $request): Response
    {
        $number = (string) $request->query('order');
        // Показываем заказ только его автору (метка в сессии) — иначе
        // страница остаётся общим «спасибо» без состава и сумм.
        $order = $number !== '' && $number === $request->session()->get('site.last_order')
            ? Order::where('number', $number)->with('items')->first()
            : null;

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
