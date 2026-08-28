<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\Order;
use App\Models\User;
use App\Notifications\SiteOrderReceived;
use App\Support\RoleTraits;
use App\Support\SiteContent;
use Illuminate\Support\Facades\DB;

/**
 * Заказы с сайта: создание из корзины и перевод в сделку ERP.
 *
 * Номер сделки и вся дальнейшая логика — существующие сервисы ERP
 * (DealNumberService, воронка, гейты): витрина ничего не дублирует.
 */
class OrderService
{
    public function __construct(private DealNumberService $dealNumbers) {}

    /**
     * @param  array{items: array<int, array<string, mixed>>, total: float}  $cart
     * @param  array<string, mixed>  $customer
     */
    public function createFromCart(array $cart, array $customer, string $source = 'site'): Order
    {
        return DB::transaction(function () use ($cart, $customer, $source) {
            $order = Order::create([
                'number' => $this->nextNumber(),
                'company_id' => Company::orderBy('id')->value('id'),
                'name' => $customer['name'],
                'phone' => $customer['phone'],
                'email' => $customer['email'] ?? null,
                'city' => $customer['city'] ?? null,
                'address' => $customer['address'] ?? null,
                'delivery' => $customer['delivery'] ?? 'delivery',
                'comment' => $customer['comment'] ?? null,
                'total' => $cart['total'],
                'status' => 'new',
                'source' => $source,
            ]);

            foreach ($cart['items'] as $item) {
                $order->items()->create([
                    'product_id' => $item['product_id'] ?? null,
                    'name' => $item['name'],
                    'unit' => $item['unit'] ?? 'шт',
                    'color' => $item['color'] ?? null,
                    'price' => $item['price'],
                    'quantity' => $item['quantity'],
                    'sum' => $item['sum'],
                ]);
            }

            $this->notifyManagers($order);

            return $order->load('items');
        });
    }

    /**
     * «В сделку»: заказ становится обычной сделкой на первом этапе воронки.
     * Повторный вызов возвращает уже созданную сделку.
     */
    public function convertToDeal(Order $order, ?User $author = null): Deal
    {
        if ($order->deal) {
            return $order->deal;
        }

        return DB::transaction(function () use ($order, $author) {
            $order->loadMissing('items');
            $companyId = $order->company_id ?: Company::orderBy('id')->value('id');
            $company = $companyId ? Company::find($companyId) : null;

            $lines = $order->items
                ->map(fn ($i) => '· '.$i->name.' — '.rtrim(rtrim(number_format((float) $i->quantity, 2, '.', ' '), '0'), '.')
                    .' '.$i->unit.($i->color ? ' ('.$i->color.')' : ''))
                ->implode("\n");

            $deal = Deal::create([
                'number' => $this->dealNumbers->generate($company),
                'company_id' => $companyId,
                'name' => $order->name,
                'company_name' => $order->name,
                'client_name' => $order->items->first()?->name ?? 'Заказ с сайта',
                'address' => $order->address,
                'budget' => $order->total,
                'status' => 'active',
                'source' => 'Сайт',
                'deal_stage_id' => DealStage::funnel($companyId)->first()?->id,
                'responsible_user_id' => $order->manager_id ?? $author?->id,
                'description' => "Заказ с сайта {$order->number} от {$order->created_at->format('d.m.Y H:i')}\n"
                    ."Контакт: {$order->phone}".($order->email ? ", {$order->email}" : '')."\n"
                    .($order->city ? "Город: {$order->city}\n" : '')
                    .($order->comment ? "Комментарий: {$order->comment}\n" : '')
                    ."Состав заказа:\n{$lines}",
            ]);

            $order->update(['deal_id' => $deal->id, 'status' => 'in_work', 'manager_id' => $order->manager_id ?? $author?->id]);

            return $deal;
        });
    }

    /** Ссылка «Заказать в WhatsApp» с составом корзины. */
    public function whatsappLink(array $cart, ?string $note = null): string
    {
        $phone = SiteContent::contacts()['whatsapp'];

        $lines = ['Здравствуйте! Хочу заказать в QAZAQ TAS:'];
        foreach ($cart['items'] as $item) {
            $qty = rtrim(rtrim(number_format((float) $item['quantity'], 2, '.', ' '), '0'), '.');
            $lines[] = '• '.$item['name'].' — '.$qty.' '.$item['unit']
                .($item['color'] ? ' ('.$item['color'].')' : '');
        }
        $lines[] = 'Итого: '.number_format((float) $cart['total'], 0, '.', ' ').' ₸';
        if ($note) {
            $lines[] = $note;
        }

        return 'https://wa.me/'.$phone.'?text='.rawurlencode(implode("\n", $lines));
    }

    private function nextNumber(): string
    {
        $year = now()->format('Y');
        $last = Order::where('number', 'like', "ZT-{$year}-%")->orderByDesc('id')->value('number');
        $next = $last ? ((int) substr($last, -4)) + 1 : 1;

        return sprintf('ZT-%s-%04d', $year, $next);
    }

    /** Новый заказ видят менеджеры, финансисты и руководство. */
    private function notifyManagers(Order $order): void
    {
        User::where('is_active', true)
            ->whereIn('id', RoleTraits::users(['manager', 'financist', 'admin', 'director'])->select('id'))
            ->get()
            ->each->notify(new SiteOrderReceived($order));
    }
}
