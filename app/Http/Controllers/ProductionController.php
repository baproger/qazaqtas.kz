<?php

namespace App\Http\Controllers;

use App\Models\Brigade;
use App\Models\DealItem;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\ProductionBonusService;
use App\Services\ProductionProgressService;
use App\Services\StockService;
use App\Support\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «Производство» — выработка бригад по сменам.
 *
 * Бригадир вводит наряд: кто сколько сделал в штуках и в м². Мастер (админ,
 * директор или начальник производства) подтверждает — только после этого
 * наряд превращается в бонус. Без подтверждения выработку можно было бы
 * приписать себе.
 */
class ProductionController extends Controller
{
    private function canView(Request $request): bool
    {
        return $request->user()->hasAnyRole(['admin', 'director', 'production_head', 'financist', 'foreman', 'assistant']);
    }

    /**
     * Подтверждает директор ИЛИ финансист — достаточно одного.
     *
     * Двойная подпись останавливала бы цех, пока директор в отъезде, а сам
     * бригадир свою выработку не подтверждает ни в каком случае.
     */
    /**
     * Наряд подтверждают директор и начальник производства (плюс админ —
     * он суперпользователь). Финансист право потерял 28.08.2026: смену
     * принимает тот, кто отвечает за цех, а не тот, кто платит.
     *
     * Свою выработку бригадир не подтверждает никогда — иначе вписал бы
     * тысячу вместо пятисот и сам это принял.
     */
    private function canConfirm(Request $request): bool
    {
        return $request->user()->hasAnyRole(['admin', 'director', 'production_head']);
    }

    /**
     * Наряд чужой фирмы не трогаем: выработка превращается в бонус, а бонус —
     * в расход из кассы конкретной фирмы.
     */
    private function assertOwnCompany(Request $request, WorkOrder $order): void
    {
        abort_unless(
            $order->company_id === null || $request->user()->worksInCompany((int) $order->company_id),
            403,
            'Наряд другой фирмы.'
        );
    }

    /** Состав бригад — дело руководства: бригадир себе людей не дописывает. */
    private function canManage(Request $request): bool
    {
        return $request->user()->hasAnyRole(['admin', 'director']);
    }

    public function index(Request $request, ProductionBonusService $bonuses, ProductionProgressService $progress): Response
    {
        abort_unless($this->canView($request), 403, 'Страница производства — для бригадиров и руководства.');

        $month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $request->string('month')->toString())
            ? $request->string('month')->toString()
            : now()->format('Y-m');
        $start = $month.'-01';
        $end = Carbon::parse($start)->endOfMonth()->toDateString();

        $companyId = CurrentCompany::id() ?: null;
        $isForeman = $request->user()->hasRole('foreman')
            && ! $request->user()->hasAnyRole(['admin', 'director', 'production_head']);

        $orders = WorkOrder::query()
            ->when($companyId, fn ($q, $c) => $q->where('company_id', $c))
            // Бригадир видит наряды своих бригад — чужая выработка не его дело.
            ->when($isForeman, fn ($q) => $q->whereIn('brigade_id',
                Brigade::where('foreman_id', $request->user()->id)->select('id')))
            ->whereDate('date', '>=', $start)->whereDate('date', '<=', $end)
            // Кто внёс наряд и кто подтвердил — прямо на карточке: за этими
            // цифрами стоят чужие деньги, и автор должен быть виден без
            // похода в журнал.
            ->with(['brigade:id,name,workshop,foreman_id', 'lines.user:id,name', 'project:id,number',
                'dealItem:id,deal_id,name,unit,quantity', 'dealItem.deal:id,number,company_name',
                'creator:id,name', 'confirmer:id,name'])
            ->orderByDesc('date')->orderByDesc('id')
            ->get()
            ->map(fn (WorkOrder $o) => [
                'id' => $o->id,
                'date' => $o->date?->toDateString(),
                'brigade' => $o->brigade?->name,
                'workshop' => $o->brigade?->workshop,
                'product' => $o->product,
                'project' => $o->project?->number,
                // Какую позицию сделки закрывает наряд. Позицию могли убрать
                // из заказа — наряд остаётся, привязка обнуляется.
                'item' => $o->dealItem ? [
                    'id' => $o->dealItem->id,
                    'name' => $o->dealItem->name,
                    'unit' => $o->dealItem->unit,
                    'deal' => $o->dealItem->deal?->number,
                ] : null,
                'status' => $o->status,
                'note' => $o->note,
                'created_by' => $o->creator?->name,
                'confirmed_by' => $o->confirmer?->name,
                'confirmed_at' => $o->confirmed_at?->format('d.m.Y H:i'),
                'totals' => $bonuses->totals($o),
                'lines' => $o->lines->map(fn ($l) => [
                    'id' => $l->id,
                    'user' => $l->user?->name,
                    'user_id' => $l->user_id,
                    'role' => $l->role,
                    'qty_pcs' => (float) $l->qty_pcs,
                    'qty_m2' => (float) $l->qty_m2,
                    'amount' => (float) $l->amount,
                ])->values(),
            ]);

        // Итог месяца по людям — только ради строки «Начислено за месяц».
        // Таблицей он больше не едет: разбивка по людям живёт в карточке
        // бригады, и держать её в двух местах значит держать две копии одной
        // суммы.
        $byPerson = $orders->flatMap(fn ($o) => $o['status'] === 'confirmed' ? $o['lines'] : [])
            ->groupBy('user')
            ->map(fn ($lines, $name) => [
                'name' => $name ?: '—',
                'pcs' => round((float) $lines->sum('qty_pcs'), 2),
                'm2' => round((float) $lines->sum('qty_m2'), 2),
                'amount' => round((float) $lines->sum('amount'), 2),
            ])->sortByDesc('amount')->values();

        // Позиции сделок, которые сейчас в работе: план из заказа и факт из
        // нарядов. Берём те, что в цехе, и те, по которым уже есть наряды —
        // иначе закрытая позиция пропала бы вместе со своей историей.
        $itemsInWork = DealItem::query()
            ->whereHas('deal', fn ($d) => $d
                ->when($companyId, fn ($q, $c) => $q->where('company_id', $c))
                ->where('status', '!=', 'cancelled')
                ->where(fn ($w) => $w
                    ->whereHas('project', fn ($p) => $p->whereNotIn('status', ['cancelled']))
                    ->orWhereHas('items.workOrders')))
            // Бригадир — только то, над чем работали ЕГО бригады: чужой объём
            // не его дело, а на его странице он выглядел бы как его план.
            ->when($isForeman, fn ($q) => $q->whereHas('workOrders', fn ($w) => $w
                ->whereIn('brigade_id', Brigade::where('foreman_id', $request->user()->id)->select('id'))))
            ->with(['deal:id,number,company_name,foreman_id'])
            ->get(['id', 'deal_id', 'name', 'unit', 'quantity', 'sort']);

        $stats = $progress->forItems($itemsInWork);
        $byBrigade = $progress->byBrigade($itemsInWork->pluck('id')->all());

        $plan = $itemsInWork
            ->map(fn (DealItem $i) => array_merge($stats[$i->id], [
                'id' => $i->id,
                'name' => $i->name,
                'deal' => $i->deal?->number,
                'client' => $i->deal?->company_name,
                'brigades' => array_values($byBrigade[$i->id] ?? []),
            ]))
            // Сначала незакрытые и те, где работа идёт: закрытые уходят вниз.
            ->sortBy(fn ($row) => [$row['left'] <= 0 ? 1 : 0, -$row['done']])
            ->values();

        // Сводка «сколько из сделок взято и сколько проделано» — в м² и в
        // штуках раздельно: складывать метры с штуками нельзя.
        $summary = [];
        foreach (['m2', 'pcs'] as $measure) {
            $rows = $plan->where('measure', $measure);
            $summary[$measure] = [
                'plan' => round((float) $rows->sum('plan'), 2),
                'done' => round((float) $rows->sum('done'), 2),
                'pending' => round((float) $rows->sum('pending'), 2),
                'left' => round((float) $rows->sum('left'), 2),
                'items' => $rows->count(),
            ];
        }

        return Inertia::render('Production/Index', [
            'month' => $month,
            'orders' => $orders,
            'plan' => $plan,
            'planSummary' => $summary,
            // Что можно выбрать в новом наряде: позиции сделок, которые
            // сейчас в цехе. Список шире, чем «план» выше: наряд заводят и по
            // позиции, за которую бригада ещё не бралась.
            'itemOptions' => $this->itemOptions($request, $companyId, $progress),
            'totals' => [
                'pcs' => round((float) $orders->where('status', 'confirmed')->sum(fn ($o) => $o['totals']['pcs']), 2),
                'm2' => round((float) $orders->where('status', 'confirmed')->sum(fn ($o) => $o['totals']['m2']), 2),
                'amount' => round((float) $byPerson->sum('amount'), 2),
                'waiting' => $orders->where('status', 'draft')->count(),
            ],
            // Скрытые бригады видит только руководство — чтобы вернуть их в строй.
            'brigades' => Brigade::query()
                ->when(! $this->canManage($request), fn ($q) => $q->where('is_active', true))
                ->when($companyId, fn ($q, $c) => $q->where(fn ($w) => $w->where('company_id', $c)->orWhereNull('company_id')))
                ->when($isForeman, fn ($q) => $q->where('foreman_id', $request->user()->id))
                ->with(['members:id,name', 'foreman:id,name'])->orderBy('name')->get()
                ->map(fn (Brigade $b) => [
                    'id' => $b->id,
                    'name' => $b->name,
                    'workshop' => $b->workshop,
                    'is_active' => (bool) $b->is_active,
                    'foreman_id' => $b->foreman_id,
                    'foreman' => $b->foreman?->name,
                    'members' => $b->members->map(fn ($m) => ['id' => $m->id, 'name' => $m->name])->values(),
                ]),
            // Ставка одна — бригадира: бонус наряда целиком его, рабочих он
            // делит сам вне системы (правило владельца от 28.08.2026).
            'rates' => ['foreman' => $bonuses->rates('foreman')],
            'canConfirm' => $this->canConfirm($request),
            'canManage' => $this->canManage($request),
            // Кандидаты в бригаду — только руководству: бригадир состав не правит.
            'employees' => $this->canManage($request)
                ? User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])
                    ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])
                : [],
        ]);
    }

    /**
     * Позиции сделок, по которым сейчас можно завести наряд.
     *
     * Это заказы, доехавшие до цеха и оттуда не ушедшие. Цех ограничен теми
     * городами, куда человека пускают (`users.workshops`) — иначе бригадир
     * Шымкента списывал бы объём на заказ Алматы.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function itemOptions(Request $request, ?int $companyId, ProductionProgressService $progress)
    {
        $items = DealItem::query()
            ->whereHas('deal.project', fn ($p) => $p->whereNotIn('status', ['completed', 'cancelled']))
            ->whereHas('deal', fn ($d) => $d
                ->when($companyId, fn ($q, $c) => $q->where('company_id', $c))
                ->where('status', '!=', 'cancelled'))
            ->with(['deal:id,number,company_name', 'deal.project'])
            ->orderBy('deal_id')->orderBy('sort')
            ->get(['id', 'deal_id', 'name', 'unit', 'quantity', 'sort'])
            ->filter(fn (DealItem $i) => $request->user()->worksInWorkshop($i->deal?->project?->workshop))
            ->values();

        $stats = $progress->forItems($items);

        return $items->map(fn (DealItem $i) => array_merge($stats[$i->id], [
            'id' => $i->id,
            'name' => $i->name,
            'deal' => $i->deal?->number,
            'client' => $i->deal?->company_name,
        ]));
    }

    /** Новый наряд: дата, бригада, изделие и строки выработки. */
    public function store(Request $request, ProductionBonusService $bonuses): RedirectResponse
    {
        abort_unless($this->canView($request), 403);

        $data = $request->validate([
            'brigade_id' => ['required', 'exists:brigades,id'],
            'date' => ['required', 'date'],
            'product' => ['nullable', 'string', 'max:255'],
            'project_id' => ['nullable', 'exists:projects,id'],
            'deal_item_id' => ['nullable', 'exists:deal_items,id'],
            'note' => ['nullable', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.user_id' => ['nullable', 'exists:users,id'],
            'lines.*.qty_pcs' => ['nullable', 'numeric', 'min:0'],
            'lines.*.qty_m2' => ['nullable', 'numeric', 'min:0'],
        ], [
            'lines.required' => 'Укажите, кто сколько сделал.',
        ]);

        $brigade = Brigade::findOrFail($data['brigade_id']);
        // Бригадир заводит наряды только своей бригады.
        abort_unless(
            $request->user()->hasAnyRole(['admin', 'director', 'production_head'])
                || $brigade->foreman_id === $request->user()->id,
            403,
            'Наряд заводит бригадир своей бригады.'
        );

        // Позиция сделки, которую закрывает наряд. Чужая фирма недопустима:
        // выработка превращается в бонус, а бонус — в расход из её кассы.
        // Заодно подставляем изделие и заказ цеха: набирать их руками, когда
        // позиция уже выбрана, значит заводить второй источник правды.
        $item = null;
        if (! empty($data['deal_item_id'])) {
            $item = DealItem::with('deal:id,company_id,number')->findOrFail($data['deal_item_id']);
            abort_unless(
                $item->deal?->company_id === null
                    || $request->user()->worksInCompany((int) $item->deal->company_id),
                403,
                'Позиция сделки другой фирмы.'
            );
            $data['product'] = ($data['product'] ?? null) ?: $item->name;
            $data['project_id'] = $data['project_id']
                ?? Project::where('deal_id', $item->deal_id)
                    ->whereNotIn('status', ['cancelled'])->latest('id')->value('id');
        }

        // Анти-дубль: та же бригада, дата и изделие младше минуты — это
        // повторная отправка формы, а не вторая смена. Подтверждённый дубль
        // удвоил бы объём, а с ним и бонус.
        $duplicate = WorkOrder::where('brigade_id', $brigade->id)
            ->whereDate('date', $data['date'])
            ->where('product', $data['product'] ?? null)
            ->where('created_at', '>=', now()->subMinute())
            ->exists();
        if ($duplicate) {
            throw ValidationException::withMessages([
                'lines' => 'Похоже на повторную отправку — такой наряд уже создан минуту назад.',
            ]);
        }

        $order = WorkOrder::create([
            'company_id' => $brigade->company_id ?: CurrentCompany::id(),
            'brigade_id' => $brigade->id,
            'project_id' => $data['project_id'] ?? null,
            'deal_item_id' => $item?->id,
            'date' => $data['date'],
            'product' => $data['product'] ?? null,
            'note' => $data['note'] ?? null,
            'status' => 'draft',
            'created_by' => $request->user()->id,
        ]);

        $bonuses->syncLines($order->load('brigade'), $data['lines']);

        return back()->with('success', 'Наряд создан — ждёт подтверждения мастера.');
    }

    /** Подтверждение наряда: только после него выработка становится бонусом. */
    public function confirm(Request $request, WorkOrder $order, StockService $stock): RedirectResponse
    {
        abort_unless($this->canConfirm($request), 403, 'Наряд подтверждает мастер или руководство.');
        $this->assertOwnCompany($request, $order);

        if ($order->isConfirmed()) {
            return back()->with('error', 'Наряд уже подтверждён.');
        }
        if ($order->status === 'rejected') {
            return back()->with('error', 'Наряд отклонён — сначала бригадир исправляет запись.');
        }

        $order->update([
            'status' => 'confirmed',
            'confirmed_by' => $request->user()->id,
            'confirmed_at' => now(),
        ]);

        // Работа по плану — это производство НА СКЛАД: подтверждённый объём
        // тут же становится остатком. Наряд под позицию сделки прихода не
        // даёт — тот товар делается под конкретный заказ и уже продан.
        $movement = $stock->receiveFromWorkOrder($order->load('plan.product', 'brigade'), $request->user()->id);

        return back()->with('success', $movement
            ? 'Наряд подтверждён — бонус начислен, товар на складе.'
            : 'Наряд подтверждён — выработка пошла в бонус.');
    }

    /**
     * Отклонить наряд с причиной: объём не сходится, нет фото партии.
     *
     * Наряд не удаляем — бригадир должен видеть, что именно исправить, и
     * поправить ту же запись, а не заводить новую.
     */
    public function reject(Request $request, WorkOrder $order): RedirectResponse
    {
        abort_unless($this->canConfirm($request), 403, 'Наряд отклоняет мастер или руководство.');
        $this->assertOwnCompany($request, $order);

        if ($order->isConfirmed()) {
            return back()->with('error', 'Наряд уже подтверждён — отклонить его нельзя.');
        }

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ], ['reason.required' => 'Напишите, что исправить.']);

        $order->update(['status' => 'rejected', 'reject_reason' => $data['reason']]);

        return back()->with('success', 'Наряд отклонён — бригадир увидит причину.');
    }

    /**
     * Удаление наряда. Подтверждённый убирает только руководство: он уже
     * посчитан в бонусах, и его исчезновение меняет чужие деньги.
     */
    public function destroy(Request $request, WorkOrder $order, StockService $stock): RedirectResponse
    {
        $this->assertOwnCompany($request, $order);
        $mine = $order->brigade?->foreman_id === $request->user()->id;
        abort_unless(
            $this->canConfirm($request) || ($mine && ! $order->isConfirmed()),
            403,
            'Подтверждённый наряд убирает только руководство.'
        );

        // Подтверждённый наряд уже положил товар на склад. Приход не стираем
        // — он был, и его видели; пишем обратное движение, чтобы остаток
        // сошёлся, а история осталась читаемой.
        $reversed = $order->isConfirmed()
            ? $stock->reverseWorkOrder($order->load('plan.product'), $request->user()->id)
            : null;

        $order->delete();

        return back()->with('success', $reversed
            ? 'Наряд удалён — приход на складе сторнирован.'
            : 'Наряд удалён.');
    }

    /** Создать бригаду: имя, цех, бригадир и состав. */
    public function storeBrigade(Request $request): RedirectResponse
    {
        abort_unless($this->canManage($request), 403, 'Бригады заводит руководство.');

        $data = $this->brigadeData($request);
        $brigade = Brigade::create([
            'company_id' => CurrentCompany::id() ?: null,
            'name' => $data['name'],
            'workshop' => $data['workshop'] ?? null,
            'foreman_id' => $data['foreman_id'] ?? null,
            'is_active' => true,
        ]);
        $brigade->members()->sync($data['members'] ?? []);

        return back()->with('success', 'Бригада создана.');
    }

    /** Изменить бригаду: состав меняется, уже созданные наряды — нет. */
    public function updateBrigade(Request $request, Brigade $brigade): RedirectResponse
    {
        abort_unless($this->canManage($request), 403, 'Бригады правит руководство.');

        $data = $this->brigadeData($request);
        $brigade->update([
            'name' => $data['name'],
            'workshop' => $data['workshop'] ?? null,
            'foreman_id' => $data['foreman_id'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);
        $brigade->members()->sync($data['members'] ?? []);

        return back()->with('success', 'Бригада обновлена.');
    }

    /**
     * Убрать бригаду. С нарядами не удаляем, а прячем: наряды — это уже
     * начисленные деньги, и терять их вместе с бригадой нельзя.
     */
    public function destroyBrigade(Request $request, Brigade $brigade): RedirectResponse
    {
        abort_unless($this->canManage($request), 403, 'Бригады убирает руководство.');

        if ($brigade->orders()->exists()) {
            $brigade->update(['is_active' => false]);

            return back()->with('success', 'Бригада скрыта — её наряды сохранены.');
        }

        $brigade->members()->detach();
        $brigade->delete();

        return back()->with('success', 'Бригада удалена.');
    }

    /** @return array<string, mixed> */
    private function brigadeData(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'workshop' => ['nullable', 'string', 'max:255'],
            'foreman_id' => ['nullable', 'exists:users,id'],
            'is_active' => ['nullable', 'boolean'],
            'members' => ['nullable', 'array'],
            'members.*' => ['exists:users,id'],
        ]);
    }
}
