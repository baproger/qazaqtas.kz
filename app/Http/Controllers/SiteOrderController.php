<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\OrderService;
use App\Support\StickyFilters;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ERP → Заказы с сайта. Менеджер видит заявку сразу после оформления и
 * одной кнопкой переводит её в сделку — дальше обычная воронка ERP.
 */
class SiteOrderController extends Controller
{
    public function __construct(private OrderService $orders) {}

    public function index(Request $request): Response
    {
        // Фильтр переживает уход со страницы: пришли без параметров —
        // подставляем сохранённый набор (App\Support\StickyFilters).
        StickyFilters::apply($request, 'orders', ['status', 'search']);

        $this->guard($request);

        $orders = Order::with(['items', 'deal:id,number', 'manager:id,name'])
            ->when($request->string('status')->toString(), fn ($q, $s) => $q->where('status', $s))
            ->when($request->string('search')->toString(), fn ($q, $s) => $q
                ->where(fn ($w) => $w->where('number', 'like', "%{$s}%")
                    ->orWhere('name', 'like', "%{$s}%")
                    ->orWhere('phone', 'like', "%{$s}%")))
            ->latest()->paginate(25)->withQueryString();

        return Inertia::render('SiteOrders/Index', [
            'orders' => $orders,
            'filters' => $request->only('status', 'search'),
            'statuses' => Order::STATUSES,
            'stats' => [
                'new' => Order::where('status', 'new')->count(),
                'month' => Order::whereDate('created_at', '>=', now()->startOfMonth())->count(),
                'monthSum' => (float) Order::whereDate('created_at', '>=', now()->startOfMonth())->sum('total'),
            ],
        ]);
    }

    public function update(Request $request, Order $order): RedirectResponse
    {
        $this->guard($request);

        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(Order::STATUSES))],
        ]);

        $order->update($data + ['manager_id' => $order->manager_id ?? $request->user()->id]);

        return back()->with('success', 'Статус заказа обновлён.');
    }

    /** «Создать сделку» — переиспользует нумерацию и воронку ERP. */
    public function convert(Request $request, Order $order): RedirectResponse
    {
        $this->guard($request);

        if ($order->deal) {
            return back()->with('error', 'По заказу уже создана сделка '.$order->deal->number.'.');
        }

        $deal = $this->orders->convertToDeal($order, $request->user());

        return redirect()->route('deals.show', $deal)->with('success', 'Сделка '.$deal->number.' создана из заказа '.$order->number.'.');
    }

    public function destroy(Request $request, Order $order): RedirectResponse
    {
        abort_unless($request->user()->hasAnyRole(['admin', 'financist']), 403);
        $order->delete();

        return back()->with('success', 'Заказ удалён.');
    }

    private function guard(Request $request): void
    {
        abort_unless(
            $request->user()->hasAnyRole(['admin', 'director', 'financist', 'manager']),
            403,
            'Заказы с сайта доступны менеджерам и руководству.'
        );
    }
}
