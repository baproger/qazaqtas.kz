<?php

namespace App\Http\Controllers;

use App\Http\Requests\DealRequest;
use App\Models\AuditLog;
use App\Models\Chat;
use App\Models\Client;
use App\Models\Company;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\DealStageLog;
use App\Models\Department;
use App\Models\Material;
use App\Models\Product;
use App\Models\Project;
use App\Models\ProjectStage;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\ProductShortage;
use App\Services\CustomFieldService;
use App\Services\DealItemService;
use App\Services\DealNumberService;
use App\Services\FinanceService;
use App\Services\PayrollService;
use App\Services\ProductionPlanService;
use App\Services\ProductionProgressService;
use App\Services\StockService;
use App\Support\AccessScope;
use App\Support\AuditFormatter;
use App\Support\CurrentCompany;
use App\Support\RoleTraits;
use App\Support\StickyFilters;
use Database\Seeders\StageSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class DealController extends Controller
{
    use Concerns\DealGuards;

    /** Вкладка «Без филиала»: значение фильтра, которого нет среди площадок. */
    public const NO_BRANCH = '__none';

    /**
     * Видит ли этот человек деньги сделки.
     *
     * Бригадир ведёт работу в цехе и должен зайти внутрь сделки: адрес,
     * товары, сроки, задачи, этапы. Суммы — не его: ни договор, ни расходы,
     * ни бонус. Проверка одна на список и на карточку — разойдись они, сумма
     * утекла бы через ту страницу, где забыли.
     */
    private function seesMoney(User $user): bool
    {
        return RoleTraits::seesMoney($user);
    }

    /**
     * Видимость сделок в списках.
     *
     * Ширину задаёт ОБЛАСТЬ ДОСТУПА роли (Настройки → Права доступа): свои /
     * отдел / отдел и подчинённые / все. Не настроена — руководство видит всё,
     * остальные свои, ровно как было до появления областей.
     *
     * Два случая областью не описываются и остаются отдельно:
     *
     * - технолог и снабженец видят сделки СВОЕГО ГЕЙТ-ЭТАПА, а не своего
     *   отдела: они подключаются к чужим сделкам на одном шаге воронки;
     * - бригадир видит те, на которые его назначил директор, — это личное
     *   назначение, а не принадлежность к отделу.
     *
     * Прямые ссылки (из уведомлений/задач) шире — их решает DealPolicy.
     */
    private function scopeForViewer($query, User $user): void
    {
        $gateTypes = [];
        if ($user->hasRole('designer')) {
            $gateTypes[] = 'design';
        }
        if ($user->hasRole('supplier')) {
            $gateTypes[] = 'shop_gate';
        }
        if ($gateTypes) {
            $query->whereHas('stage', fn ($s) => $s->whereIn('stage_type', $gateTypes));

            return;
        }

        // Бригадир — только сделки, на которые его назначил директор.
        if ($user->hasRole('foreman')) {
            $query->where('foreman_id', $user->id);

            return;
        }

        AccessScope::apply($query, $user, 'deal.viewAny');
    }

    public function index(Request $request): Response
    {
        // Фильтр переживает уход со страницы: пришли без параметров —
        // подставляем сохранённый набор (App\Support\StickyFilters).
        StickyFilters::apply($request, 'deals', ['search', 'responsible', 'stage', 'branch', 'date_from', 'date_to', 'contract_from', 'contract_to']);

        $this->authorize('viewAny', Deal::class);

        $view = $request->string('view', 'kanban')->toString();

        $base = $this->filteredDeals($request->all(), $request->user())
            ->select('deals.*')
            // ⏱ на канбане: когда сделка вошла на текущий этап (открытый лог).
            ->addSelect(['stage_entered_at' => DealStageLog::select('entered_at')
                ->whereColumn('deal_id', 'deals.id')->whereNull('left_at')
                ->latest('entered_at')->limit(1)])
            // items — товары сделки: на карточке видно, что именно продали.
            // Без них сделка с пятью позициями выглядела на доске пустой.
            ->with(['client:id,name', 'responsible:id,name,avatar', 'stage:id,name,color,order',
                'items:id,deal_id,name,unit,quantity'])
            ->withCount('tasks')
            ->withCount(['tasks as overdue_count' => fn ($q) => $q->where('status', '!=', 'done')->whereNotNull('due_date')->where('due_date', '<', now())]);

        // Воронка текущей компании; в режиме «Все компании» (id=0) — обе воронки,
        // колонки подписываются кодом фирмы.
        $companyId = CurrentCompany::id() ?: null;
        $companyCodes = Company::pluck('code', 'id');
        $stages = DealStage::with('translations')->where('is_active', true)
            ->when($companyId, fn ($q, $c) => $q->where(fn ($w) => $w->where('company_id', $c)->orWhereNull('company_id')))
            ->orderBy('order')->orderBy('company_id')->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->translatedName().(! $companyId && $s->company_id ? ' · '.$companyCodes[$s->company_id] : ''),
                'color' => $s->color, 'order' => $s->order, 'is_won' => $s->is_won,
                // Спец-логика этапов держится на системном типе из админки, а
                // НЕ на названии: этап можно переименовать и переставить.
                'stage_type' => $s->stage_type,
            ]);

        $deals = $view === 'list'
            ? (clone $base)->latest()->paginate(20)->withQueryString()
            : (clone $base)->latest()->get();

        // Бригадиру сумм не показываем — ни в карточке канбана, ни в списке.
        if (! $this->seesMoney($request->user())) {
            $deals->each(fn ($d) => $d->makeHidden(['budget', 'partner_pct', 'bonus_rate_override']));
        }

        return Inertia::render('Deals/Index', [
            'deals' => $deals,
            'stages' => $stages,
            'view' => $view,
            'filters' => $request->only('search', 'responsible', 'stage', 'branch', 'date_from', 'date_to', 'contract_from', 'contract_to'),
            // Сколько сделок в каждом филиале — с учётом остальных фильтров,
            // кроме самого филиала: счётчики на вкладках не должны обнуляться
            // от того, что одна вкладка уже выбрана.
            'branchCounts' => $this->branchCounts($request),
            'isLeadership' => RoleTraits::isLeadership($request->user()),
            // Роль/отдел — для фильтра: менеджеры сверху, остальные по отделам.
            'users' => User::where('is_active', true)->with(['roles:id,name', 'department:id,name'])
                ->orderBy('name')->get(['id', 'name', 'department_id'])
                ->map(fn ($u) => [
                    'id' => $u->id, 'name' => $u->name,
                    'is_manager' => $u->roles->contains('name', 'manager'),
                    'department' => $u->department?->name,
                ])->values(),
            'clients' => Client::orderBy('name')->get(['id', 'name']),
            'departments' => Department::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'can' => [
                'create' => $request->user()->can('create', Deal::class),
                // Удаление (в т.ч. массовое) — только admin (DealPolicy::delete).
                'delete' => $request->user()->hasRole('admin'),
            ],
            'companies' => $request->user()->companies()->where('is_active', true)->orderBy('name')->get(['companies.id', 'name', 'code']),
            // Филиалы = производственные площадки; каталог — источник товара.
            'branches' => StageSeeder::WORKSHOPS,
            // Каталог + категории: товары выбираются через категорию, поэтому
            // страница получает и то, и другое.
            'catalog' => Product::catalogForPicker(),
            'productCategories' => Product::pickerCategories(),
            'currentCompanyId' => CurrentCompany::id(),
            // Цеха фирмы: если их несколько, кнопка «В цех» открывает выбор.
            'workshopsByCompany' => Company::where('is_active', true)->pluck('id')
                ->mapWithKeys(fn ($id) => [$id => ProjectStage::workshopsFor((int) $id)]),
        ]);
    }

    /**
     * Отбор сделок для списка.
     *
     * Вынесен из index(), потому что тем же отбором считаются счётчики на
     * вкладках филиалов: разойдись эти два места, цифра на вкладке перестала
     * бы сходиться с тем, что в ней открывается.
     */
    private function filteredDeals(array $filters, User $viewer): Builder
    {
        $value = fn (string $key) => trim((string) ($filters[$key] ?? ''));

        return Deal::query()
            ->where('status', '!=', 'closed')
            ->when(CurrentCompany::id(), fn ($q, $c) => $q->where('company_id', $c))
            ->tap(fn ($q) => $this->scopeForViewer($q, $viewer))
            ->when($value('search'), fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('name', 'like', "%{$s}%")
                ->orWhere('number', 'like', "%{$s}%")
                ->orWhere('lot_number', 'like', "%{$s}%")
                ->orWhere('bin', 'like', "%{$s}%")
                ->orWhere('company_name', 'like', "%{$s}%")))
            ->when($value('responsible'), fn ($q, $r) => $q->where('responsible_user_id', $r))
            // Филиал: «Без филиала» отбирает сделки, которым площадку ещё не
            // назначили, — иначе они пропадали бы из всех вкладок разом.
            ->when($value('branch'), fn ($q, $b) => $b === self::NO_BRANCH
                ? $q->where(fn ($w) => $w->whereNull('branch')->orWhere('branch', ''))
                : $q->where('branch', $b))
            ->when((int) ($filters['stage'] ?? 0), fn ($q, $s) => $q->where('deal_stage_id', $s))
            ->when($value('date_from'), fn ($q, $d) => $q->whereDate('deadline', '>=', $d))
            ->when($value('date_to'), fn ($q, $d) => $q->whereDate('deadline', '<=', $d))
            ->when($value('contract_from'), fn ($q, $d) => $q->whereDate('contract_date', '>=', $d))
            ->when($value('contract_to'), fn ($q, $d) => $q->whereDate('contract_date', '<=', $d));
    }

    /**
     * Сколько сделок в каждом филиале.
     *
     * Считаем по тем же фильтрам, что и список, но БЕЗ самого филиала:
     * иначе выбранная вкладка обнуляла бы счётчики всех остальных, и по ним
     * нельзя было бы понять, куда переключаться.
     *
     * @return array<string, int>
     */
    private function branchCounts(Request $request): array
    {
        $counts = $this->filteredDeals($request->except('branch'), $request->user())
            ->selectRaw('branch, count(*) as total')
            ->groupBy('branch')
            ->pluck('total', 'branch');

        $result = [self::NO_BRANCH => 0];

        foreach (StageSeeder::WORKSHOPS as $branch) {
            $result[$branch] = (int) ($counts[$branch] ?? 0);
        }

        // Пустая строка и NULL — это одно и то же «филиал не назначен».
        foreach ($counts as $branch => $total) {
            if ($branch === null || $branch === '') {
                $result[self::NO_BRANCH] += (int) $total;
            }
        }

        return $result;
    }

    public function store(DealRequest $request, DealNumberService $numbers): RedirectResponse
    {
        $this->authorize('create', Deal::class);

        $data = $request->validated();
        // Название сделки = название компании (поле «Название сделки» убрано из UI).
        $data['name'] = $data['company_name'];

        // Deal belongs to a firm: the one picked in the form if the
        // user is a member of it, otherwise the current session company.
        $requested = (int) $request->input('company_id');
        $memberIds = $request->user()->companies()->where('is_active', true)->pluck('companies.id');
        $companyId = $memberIds->contains($requested) ? $requested : CurrentCompany::id();
        $company = $companyId ? Company::find($companyId) : null;

        $data['company_id'] = $company?->id;
        $data['number'] = $numbers->generate($company);
        // Первый этап ВОРОНКИ ФИРМЫ этой сделки (у каждой фирмы воронка своя).
        $data['deal_stage_id'] ??= DealStage::funnel($company?->id)->first()?->id;
        $data['status'] = $data['status'] ?? 'active';
        // Менеджер создаёт сделку только на себя — назначить ответственным другого нельзя.
        if (! RoleTraits::isLeadership($request->user())) {
            $data['responsible_user_id'] = $request->user()->id;
        }

        // Позиции не поле сделки — их пишет DealItemService, он же
        // пересчитывает сумму по строкам.
        $items = $data['items'] ?? [];
        unset($data['items']);

        $deal = Deal::create($data);
        if ($items !== []) {
            app(DealItemService::class)->syncDeal($deal, $items);
        }

        $short = $this->warnAboutShortage($deal, $items);

        return back()->with('success', 'Сделка создана.'.($short !== '' ? ' Не хватает на складе: '.$short.'.' : ''));
    }

    /**
     * Под новую сделку не хватает готовой продукции — сказать начальнику
     * производства сегодня, а не в день отгрузки.
     *
     * Сделку НЕ блокируем: договор уже подписан, и остановить его складом
     * поздно — недостающее просто нужно успеть сделать. Менеджеру возвращаем
     * ту же нехватку строкой, чтобы он узнал о ней сразу, не открывая склад.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    private function warnAboutShortage(Deal $deal, array $items): string
    {
        if ($items === []) {
            return '';
        }

        $rows = app(StockService::class)->shortages($items, $deal->company_id ? (int) $deal->company_id : null);
        if ($rows->isEmpty()) {
            return '';
        }

        $payload = $rows->map(fn ($r) => [
            'name' => $r['product']->name,
            'unit' => $r['product']->unit,
            'need' => $r['need'],
            'have' => $r['have'],
            'short' => $r['short'],
        ])->all();

        // Начальник производства — это директор; админ видит всё в любом случае.
        User::role(['admin', 'director'])->where('is_active', true)->get()
            ->each->notify(new ProductShortage($payload, $deal->number, $deal->id));

        return collect($payload)
            ->map(fn ($r) => $r['name'].' '.rtrim(rtrim(number_format($r['short'], 2, '.', ' '), '0'), '.').' '.($r['unit'] ?: ''))
            ->implode(', ');
    }

    /**
     * Остаток склада по позициям сделки: что нужно, что есть, чего не хватает.
     *
     * Считает `StockService` — тот же, что показывает склад: второй расчёт
     * «покороче» однажды разошёлся бы, и менеджер отправлял бы в цех объём,
     * который на складе уже лежит.
     *
     * @return array<string, mixed>
     */
    private function stockForItems(Deal $deal): array
    {
        $rows = $deal->items
            ->filter(fn ($item) => $item->product_id !== null && (float) $item->quantity > 0)
            ->map(fn ($item) => ['product_id' => (int) $item->product_id, 'quantity' => (float) $item->quantity])
            ->values()->all();

        if ($rows === []) {
            return ['short' => [], 'has_shortage' => false];
        }

        $short = app(StockService::class)
            ->shortages($rows, $deal->company_id ? (int) $deal->company_id : null);

        return [
            'short' => $short->map(fn ($row) => [
                'product_id' => $row['product']->id,
                'name' => $row['product']->name,
                'unit' => $row['product']->unit,
                'need' => $row['need'],
                'have' => $row['have'],
                'short' => $row['short'],
            ])->values(),
            'has_shortage' => $short->isNotEmpty(),
        ];
    }

    /**
     * Нехватка со склада — в план производства, одним нажатием.
     *
     * Менеджер видит в сделке «на складе 200, нужно 1000» и жмёт кнопку. Объём
     * уходит в «План — факт» СРАЗУ, без промежуточной заявки: заявку всё равно
     * разбирал бы тот же начальник производства, и лишний шаг только тянул бы
     * время.
     *
     * Считаем нехватку ЗАНОВО на сервере, а не берём из формы: между тем, что
     * менеджер увидел на экране, и нажатием кнопки склад мог измениться, а
     * присланное число — это то, чему верить нельзя.
     */
    public function toProduction(Request $request, Deal $deal, StockService $stock, ProductionPlanService $plans): RedirectResponse
    {
        $this->authorize('view', $deal);

        $deal->loadMissing('items:id,deal_id,product_id,name,unit,quantity');

        $rows = $deal->items
            ->filter(fn ($item) => $item->product_id !== null && (float) $item->quantity > 0)
            ->map(fn ($item) => ['product_id' => (int) $item->product_id, 'quantity' => (float) $item->quantity])
            ->values()->all();

        $short = $stock->shortages($rows, $deal->company_id ? (int) $deal->company_id : null);

        if ($short->isEmpty()) {
            return back()->with('success', 'Склада хватает — в план ничего не добавлено.');
        }

        $queued = $plans->addShortage($deal, $short->map(fn ($row) => [
            'product_id' => $row['product']->id,
            'qty' => $row['short'],
            'unit' => $row['product']->unit,
        ])->all(), $request->user());

        $what = collect($queued)
            ->map(fn ($p) => $p->product?->name.' '.rtrim(rtrim(number_format((float) $p->plan_qty, 2, '.', ' '), '0'), '.').' '.($p->unit ?: ''))
            ->implode(', ');

        return back()->with('success', 'В план производства: '.$what.'. Бригаду назначит начальник производства.');
    }

    public function show(Deal $deal, FinanceService $finance, ProductionProgressService $progress): Response
    {
        $this->authorize('view', $deal);

        $money = $this->seesMoney(request()->user());

        // Цены позиций — деньги: бригадиру их не выбираем из БД вовсе. Что
        // делать и сколько — выбираем всегда: без этого сделка нечитаема.
        $itemColumns = ['id', 'deal_id', 'product_id', 'name', 'unit', 'quantity', 'sort'];
        if ($money) {
            $itemColumns[] = 'price';
            $itemColumns[] = 'amount';
        }

        $deal->load(array_filter([
            'client', 'responsible:id,name,avatar', 'foreman:id,name,avatar', 'department:id,name',
            'stage', 'project:id,number,name,status',
            // Снимки, сделанные в цехе, нужны менеджеру в сделке — иначе он
            // не видит, что отлили, пока не откроет карточку заказа.
            'project.documents' => fn ($q) => $q->where('is_active', true)->with('user:id,name')->latest(),
            'items' => fn ($q) => $q->select($itemColumns)
                // Фото каждой позиции: «вот эта плитка выглядит так».
                ->with(['documents' => fn ($d) => $d->where('is_active', true)->with('user:id,name')->latest()]),
            'tasks' => fn ($q) => $q->with('assignee:id,name')->latest(),
            // Счета и расходы — это деньги: бригадиру их не грузим вовсе,
            // чтобы суммы не уехали в браузер «на всякий случай».
            'invoices' => $money ? fn ($q) => $q->withSum('payments as payments_sum_amount', 'amount')
                ->with('payments')->latest() : null,
            'expenses' => $money ? fn ($q) => $q->with(['responsible:id,name,avatar', 'material:id,name,unit'])->latest() : null,
            'documents' => fn ($q) => $q->where('is_active', true)->with('user:id,name')->latest(),
            'comments' => fn ($q) => $q->with('user:id,name')->latest(),
        ]));

        // Суммы прячем и в самой модели: бюджет, доля партнёра и ручной %
        // бонуса уезжали во фронт вместе со сделкой.
        if (! $money) {
            $deal->makeHidden(['budget', 'partner_pct', 'bonus_rate_override']);
        }

        $dealChat = Chat::firstOrCreate(
            ['deal_id' => $deal->id],
            ['type' => 'group', 'name' => 'Чат '.$deal->number, 'is_active' => true]
        );

        $taxRate = ((float) Setting::get('tax_percent', 3)) / 100;
        $confirmedExpense = (float) ($money ? $deal->expenses->where('status', 'confirmed')->sum('amount') : 0);
        $dealBudget = (float) $deal->budget;
        $dealTax = round($dealBudget * $taxRate, 2);
        // Доля партнёра: только % (partner_pct), сумма = % × сумма договора, минусуется из остатка.
        $dealPartner = PayrollService::partnerSum($dealBudget, $deal->partner_pct);
        $dealRemainder = round($dealBudget - $dealTax - $confirmedExpense - $dealPartner, 2);
        // Ступенчатый бонус: ступень по марже ДО налога (как «Маржа» на карточке),
        // сам бонус — % от остатка (после налога). Та же формула в ЗП/аналитике.
        $dealMarginPct = PayrollService::marginPct($dealBudget, $dealRemainder, $dealTax);
        // Ручной % финансиста по этой сделке (null = авто-ступень от маржи).
        $bonusOverride = $deal->bonus_rate_override !== null ? (float) $deal->bonus_rate_override : null;
        // Личный % ответственного менеджера — та же ставка, что и в ЗП.
        $dealUserPercent = PayrollService::userBonusPercent($deal->responsible_user_id);
        // Ставка зависит от типа сделки (производство/перепродажа) — единая
        // точка расчёта на все страницы.
        $dealBonusParts = PayrollService::dealBonus(
            $dealRemainder, $bonusOverride, $dealUserPercent,
            $deal->deal_type ?? PayrollService::TYPE_PRODUCTION,
        );
        $dealBonus = $dealBonusParts['total'];
        $dealBonusRate = $dealBonusParts['rate'] / 100;

        // Галочка-гейт текущего этапа (настраивается в Настройки → Этапы).
        $gateStage = self::gateStage($deal);
        $stageTask = null;
        if ($gateStage) {
            $openTask = $deal->tasks()->where('title', 'like', $gateStage->gate_task_title.'%')->where('status', '!=', 'done')->orderBy('due_date')->first();
            $gateRole = $gateStage->gate_task_role ?: 'financist';
            $stageTask = [
                'label' => $gateStage->gate_task_title.' — выполнено',
                'done' => $openTask === null,
                'due' => optional($openTask?->due_date)->toDateTimeString(),
                'role' => $gateRole,
                'roleLabel' => self::GATE_ROLE_LABELS[$gateRole] ?? $gateRole,
            ];
        }

        return Inertia::render('Deals/Show', [
            'deal' => $deal,
            // Филиал сделки правится прямо в карточке: раньше его можно было
            // задать только при создании, и ошибку выбора нечем было исправить.
            'branches' => StageSeeder::WORKSHOPS,
            'stageTask' => $stageTask,
            // История этапов: сколько сделка провела на каждом и кто перевёл
            // (открытый лог — тикает, как тайминг у заказа цеха).
            'stageLogs' => DealStageLog::where('deal_id', $deal->id)
                ->with('mover:id,name')->orderBy('entered_at')->orderBy('id')->get()
                ->map(fn ($l) => [
                    'stage' => $l->stage_name,
                    'mover' => $l->mover?->name,
                    'entered_at' => $l->entered_at->toIso8601String(),
                    'left_at' => $l->left_at?->toIso8601String(),
                    'seconds' => $l->left_at ? (int) $l->duration_seconds : (int) abs(now()->diffInSeconds($l->entered_at)),
                    'open' => $l->left_at === null,
                ]),
            // Остатки касса/банк — бухгалтеру в форме расхода («доступно N»);
            // менеджеру деньги компании не показываем.
            'balances' => request()->user()->hasAnyRole(['admin', 'financist'])
                ? $finance->companyBalances($deal->company_id ? (int) $deal->company_id : null)
                : null,
            // Склад компании сделки — для расходов по материалам (показ остатка).
            'materials' => Material::query()
                ->when($deal->company_id, fn ($q, $c) => $q->where('company_id', $c))
                ->orderBy('name')->get(['id', 'name', 'unit', 'quantity', 'price']),
            // Раскладка денег — только тем, кто их видит.
            'profit' => ! $money ? null : [
                'budget' => $dealBudget,
                'tax' => $dealTax, 'taxRate' => $taxRate * 100,
                'expense' => $confirmedExpense,
                'partner' => $dealPartner,
                'partnerPct' => $deal->partner_pct !== null ? (float) $deal->partner_pct : null,
                'remainder' => $dealRemainder,
                'bonus' => $dealBonus, 'bonusRate' => round($dealBonusRate * 100, 1),
                'bonusManual' => $bonusOverride !== null,
                'company' => round($dealRemainder - $dealBonus, 2),
                // Маржа — тем же методом, что в Сводном отчёте: показатель
                // здоровья сделки должен быть ОДНИМ числом, где бы его ни
                // смотрели. На бонус не влияет (§5.7).
                'margin' => PayrollService::marginPct($dealBudget, $dealRemainder, $dealTax),
            ],
            // Склад против заказа: сколько обещали и сколько лежит. Показываем
            // всем, кто видит сделку, — это не деньги, а наличие.
            'stock' => $this->stockForItems($deal),
            'chatId' => $dealChat->id,
            'workshops' => ProjectStage::workshopsFor($deal->company_id ? (int) $deal->company_id : null),
            'users' => User::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'stages' => DealStage::with('translations')->where('is_active', true)
                ->when($deal->company_id, fn ($q, $c) => $q->where(fn ($w) => $w->where('company_id', $c)->orWhereNull('company_id')))
                ->orderBy('order')->get()
                ->map(fn ($s) => ['id' => $s->id, 'name' => $s->translatedName(), 'color' => $s->color, 'order' => $s->order,
                    'is_won' => $s->is_won, 'stage_type' => $s->stage_type, 'checklist' => $s->checklist]),
            'finance' => $money ? $finance->summaryFor($deal) : null,
            'history' => AuditFormatter::humanize(AuditLog::where('table_name', 'deals')->where('record_id', $deal->id)->with('user:id,name')->latest()->limit(100)->get(), ['deal_stage_id' => DealStage::pluck('name', 'id'), 'responsible_user_id' => User::pluck('name', 'id')]),
            'customFields' => app(CustomFieldService::class)->forEntity('deal', $deal->id),
            // Сколько по каждой позиции уже сделано в цехе. Считает один
            // сервис — и здесь, и в цехе, и на производстве: разойдись счёт,
            // менеджер и бригадир видели бы разный остаток по одному заказу.
            'itemProgress' => $progress->forItems($deal->items),
            'can' => [
                'update' => request()->user()->can('update', $deal),
                'advance' => request()->user()->can('advance', $deal),
                'delete' => request()->user()->can('delete', $deal),
                'money' => $money,
                // Бригадира на сделку ставит директор (и админ) — он решает,
                // чья бригада едет на объект.
                'setForeman' => request()->user()->hasAnyRole(['admin', 'director']),
            ],
            // Кандидаты в бригадиры — только люди с этой ролью.
            'foremen' => request()->user()->hasAnyRole(['admin', 'director'])
                ? User::role('foreman')->where('is_active', true)->orderBy('name')->get(['id', 'name'])
                : [],
        ]);
    }

    public function update(DealRequest $request, Deal $deal): RedirectResponse
    {
        $this->authorize('update', $deal);
        $this->assertNotFrozen($request, $deal);
        $data = $request->validated();
        // Название сделки зеркалит название компании (поле убрано из UI).
        $data['name'] = $data['company_name'];

        // `items` присылают только формы, где товары редактируются: отсутствие
        // ключа означает «не трогать позиции», пустой массив — «удалить все».
        $items = $data['items'] ?? null;
        unset($data['items']);

        $deal->update($data);
        if ($items !== null) {
            app(DealItemService::class)->syncDeal($deal, $items);
        }

        return back()->with('success', 'Сделка обновлена.');
    }

    public function destroy(Request $request, Deal $deal): RedirectResponse
    {
        $this->authorize('delete', $deal);
        $this->assertNotFrozen($request, $deal);
        $deal->delete();

        // Не back(): удаляют из карточки сделки, а «назад» — это страница
        // только что удалённой сделки → 404 (No query results for model Deal).
        return redirect()->route('deals.index')->with('success', 'Сделка удалена.');
    }

    /**
     * Массовое удаление из списка. Права те же, что у одиночного удаления:
     * DealPolicy::delete (только admin, в пределах своей компании) — проверяется
     * ПО КАЖДОЙ сделке до удаления, чтобы не удалить «частично».
     */
    public function bulkDestroy(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer'],
        ]);

        $deals = Deal::whereIn('id', $data['ids'])->get();
        abort_if($deals->isEmpty(), 404);
        foreach ($deals as $deal) {
            $this->authorize('delete', $deal);
        }

        // each->delete() (не массовый query) — сохраняет SoftDeletes и аудит-события.
        $deals->each->delete();

        return back()->with('success', 'Удалено сделок: '.$deals->count().'.');
    }

    /**
     * Overdue deals: deadline is in the past and deal is still open.
     * Sorted so the most-overdue deal (earliest deadline) is on top.
     */
    public function overdue(Request $request): Response
    {
        $this->authorize('viewAny', Deal::class);

        $today = now()->startOfDay();

        $deals = Deal::query()
            ->with(['responsible:id,name,avatar', 'stage:id,name,color'])
            ->when(CurrentCompany::id(), fn ($q, $c) => $q->where('company_id', $c))
            ->whereNotNull('deadline')
            ->whereDate('deadline', '<', $today)
            ->whereNotIn('status', ['closed', 'cancelled'])
            // ЭСФ и «Оплата успешно» — не просрочка; на «Акт утверждение»
            // просроченная сделка ПОКАЗЫВАЕТСЯ (по stage_type, имя ненадёжно).
            ->whereDoesntHave('stage', fn ($s) => $s->where('is_won', true)->orWhere('stage_type', 'esf'))
            ->tap(fn ($q) => $this->scopeForViewer($q, $request->user()))
            ->orderBy('deadline')
            ->get()
            ->map(function ($d) use ($today) {
                $d->overdue_days = (int) Carbon::parse($d->deadline)->startOfDay()->diffInDays($today);

                return $d;
            });

        // Бригадир видит, ЧТО горит, но не на сколько: та же проверка, что в
        // списке сделок и в карточке. Разойдись они, сумма утекла бы через ту
        // страницу, где забыли.
        if (! $this->seesMoney($request->user())) {
            $deals->each(fn ($d) => $d->makeHidden(['budget', 'partner_pct', 'bonus_rate_override']));
        }

        // Просроченные заказы цеха: у заказа свой дедлайн (унаследован от
        // сделки) — горящий цех виден на той же странице.
        $projects = Project::query()
            ->with(['responsible:id,name,avatar', 'stage:id,name,color', 'deal:id,number,company_name,company_id'])
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->whereNotNull('deadline')
            ->whereDate('deadline', '<', $today)
            ->when(CurrentCompany::id(), fn ($q, $c) => $q->whereHas('deal', fn ($d) => $d->where('company_id', $c)))
            ->whereHas('deal', fn ($d) => $this->scopeForViewer($d, $request->user()))
            ->orderBy('deadline')
            ->get()
            ->map(function ($p) use ($today) {
                $p->overdue_days = (int) Carbon::parse($p->deadline)->startOfDay()->diffInDays($today);

                return $p;
            });

        if (! $this->seesMoney($request->user())) {
            $projects->each(fn ($p) => $p->makeHidden('budget'));
        }

        return Inertia::render('Deals/Overdue', ['deals' => $deals, 'projects' => $projects]);
    }
}
