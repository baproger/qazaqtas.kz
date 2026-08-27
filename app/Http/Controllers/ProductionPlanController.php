<?php

namespace App\Http\Controllers;

use App\Models\Brigade;
use App\Models\Product;
use App\Models\ProductionPlan;
use App\Models\WorkOrder;
use App\Services\ProductionBonusService;
use App\Services\ProductionProgressService;
use App\Support\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «План — факт»: задание цеху на месяц и его выполнение.
 *
 * План ставит директор (или админ) — он решает, что и в каком объёме делать.
 * Бригадир видит свой план и отмечает выработку; подтверждает директор или
 * финансист, и только после этого объём становится бонусом.
 *
 * Выполнение считается по нарядам, привязанным к плану. Второго счётчика нет:
 * заведи его, и «выполнено» на этой странице разошлось бы с нарядами.
 */
class ProductionPlanController extends Controller
{
    /** План ставит только директор и админ: это задание, а не отчёт. */
    private function canPlan(Request $request): bool
    {
        return $request->user()->hasAnyRole(['admin', 'director']);
    }

    /** Подтверждает директор ИЛИ финансист — достаточно одного. */
    private function canConfirm(Request $request): bool
    {
        return $request->user()->hasAnyRole(['admin', 'director', 'financist']);
    }

    private function isForeman(Request $request): bool
    {
        return $request->user()->hasRole('foreman')
            && ! $request->user()->hasAnyRole(['admin', 'director', 'financist']);
    }

    /** План другой фирмы не трогаем: выполнение превращается в её деньги. */
    private function assertOwnCompany(Request $request, ProductionPlan $plan): void
    {
        abort_unless(
            $plan->company_id === null || $request->user()->worksInCompany((int) $plan->company_id),
            403,
            'План другой фирмы.'
        );
    }

    public function index(Request $request, ProductionProgressService $progress): Response
    {
        abort_unless(
            $request->user()->hasAnyRole(['admin', 'director', 'financist', 'foreman']),
            403,
            'Страница плана — для бригадиров и руководства.'
        );

        $month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $request->string('month')->toString())
            ? $request->string('month')->toString()
            : now()->format('Y-m');

        $companyId = CurrentCompany::id() ?: null;
        $isForeman = $this->isForeman($request);

        $plans = ProductionPlan::query()
            ->when($companyId, fn ($q, $c) => $q->where(fn ($w) => $w->where('company_id', $c)->orWhereNull('company_id')))
            ->whereDate('period_month', $month.'-01')
            // Бригадир видит план СВОИХ бригад: чужое задание не его дело.
            ->when($isForeman, fn ($q) => $q->whereIn('brigade_id',
                Brigade::where('foreman_id', $request->user()->id)->select('id')))
            ->with(['brigade:id,name,workshop,foreman_id', 'brigade.foreman:id,name', 'product:id,name,unit'])
            ->orderBy('brigade_id')->orderBy('id')
            ->get();

        $stats = $progress->forPlans($plans);
        $rates = app(ProductionBonusService::class)->rates('foreman');

        $rows = $plans->map(function (ProductionPlan $plan) use ($stats, $rates) {
            $stat = $stats[$plan->id];
            // Бонус бригадира за выполненное: ставка плана, а нет её — общая.
            $rate = $plan->bonus_rate !== null ? (float) $plan->bonus_rate : $rates[$stat['measure']];

            return array_merge($stat, [
                'id' => $plan->id,
                'brigade' => $plan->brigade?->name,
                'brigade_id' => $plan->brigade_id,
                'foreman' => $plan->brigade?->foreman?->name,
                'workshop' => $plan->brigade?->workshop,
                'product' => $plan->product?->name,
                'product_id' => $plan->product_id,
                'rate' => round($rate, 2),
                'bonus' => round($stat['done'] * $rate, 2),
                'status' => $plan->status,
                'note' => $plan->note,
                'editable' => $plan->isEditable(),
            ]);
        })->values();

        // Наряды по планам этого месяца — лента «что ждёт подтверждения».
        $orders = WorkOrder::query()
            ->whereIn('production_plan_id', $plans->pluck('id'))
            ->with(['brigade:id,name', 'lines', 'creator:id,name', 'confirmer:id,name', 'plan:id,product_id', 'plan.product:id,name,unit'])
            ->orderByDesc('date')->orderByDesc('id')
            ->get()
            ->map(fn (WorkOrder $o) => [
                'id' => $o->id,
                'plan_id' => $o->production_plan_id,
                'date' => $o->date?->toDateString(),
                'brigade' => $o->brigade?->name,
                'product' => $o->plan?->product?->name,
                'unit' => $o->plan?->product?->unit,
                'status' => $o->status,
                'reject_reason' => $o->reject_reason,
                'qty' => round((float) $o->lines->where('role', 'worker')->sum('qty_m2')
                    + (float) $o->lines->where('role', 'worker')->sum('qty_pcs'), 2),
                'amount' => round((float) $o->lines->sum('amount'), 2),
                'created_by' => $o->creator?->name,
                'confirmed_by' => $o->confirmer?->name,
            ]);

        return Inertia::render('Production/Plans', [
            'month' => $month,
            'plans' => $rows,
            'orders' => $orders,
            // Метры и штуки в одно число не складываются: «план 2100» из
            // 1000 м² плитки и 1100 штук вазонов не значит ничего. Деньги —
            // складываются, они одни на всё.
            'summary' => [
                'measures' => collect(['m2', 'pcs'])
                    ->map(fn ($measure) => [
                        'measure' => $measure,
                        'plan' => round((float) $rows->where('measure', $measure)->sum('plan'), 2),
                        'done' => round((float) $rows->where('measure', $measure)->sum('done'), 2),
                        'pending' => round((float) $rows->where('measure', $measure)->sum('pending'), 2),
                        'items' => $rows->where('measure', $measure)->count(),
                    ])
                    ->filter(fn ($row) => $row['items'] > 0)->values(),
                'bonus' => round((float) $rows->sum('bonus'), 2),
                'waiting' => $orders->where('status', 'draft')->count(),
            ],
            'canPlan' => $this->canPlan($request),
            'canConfirm' => $this->canConfirm($request),
            'isForeman' => $isForeman,
            'brigades' => $this->canPlan($request)
                ? Brigade::where('is_active', true)
                    ->when($companyId, fn ($q, $c) => $q->where(fn ($w) => $w->where('company_id', $c)->orWhereNull('company_id')))
                    ->orderBy('name')->get(['id', 'name', 'workshop'])
                : [],
            // Каталог для выбора товара — тот же источник, что в сделке и в
            // заявке: разойдись они, один и тот же товар назывался бы в трёх
            // местах по-разному.
            'products' => $this->canPlan($request) ? Product::catalogForPicker() : [],
            'productCategories' => $this->canPlan($request) ? Product::pickerCategories() : [],
        ]);
    }

    /**
     * Карточка бригады: состав, планы, наряды, начисления.
     *
     * На общей странице бригада — одна строка; здесь всё, что о ней известно.
     * Бригадир открывает только свою: чужая выработка не его дело.
     */
    public function brigade(Request $request, Brigade $brigade, ProductionProgressService $progress): Response
    {
        abort_unless(
            $request->user()->hasAnyRole(['admin', 'director', 'financist'])
                || $brigade->foreman_id === $request->user()->id,
            403,
            'Это чужая бригада.'
        );
        abort_unless(
            $brigade->company_id === null || $request->user()->worksInCompany((int) $brigade->company_id),
            403,
            'Бригада другой фирмы.'
        );

        $month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $request->string('month')->toString())
            ? $request->string('month')->toString()
            : now()->format('Y-m');

        $plans = ProductionPlan::where('brigade_id', $brigade->id)
            ->whereDate('period_month', $month.'-01')
            ->with('product:id,name,unit')->orderBy('id')->get();

        $stats = $progress->forPlans($plans);
        $rates = app(ProductionBonusService::class)->rates('foreman');

        // Все наряды месяца — и по плану, и под заказ клиента: бригада одна,
        // и её месяц должен быть виден целиком.
        $orders = WorkOrder::where('brigade_id', $brigade->id)
            ->whereDate('date', '>=', $month.'-01')
            ->whereDate('date', '<=', \Illuminate\Support\Carbon::parse($month.'-01')->endOfMonth()->toDateString())
            ->with(['lines.user:id,name', 'plan.product:id,name,unit', 'dealItem:id,deal_id,name,unit', 'dealItem.deal:id,number',
                'creator:id,name', 'confirmer:id,name'])
            ->orderByDesc('date')->orderByDesc('id')->get();

        $bonuses = app(ProductionBonusService::class);

        return Inertia::render('Production/Brigade', [
            'month' => $month,
            'brigade' => [
                'id' => $brigade->id,
                'name' => $brigade->name,
                'workshop' => $brigade->workshop,
                'is_active' => (bool) $brigade->is_active,
                'foreman' => $brigade->foreman?->name,
                'members' => $brigade->members->map(fn ($m) => ['id' => $m->id, 'name' => $m->name])->values(),
            ],
            'plans' => $plans->map(function (ProductionPlan $plan) use ($stats, $rates) {
                $stat = $stats[$plan->id];
                $rate = $plan->bonus_rate !== null ? (float) $plan->bonus_rate : $rates[$stat['measure']];

                return array_merge($stat, [
                    'id' => $plan->id,
                    'product' => $plan->product?->name,
                    'status' => $plan->status,
                    'rate' => round($rate, 2),
                    'bonus' => round($stat['done'] * $rate, 2),
                ]);
            })->values(),
            'orders' => $orders->map(fn (WorkOrder $o) => [
                'id' => $o->id,
                'date' => $o->date?->toDateString(),
                'status' => $o->status,
                'reject_reason' => $o->reject_reason,
                'source' => $o->plan
                    ? ['kind' => 'plan', 'name' => $o->plan->product?->name, 'unit' => $o->plan->unit]
                    : ($o->dealItem
                        ? ['kind' => 'deal', 'name' => $o->dealItem->name, 'unit' => $o->dealItem->unit, 'deal' => $o->dealItem->deal?->number]
                        : ['kind' => 'free', 'name' => $o->product, 'unit' => null]),
                'totals' => $bonuses->totals($o),
                'lines' => $o->lines->map(fn ($l) => [
                    'id' => $l->id, 'user' => $l->user?->name, 'role' => $l->role,
                    'qty_pcs' => (float) $l->qty_pcs, 'qty_m2' => (float) $l->qty_m2,
                    'amount' => (float) $l->amount,
                ])->values(),
                'created_by' => $o->creator?->name,
                'confirmed_by' => $o->confirmer?->name,
            ])->values(),
            // Кто сколько заработал в этой бригаде за месяц — по подтверждённым.
            'byPerson' => $orders->where('status', 'confirmed')
                ->flatMap(fn (WorkOrder $o) => $o->lines)
                ->groupBy('user_id')
                ->map(fn ($lines) => [
                    'name' => $lines->first()->user?->name ?: '—',
                    'role' => $lines->first()->role,
                    'm2' => round((float) $lines->sum('qty_m2'), 2),
                    'pcs' => round((float) $lines->sum('qty_pcs'), 2),
                    'amount' => round((float) $lines->sum('amount'), 2),
                ])->sortByDesc('amount')->values(),
            'canConfirm' => $this->canConfirm($request),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($this->canPlan($request), 403, 'План ставит директор или админ.');

        $data = $this->validated($request);
        $brigade = Brigade::findOrFail($data['brigade_id']);
        $product = Product::findOrFail($data['product_id']);

        // Дубль плана удвоил бы и задание, и процент выполнения.
        $exists = ProductionPlan::whereDate('period_month', $data['period_month'].'-01')
            ->where('brigade_id', $brigade->id)->where('product_id', $product->id)->exists();
        if ($exists) {
            throw ValidationException::withMessages([
                'product_id' => 'На этот месяц у бригады уже есть план по этому товару — исправьте его.',
            ]);
        }

        ProductionPlan::create([
            'company_id' => $brigade->company_id ?: CurrentCompany::id(),
            'period_month' => $data['period_month'].'-01',
            'brigade_id' => $brigade->id,
            'product_id' => $product->id,
            'plan_qty' => $data['plan_qty'],
            // Единица — снимок каталога: товар переименуют, а план останется.
            'unit' => $product->unit,
            'bonus_rate' => $data['bonus_rate'] ?? null,
            'note' => $data['note'] ?? null,
            'status' => 'active',
            'created_by' => $request->user()->id,
        ]);

        return back()->with('success', 'План поставлен — бригадир увидит его у себя.');
    }

    public function update(Request $request, ProductionPlan $plan): RedirectResponse
    {
        abort_unless($this->canPlan($request), 403, 'План правит директор или админ.');
        $this->assertOwnCompany($request, $plan);

        // После подтверждённой выработки задание уже стало деньгами: сменишь
        // объём — и процент, по которому платили, поедет задним числом.
        abort_unless($plan->isEditable(), 422,
            'По этому плану уже есть подтверждённая выработка — объём не меняем.');

        $data = $request->validate([
            'plan_qty' => ['required', 'numeric', 'min:0'],
            'bonus_rate' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(['active', 'closed'])],
        ]);

        $plan->update(array_filter($data, fn ($v) => $v !== null) + ['bonus_rate' => $data['bonus_rate'] ?? null]);

        return back()->with('success', 'План изменён.');
    }

    public function destroy(Request $request, ProductionPlan $plan): RedirectResponse
    {
        abort_unless($this->canPlan($request), 403, 'План убирает директор или админ.');
        $this->assertOwnCompany($request, $plan);
        abort_unless($plan->isEditable(), 422,
            'По этому плану есть подтверждённая выработка — план не удаляем, закройте его.');

        $plan->delete();

        return back()->with('success', 'План убран.');
    }

    /**
     * Бригадир отмечает выработку по своему плану.
     *
     * Запись становится обычным сменным нарядом: она попадёт в «Наряды по
     * сменам» и ждёт подтверждения. Второй сущности «факт» нет — иначе
     * выработка на этой странице и в нарядах считалась бы по-разному.
     */
    public function output(Request $request, ProductionPlan $plan,
        ProductionBonusService $bonuses, ProductionProgressService $progress): RedirectResponse
    {
        $this->assertOwnCompany($request, $plan);
        abort_unless(
            $this->canPlan($request) || $plan->brigade?->foreman_id === $request->user()->id,
            403,
            'Выработку по плану отмечает бригадир этой бригады.'
        );
        abort_unless($plan->status === 'active', 422, 'План закрыт — выработку по нему не принимаем.');

        $data = $request->validate([
            'qty' => ['required', 'numeric', 'min:0.01'],
            'date' => ['nullable', 'date'],
        ], ['qty.required' => 'Укажите, сколько сделано.']);

        $qty = round((float) $data['qty'], 2);
        $measure = $progress->measure($plan->unit ?: $plan->product?->unit);

        $order = WorkOrder::create([
            'company_id' => $plan->company_id,
            'brigade_id' => $plan->brigade_id,
            'production_plan_id' => $plan->id,
            'date' => $data['date'] ?? now()->toDateString(),
            'product' => $plan->product?->name,
            'status' => 'draft',
            'created_by' => $request->user()->id,
        ]);

        // Объём делим поровну между рабочими бригады; остаток от деления — в
        // первую строку, чтобы сумма сошлась с введённым числом. Некому
        // делить — пишем на бригадира.
        $members = $plan->brigade->members()->pluck('users.id');
        $targets = $members->isNotEmpty() ? $members : collect([$plan->brigade->foreman_id])->filter();
        $share = $targets->isNotEmpty() ? round($qty / $targets->count(), 2) : 0;

        $rows = $targets->values()->map(fn ($id, $i) => [
            'user_id' => $id,
            'qty_'.$measure => $i === 0 ? round($qty - $share * ($targets->count() - 1), 2) : $share,
        ])->all();

        $bonuses->syncLines($order->load('brigade', 'plan.product'), $rows);

        return back()->with('success', 'Записано '.$qty.' '.($plan->unit ?: '').' — ждёт подтверждения.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'period_month' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'brigade_id' => ['required', 'exists:brigades,id'],
            'product_id' => ['required', 'exists:products,id'],
            'plan_qty' => ['required', 'numeric', 'min:0.01'],
            'bonus_rate' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:255'],
        ], [
            'plan_qty.required' => 'Укажите объём плана.',
            'period_month.regex' => 'Месяц плана в формате ГГГГ-ММ.',
        ]);
    }
}
