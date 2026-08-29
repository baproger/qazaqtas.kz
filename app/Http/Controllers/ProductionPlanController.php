<?php

namespace App\Http\Controllers;

use App\Models\Brigade;
use App\Models\Product;
use App\Models\ProductionPlan;
use App\Models\WorkOrder;
use App\Models\WorkOrderLine;
use App\Services\ProductionBonusService;
use App\Services\ProductionProgressService;
use App\Support\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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
    /**
     * План ставит руководство производства: директор и начальник цеха.
     * Бригадир и финансист план не ставят — это задание, а не отчёт.
     */
    private function canPlan(Request $request): bool
    {
        return $request->user()->hasAnyRole(['admin', 'director', 'production_head']);
    }

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

    private function isForeman(Request $request): bool
    {
        return $request->user()->hasRole('foreman')
            && ! $request->user()->hasAnyRole(['admin', 'director', 'production_head']);
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
            $request->user()->hasAnyRole(['admin', 'director', 'production_head', 'financist', 'foreman', 'assistant'])
            && $request->user()->can('project.viewAny'),
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
            // Нераспределённые идут отдельной очередью ниже: в общем списке
            // они не имеют бригады, а блок строится вокруг неё.
            ->whereNotNull('brigade_id')
            // Бригадир видит план СВОИХ бригад: чужое задание не его дело.
            ->when($isForeman, fn ($q) => $q->whereIn('brigade_id',
                Brigade::where('foreman_id', $request->user()->id)->select('id')))
            ->with(['brigade:id,name,workshop,foreman_id', 'brigade.foreman:id,name', 'product:id,name,unit'])
            ->orderBy('brigade_id')->orderBy('id')
            ->get();

        $stats = $progress->forPlans($plans);
        $rates = app(ProductionBonusService::class)->rates('foreman');
        $accrued = $this->accruedByPlan($plans);

        $rows = $plans->map(function (ProductionPlan $plan) use ($stats, $rates, $accrued) {
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
                // Начислено по замороженным строкам; плана ещё не касались —
                // показываем прикидку по текущей ставке.
                'bonus' => $accrued[$plan->id] ?? round($stat['done'] * $rate, 2),
                'status' => $plan->status,
                // План выполнен, когда сделано не меньше задания ИЛИ его
                // закрыли руками. Признак считает сервер: посчитай его в
                // браузере — и «выполнено» на странице разошлось бы с
                // «выполнено» в подытоге.
                'done' => $stat['percent'] >= 100 || $plan->status === 'closed',
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
                // Итог месяца и подытоги блоков считает ОДНА функция: два
                // расчёта «покороче» однажды разойдутся, и страница начнёт
                // спорить сама с собой.
                'measures' => $this->totalsFor($rows),
                'bonus' => round((float) $rows->sum('bonus'), 2),
                'waiting' => $orders->where('status', 'draft')->count(),
                // Подытоги блоков: сумма «в работе» и «выполнено» обязана
                // сойтись с итогом выше — это видно глазами, а не на веру.
                'active' => [
                    'measures' => $this->totalsFor($rows->where('done', false)),
                    'bonus' => round((float) $rows->where('done', false)->sum('bonus'), 2),
                    'count' => $rows->where('done', false)->count(),
                ],
                'done' => [
                    'measures' => $this->totalsFor($rows->where('done', true)),
                    'bonus' => round((float) $rows->where('done', true)->sum('bonus'), 2),
                    'count' => $rows->where('done', true)->count(),
                ],
            ],
            'brigadeOutput' => $this->brigadeOutput($month, $companyId, $isForeman ? $request->user()->id : null),
            // Очередь: пришло из сделок, бригада ещё не назначена. Бригадиру
            // не показываем — это работа начальника производства, а не его.
            'queue' => $isForeman ? [] : $this->queueFor($month, $companyId),
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
     * Очередь: объём пришёл из сделки, бригаду ещё не назначили.
     *
     * Пока строка здесь, цех о ней знает, но никто за неё не отвечает —
     * поэтому очередь стоит ПЕРВОЙ на странице и подсвечена. Назначил
     * бригаду — строка уезжает в «В работе» и начинает считаться.
     *
     * @return array<int, array<string, mixed>>
     */
    private function queueFor(string $month, ?int $companyId): array
    {
        return ProductionPlan::query()
            ->whereNull('brigade_id')
            ->whereDate('period_month', $month.'-01')
            ->when($companyId, fn ($q, $c) => $q->where(fn ($w) => $w->where('company_id', $c)->orWhereNull('company_id')))
            ->with(['product:id,name,unit', 'deal:id,number,company_name,deadline'])
            ->orderBy('id')
            ->get()
            ->map(fn (ProductionPlan $p) => [
                'id' => $p->id,
                'product' => $p->product?->name,
                'qty' => (float) $p->plan_qty,
                'unit' => $p->unit ?: $p->product?->unit,
                'deal' => $p->deal ? [
                    'id' => $p->deal->id,
                    'number' => $p->deal->number,
                    'client' => $p->deal->company_name,
                    'deadline' => $p->deal->deadline?->toDateString(),
                ] : null,
            ])->values()->all();
    }

    /**
     * Назначить бригаду плану из очереди.
     *
     * Если у этой бригады уже есть план на тот же товар в этом месяце,
     * СКЛАДЫВАЕМ объёмы и очередную строку убираем: два задания на один товар
     * одной бригаде — это одно задание, а уникальный ключ их и не пустит.
     */
    public function assign(Request $request, ProductionPlan $plan): RedirectResponse
    {
        abort_unless($this->canPlan($request), 403, 'Бригаду назначает начальник производства.');
        abort_unless($plan->brigade_id === null, 422, 'У плана уже есть бригада.');

        $data = $request->validate(['brigade_id' => ['required', 'exists:brigades,id']]);

        DB::transaction(function () use ($plan, $data) {
            $existing = ProductionPlan::whereDate('period_month', $plan->period_month)
                ->where('product_id', $plan->product_id)
                ->where('brigade_id', $data['brigade_id'])
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $existing->increment('plan_qty', (float) $plan->plan_qty);
                $plan->delete();

                return;
            }

            $plan->update(['brigade_id' => $data['brigade_id']]);
        });

        return back()->with('success', 'Бригада назначена — план в работе.');
    }

    /**
     * Сколько по этим планам РЕАЛЬНО начислено.
     *
     * Берём суммы строк подтверждённых нарядов, а не «сделано × ставка»:
     * ставка замораживается в строке (§4), и после её правки расчёт по
     * текущей показывал бы бонус, которого никто не получит — ведомость
     * платит по замороженным строкам. Владелец поднял цену со 100 до 300 —
     * и прошлая смена «дорожала» на экране втрое.
     *
     * @param  Collection<int, ProductionPlan>  $plans
     * @return array<int, float> id плана → начислено
     */
    private function accruedByPlan(Collection $plans): array
    {
        if ($plans->isEmpty()) {
            return [];
        }

        return WorkOrderLine::query()
            ->join('work_orders', 'work_orders.id', '=', 'work_order_lines.work_order_id')
            ->whereIn('work_orders.production_plan_id', $plans->pluck('id'))
            ->where('work_orders.status', 'confirmed')
            ->groupBy('work_orders.production_plan_id')
            ->selectRaw('work_orders.production_plan_id as pid, sum(work_order_lines.amount) as total')
            ->pluck('total', 'pid')
            ->map(fn ($v) => round((float) $v, 2))
            ->all();
    }

    /**
     * Итоги по метрикам для набора планов.
     *
     * Одна функция и на месяц, и на подытог блока: посчитай подытог отдельно
     * «покороче», и сумма блоков перестанет сходиться с итогом сверху.
     *
     * Метры и штуки в одно число не складываются: «план 2100» из 1000 м²
     * плитки и 1100 штук вазонов не значит ничего.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function totalsFor(Collection $rows): array
    {
        return collect(['m2', 'pcs'])
            ->map(fn ($measure) => [
                'measure' => $measure,
                'plan' => round((float) $rows->where('measure', $measure)->sum('plan'), 2),
                'done' => round((float) $rows->where('measure', $measure)->sum('done'), 2),
                'pending' => round((float) $rows->where('measure', $measure)->sum('pending'), 2),
                'items' => $rows->where('measure', $measure)->count(),
            ])
            ->filter(fn ($row) => $row['items'] > 0)
            ->values()->all();
    }

    /**
     * Выработка бригад за месяц: сколько каждая сделала и на сколько.
     *
     * Берём ВСЕ подтверждённые наряды месяца, а не только по планам: бригада
     * работает и под заказ клиента, и месяц без планов выглядел бы пустым.
     * Но колонки разделены — «по плану» и «под заказ», — иначе цифра тут
     * спорила бы с бонусом в блоках планов выше, и понять, какая верная,
     * было бы нечем. Столбец «по плану» обязан сойтись с их подытогами.
     *
     * Неподтверждённое в выработку не идёт: пока мастер не принял, это ещё
     * не факт и не деньги (§4 «Производство»).
     *
     * @return array<int, array<string, mixed>>
     */
    private function brigadeOutput(string $month, ?int $companyId, ?int $foremanId): array
    {
        $start = $month.'-01';
        $end = Carbon::parse($start)->endOfMonth()->toDateString();

        $orders = WorkOrder::query()
            ->where('status', 'confirmed')
            ->whereDate('date', '>=', $start)->whereDate('date', '<=', $end)
            ->when($companyId, fn ($q, $c) => $q->where(fn ($w) => $w->where('company_id', $c)->orWhereNull('company_id')))
            // Бригадир видит выработку СВОИХ бригад: чужая — не его дело.
            ->when($foremanId, fn ($q, $id) => $q->whereIn('brigade_id',
                Brigade::where('foreman_id', $id)->select('id')))
            ->with(['brigade:id,name,workshop', 'lines'])
            ->get();

        return $orders->groupBy('brigade_id')
            ->map(function ($group) {
                $lines = $group->flatMap->lines;
                $byPlan = $group->whereNotNull('production_plan_id')->flatMap->lines;

                return [
                    'id' => $group->first()->brigade_id,
                    'name' => $group->first()->brigade?->name ?? '—',
                    'workshop' => $group->first()->brigade?->workshop,
                    'shifts' => $group->count(),
                    'm2' => round((float) $lines->sum('qty_m2'), 2),
                    'pcs' => round((float) $lines->sum('qty_pcs'), 2),
                    'amount' => round((float) $lines->sum('amount'), 2),
                    // Из них по плану — эта колонка сходится с блоками выше.
                    'plan_amount' => round((float) $byPlan->sum('amount'), 2),
                ];
            })
            ->sortByDesc('amount')->values()->all();
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
            $request->user()->hasAnyRole(['admin', 'director', 'production_head'])
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
        $accrued = $this->accruedByPlan($plans);

        // Все наряды месяца — и по плану, и под заказ клиента: бригада одна,
        // и её месяц должен быть виден целиком.
        $orders = WorkOrder::where('brigade_id', $brigade->id)
            ->whereDate('date', '>=', $month.'-01')
            ->whereDate('date', '<=', Carbon::parse($month.'-01')->endOfMonth()->toDateString())
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
            'plans' => $plans->map(function (ProductionPlan $plan) use ($stats, $rates, $accrued) {
                $stat = $stats[$plan->id];
                $rate = $plan->bonus_rate !== null ? (float) $plan->bonus_rate : $rates[$stat['measure']];

                return array_merge($stat, [
                    'id' => $plan->id,
                    'product' => $plan->product?->name,
                    'status' => $plan->status,
                    'rate' => round($rate, 2),
                    // Как и на «План — факт»: начислено по ЗАМОРОЖЕННЫМ строкам.
                    // Две страницы про один план обязаны звать одно число.
                    'bonus' => $accrued[$plan->id] ?? round($stat['done'] * $rate, 2),
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
