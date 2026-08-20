<?php

namespace App\Http\Controllers;

use App\Models\Deal;
use App\Models\DealStage;
use App\Models\PreDeal;
use App\Models\PreDealChecklistItem;
use App\Models\User;
use App\Services\DealNumberService;
use App\Support\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Заявки / запросы КП: менеджер вносит объём и цены, система считает маржу.
 * Маржа ≥ порога (15%) — заявку можно перевести в сделку («В работу ✓»):
 * создаётся настоящая сделка на первом этапе воронки. Каждый менеджер видит
 * только свои заявки; руководство — все + рейтинг менеджеров.
 */
class PreDealController extends Controller
{
    private function leadership(Request $request): bool
    {
        return $request->user()->hasAnyRole(['admin', 'director', 'financist']);
    }

    private function guardAccess(Request $request): void
    {
        abort_unless($request->user()->hasAnyRole(['admin', 'director', 'financist', 'manager']), 403);
    }

    private function guardOwner(Request $request, PreDeal $preDeal): void
    {
        abort_unless($this->leadership($request) || $preDeal->user_id === $request->user()->id, 403);
    }

    public function index(Request $request): Response
    {
        $this->guardAccess($request);
        $lead = $this->leadership($request);
        $companyId = CurrentCompany::id() ?: null;

        $q = PreDeal::query()
            ->when($companyId, fn ($qq, $c) => $qq->where('company_id', $c))
            ->with(['user:id,name,avatar', 'deal:id,number'])
            ->latest();
        // Персонализация: менеджер видит ТОЛЬКО свои заявки.
        if (! $lead) {
            $q->where('user_id', $request->user()->id);
        } elseif ($mid = (int) $request->query('manager', 0)) {
            $q->where('user_id', $mid);
        }
        if ($st = $request->string('status')->toString()) {
            $q->where('status', $st);
        }
        // Фильтр по месяцу ВНЕСЕНИЯ заявки (YYYY-MM): какие заявки в какой день вводили.
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m = $request->string('month')->toString())) {
            $start = $m.'-01';
            $q->whereDate('created_at', '>=', $start)
                ->whereDate('created_at', '<=', \Illuminate\Support\Carbon::parse($start)->endOfMonth()->toDateString());
        }

        // Рейтинг менеджеров (руководству): переведено в сделки / на какую сумму.
        $stats = null;
        if ($lead) {
            $rows = PreDeal::query()
                ->when($companyId, fn ($qq, $c) => $qq->where('company_id', $c))
                ->selectRaw("user_id, count(*) total, sum(case when status = 'confirmed' then 1 else 0 end) confirmed, sum(case when status = 'confirmed' then contract_sum else 0 end) confirmed_sum")
                ->groupBy('user_id')->get();
            $names = User::whereIn('id', $rows->pluck('user_id'))->get(['id', 'name', 'avatar'])->keyBy('id');
            $stats = $rows->filter(fn ($r) => $names->has($r->user_id))
                ->map(fn ($r) => [
                    'name' => $names[$r->user_id]->name,
                    'avatar' => $names[$r->user_id]->avatar,
                    'total' => (int) $r->total,
                    'confirmed' => (int) $r->confirmed,
                    'sum' => (float) $r->confirmed_sum,
                ])->sortByDesc('confirmed')->values();
        }

        return Inertia::render('PreDeals/Index', [
            'preDeals' => $q->limit(300)->get(),
            'items' => PreDealChecklistItem::where('is_active', true)->orderBy('order')->get(['id', 'label']),
            'minMargin' => PreDeal::minMargin(),
            'taxPercent' => (float) \App\Models\Setting::get('tax_percent', 3),
            'leadership' => $lead,
            'stats' => $stats,
            'managers' => $lead ? User::role('manager')->where('is_active', true)->orderBy('name')->get(['id', 'name']) : [],
            'filters' => $request->only('manager', 'status', 'month'),
            'canManageChecklist' => $request->user()->hasAnyRole(['admin', 'financist']),
            // Товары заявки выбираются так же, как в сделке: категория → товар.
            'catalog' => \App\Models\Product::catalogForPicker(),
            'productCategories' => \App\Models\Product::pickerCategories(),
        ]);
    }

    /** @return array<string, mixed> */
    /**
     * Свести позиции в цифры заявки: сумма КП = Σ (количество × цена), закуп
     * = Σ (количество × закупочная цена).
     *
     * Одиночные поля (объём, цена за единицу) при этом обнуляются: иначе
     * PreDeal::calculate пересчитал бы сумму по ним и она разошлась бы с
     * составом заказа.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function applyItems(array $items, array $data): array
    {
        $service = app(\App\Services\DealItemService::class);
        $rows = $service->normalize($items, withPurchasePrice: true);
        if ($rows === []) {
            return $data;
        }

        $data['contract_sum'] = $service->total($rows);
        $purchase = $service->purchaseTotal($rows);
        if ($purchase > 0) {
            $data['purchase_price'] = $purchase;
        }
        // Обнуляем, а не убираем: колонки NOT NULL, а нулевые объём и цена
        // за единицу заставляют PreDeal::calculate взять готовую сумму строк.
        $data['quantity'] = 0;
        $data['unit_price'] = 0;
        // Изделие — перечень позиций: он идёт в название сделки и в списки.
        $data['product'] = collect($rows)->pluck('name')->join(', ');

        return $data;
    }

    private function validated(Request $request, ?PreDeal $ignore = null): array
    {
        return $request->validate([
            // Уникальный № заявки: менеджеры не заводят одну заявку дважды
            // (при правке — без ложного срабатывания на самого себя).
            'request_number' => ['nullable', 'string', 'max:100',
                \Illuminate\Validation\Rule::unique('pre_deals', 'request_number')->ignore($ignore?->id)],
            'valid_until' => ['nullable', 'date'],
            'bin' => ['nullable', 'string', 'max:40'],
            'customer' => ['nullable', 'string', 'max:255'],
            'object_address' => ['nullable', 'string', 'max:255'],
            'client_name' => ['nullable', 'string', 'max:255'],
            'client_phone' => ['nullable', 'string', 'max:40'],
            // При нескольких позициях изделие и сумма берутся из строк —
            // одиночные поля тогда не обязательны.
            'product' => ['required_without:items', 'nullable', 'string', 'max:255'],
            'quantity' => ['nullable', 'numeric', 'min:0'],
            'unit' => ['nullable', \Illuminate\Validation\Rule::in(\App\Models\Deal::UNITS)],
            'unit_price' => ['nullable', 'numeric', 'min:0'],
            'contract_sum' => ['required_without:items', 'nullable', 'numeric', 'min:1'],
            'items' => ['sometimes', 'array'],
            'items.*.product_id' => ['nullable', 'integer', 'exists:products,id'],
            'items.*.name' => ['nullable', 'string', 'max:255'],
            'items.*.quantity' => ['nullable', 'numeric', 'min:0'],
            'items.*.price' => ['nullable', 'numeric', 'min:0'],
            'items.*.purchase_price' => ['nullable', 'numeric', 'min:0'],
            'purchase_price' => ['nullable', 'numeric', 'min:0'],
            'partner_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'delivery' => ['nullable', 'numeric', 'min:0'],
            'assembly' => ['nullable', 'numeric', 'min:0'],
            'commission' => ['nullable', 'numeric', 'min:0'],
        ], [
            'request_number.unique' => 'Такой № заявки уже существует — эта заявка уже внесена.',
        ]);
    }

    /**
     * Быстрая проверка № заявки ДО заполнения формы: занят ли номер, кем и
     * когда — менеджер не тратит время на ввод остальных полей. ignore — id
     * правящейся заявки (свой номер при правке не считается занятым).
     */
    /**
     * Откат случайного «В работу ✓»: созданная сделка удаляется (soft, номер
     * освобождается хуками Deal), заявка возвращается в «В работе». Разрешён
     * ТОЛЬКО пока по сделке нет движения — счетов/расходов/заказа цеха.
     */
    public function revert(Request $request, PreDeal $preDeal): RedirectResponse
    {
        $this->guardOwner($request, $preDeal);
        $deal = $preDeal->deal;
        if ($preDeal->status !== 'confirmed' || ! $deal) {
            return back()->with('error', 'Заявка не подтверждена — возвращать нечего.');
        }
        // Авто-расходы, созданные из самой заявки (🚚/🔧, «Из заявки…», без нал/банк),
        // откату не мешают — удаляются вместе со сделкой. Блокируют только
        // РУЧНЫЕ расходы, счета и заказ цеха.
        $manualExpenses = $deal->expenses()
            ->where(fn ($q) => $q->whereNotIn('type', ['delivery', 'assembly'])
                ->orWhereNotNull('payment_method')
                ->orWhere('description', 'not like', 'Из заявки%'))
            ->exists();
        if ($deal->invoices()->exists() || $manualExpenses || $deal->project()->exists()) {
            return back()->with('error', 'По сделке '.$deal->number.' уже есть счета/расходы/заказ цеха — откат невозможен, обратитесь к администратору.');
        }

        $deal->expenses()->where('description', 'like', 'Из заявки%')->delete();
        $preDeal->update(['status' => 'new', 'deal_id' => null]);
        $deal->delete();

        return back()->with('success', 'Возвращено: сделка '.$deal->number.' удалена, заявка снова «В работе».');
    }

    public function checkNumber(Request $request): \Illuminate\Http\JsonResponse
    {
        $this->guardAccess($request);
        $data = $request->validate([
            'request_number' => ['required', 'string', 'max:100'],
            'ignore' => ['nullable', 'integer'],
        ]);

        $existing = PreDeal::where('request_number', trim($data['request_number']))
            ->when($data['ignore'] ?? null, fn ($q, $id) => $q->where('id', '!=', $id))
            ->with('user:id,name')->latest()->first();

        return response()->json([
            'exists' => (bool) $existing,
            'manager' => $existing?->user?->name,
            'date' => $existing?->created_at?->toDateString(),
            'status' => $existing?->status,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->guardAccess($request);
        $data = $this->validated($request);
        $items = $data['items'] ?? [];
        unset($data['items']);

        $data = PreDeal::calculate($this->applyItems($items, $data));
        $data['user_id'] = $request->user()->id;
        $data['company_id'] = CurrentCompany::id() ?: null;
        $preDeal = PreDeal::create($data);

        if ($items !== []) {
            app(\App\Services\DealItemService::class)->syncPreDeal($preDeal, $items);
        }

        return back()->with('success', 'Заявка добавлена — маржа рассчитана.');
    }

    public function update(Request $request, PreDeal $preDeal): RedirectResponse
    {
        $this->guardOwner($request, $preDeal);
        if ($preDeal->status === 'confirmed') {
            return back()->with('error', 'Заявка уже переведена в сделку — правки только в самой сделке.');
        }
        $data = $this->validated($request, $preDeal);
        $items = $data['items'] ?? null;
        unset($data['items']);

        $preDeal->update(PreDeal::calculate($this->applyItems($items ?? [], $data)));
        if ($items !== null) {
            app(\App\Services\DealItemService::class)->syncPreDeal($preDeal, $items);
        }

        return back()->with('success', 'Пересчитано.');
    }

    public function destroy(Request $request, PreDeal $preDeal): RedirectResponse
    {
        $this->guardOwner($request, $preDeal);
        $preDeal->delete();

        return back()->with('success', 'Заявка удалена.');
    }

    /** Галочка чек-листа («Позвонил клиенту», «Сделал замер»…). */
    public function check(Request $request, PreDeal $preDeal, PreDealChecklistItem $item): RedirectResponse
    {
        $this->guardOwner($request, $preDeal);
        $checks = $preDeal->checks ?? [];
        $checks[(string) $item->id] = ! ($checks[(string) $item->id] ?? false);
        $preDeal->update(['checks' => $checks]);

        return back();
    }

    /** «В работу»: маржа ≥ порога → создаётся настоящая сделка. */
    public function confirm(Request $request, PreDeal $preDeal, DealNumberService $numbers): RedirectResponse
    {
        $this->guardOwner($request, $preDeal);
        if ($preDeal->status === 'confirmed') {
            return back()->with('error', 'Заявка уже переведена в сделку.');
        }
        if ((float) $preDeal->margin < PreDeal::minMargin()) {
            return back()->with('error', 'Маржа '.$preDeal->margin.'% ниже порога '.rtrim(rtrim(number_format(PreDeal::minMargin(), 2, '.', ''), '0'), '.').'% — заявка отклонена.');
        }

        $companyId = $preDeal->company_id ? (int) $preDeal->company_id : null;
        $company = $companyId ? \App\Models\Company::find($companyId) : null;
        $customer = $preDeal->customer ?: $preDeal->product;

        // Двойной клик по «В работу ✓» создавал ДВЕ сделки: обе проверки
        // статуса успевали пройти до того, как первая транзакция его меняла.
        // Блокируем заявку и перечитываем статус под блокировкой — второй
        // запрос дождётся первого и увидит уже переведённую заявку.
        $deal = DB::transaction(function () use ($request, $preDeal, $numbers, $companyId, $company, $customer) {
        $locked = PreDeal::whereKey($preDeal->id)->lockForUpdate()->firstOrFail();
        if ($locked->status === 'confirmed') {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'status' => 'Заявка уже переведена в сделку.',
            ]);
        }

        $deal = Deal::create([
            'number' => $numbers->generate($company),
            'name' => $customer,
            'company_name' => $customer,
            'client_name' => $preDeal->client_name ?: ($preDeal->customer ?: '—'),
            'bin' => $preDeal->bin,
            'budget' => $preDeal->contract_sum,
            // Доля партнёра заявки переносится в сделку (только %, сумма — от суммы договора).
            'partner_pct' => $preDeal->partner_pct !== null && (float) $preDeal->partner_pct > 0
                ? $preDeal->partner_pct : null,
            'status' => 'active',
            'company_id' => $companyId,
            'deal_stage_id' => DealStage::funnel($companyId)->first()?->id,
            'responsible_user_id' => $preDeal->user_id,
            'description' => 'Из заявки: изделие — '.$preDeal->product
                .($preDeal->quantity > 0 ? '; объём '.rtrim(rtrim(number_format((float) $preDeal->quantity, 2, '.', ' '), '0'), '.').' '.($preDeal->unit ?: '') : '')
                .($preDeal->request_number ? '; заявка №'.$preDeal->request_number : '')
                .($preDeal->client_phone ? '; контакт '.($preDeal->client_name ?: '').' '.$preDeal->client_phone : '')
                .'; закуп '.number_format((float) $preDeal->purchase_price, 0, '.', ' ')
                .'; расчётная маржа '.$preDeal->margin.'%',
        ]);
        // Доставка и монтаж из заявки → сразу расходы сделки (🚚/🔧, confirmed),
        // чтобы не вносить их в сделку второй раз. БЕЗ нал/банк (payment_method
        // null): остаток/маржу сделки уменьшают, кассу и банк — нет (деньги
        // физически ещё не потрачены; бухгалтер проставит способ по факту).
        foreach ([['delivery', '🚚 Доставка'], ['assembly', '🔧 Монтаж']] as [$type, $label]) {
            $amount = (float) $preDeal->{$type};
            if ($amount > 0) {
                \App\Models\Expense::create([
                    'company_id' => $companyId,
                    'expenseable_type' => 'deal',
                    'expenseable_id' => $deal->id,
                    'type' => $type,
                    'amount' => $amount,
                    'date' => now()->toDateString(),
                    'description' => 'Из заявки'.($preDeal->request_number ? ' №'.$preDeal->request_number : '').': '.$label,
                    'responsible_user_id' => $preDeal->user_id,
                    'status' => 'confirmed',
                    'confirmed_by' => $request->user()->id,
                    'confirmed_at' => now(),
                ]);
            }
        }

        // Товары заявки становятся позициями сделки: вводить их второй раз
        // менеджеру не нужно.
        app(\App\Services\DealItemService::class)->copyToDeal($locked->load('items'), $deal);

        $locked->update(['status' => 'confirmed', 'deal_id' => $deal->id]);

            return $deal;
        });

        // «В работу» → сразу на страницу Сделки, где появилась новая сделка.
        return redirect()->route('deals.index')->with('success', 'Заказ подтверждён! Сделка '.$deal->number.' создана.');
    }

    // ---- Чек-лист: пункты настраивают админ и финансист ----

    private function guardChecklist(Request $request): void
    {
        abort_unless($request->user()->hasAnyRole(['admin', 'financist']), 403, 'Чек-лист настраивает админ или финансист.');
    }

    public function storeItem(Request $request): RedirectResponse
    {
        $this->guardChecklist($request);
        $data = $request->validate(['label' => ['required', 'string', 'max:255']]);
        PreDealChecklistItem::create(['label' => $data['label'], 'order' => (int) PreDealChecklistItem::max('order') + 1]);

        return back()->with('success', 'Пункт чек-листа добавлен.');
    }

    public function updateItem(Request $request, PreDealChecklistItem $item): RedirectResponse
    {
        $this->guardChecklist($request);
        $data = $request->validate(['label' => ['required', 'string', 'max:255']]);
        $item->update(['label' => $data['label']]);

        return back()->with('success', 'Пункт обновлён.');
    }

    public function destroyItem(Request $request, PreDealChecklistItem $item): RedirectResponse
    {
        $this->guardChecklist($request);
        $item->delete();

        return back()->with('success', 'Пункт удалён.');
    }
}
