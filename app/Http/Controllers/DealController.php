<?php

namespace App\Http\Controllers;

use App\Http\Requests\DealRequest;
use App\Models\Client;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\Department;
use App\Models\User;
use App\Services\DealNumberService;
use App\Services\FinanceService;
use App\Services\StageTransitionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DealController extends Controller
{
    /**
     * Видимость сделок в списках по роли: руководство — все; технолог —
     * только сделки на этапе «Дизайн и расчет», снабженец — на «Закупе»
     * (их гейт-этапы, чтобы не путались в чужих сделках); менеджер — свои.
     * Прямые ссылки (из уведомлений/задач) шире — их решает DealPolicy.
     */
    private function scopeForViewer($query, User $user): void
    {
        if ($user->hasAnyRole(['admin', 'director', 'financist'])) {
            return;
        }
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
        $query->where('responsible_user_id', $user->id);
    }

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Deal::class);

        $view = $request->string('view', 'kanban')->toString();

        $base = Deal::query()
            ->select('deals.*')
            // ⏱ на канбане: когда сделка вошла на текущий этап (открытый лог).
            ->addSelect(['stage_entered_at' => \App\Models\DealStageLog::select('entered_at')
                ->whereColumn('deal_id', 'deals.id')->whereNull('left_at')
                ->latest('entered_at')->limit(1)])
            ->with(['client:id,name', 'responsible:id,name,avatar', 'stage:id,name,color,order'])
            ->withCount('tasks')
            ->withCount(['tasks as overdue_count' => fn ($q) => $q->where('status', '!=', 'done')->whereNotNull('due_date')->where('due_date', '<', now())])
            ->where('status', '!=', 'closed')
            ->when(\App\Support\CurrentCompany::id(), fn ($q, $c) => $q->where('company_id', $c))
            ->tap(fn ($q) => $this->scopeForViewer($q, $request->user()))
            ->when($request->string('search')->toString(), fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('name', 'like', "%{$s}%")
                ->orWhere('number', 'like', "%{$s}%")
                ->orWhere('lot_number', 'like', "%{$s}%")
                ->orWhere('bin', 'like', "%{$s}%")
                ->orWhere('company_name', 'like', "%{$s}%")))
            ->when($request->string('responsible')->toString(), fn ($q, $r) => $q->where('responsible_user_id', $r))
            ->when($request->integer('stage'), fn ($q, $s) => $q->where('deal_stage_id', $s))
            ->when($request->date('date_from'), fn ($q, $d) => $q->whereDate('deadline', '>=', $d))
            ->when($request->date('date_to'), fn ($q, $d) => $q->whereDate('deadline', '<=', $d))
            ->when($request->date('contract_from'), fn ($q, $d) => $q->whereDate('contract_date', '>=', $d))
            ->when($request->date('contract_to'), fn ($q, $d) => $q->whereDate('contract_date', '<=', $d));

        // Воронка текущей компании; в режиме «Все компании» (id=0) — обе воронки,
        // колонки подписываются кодом фирмы.
        $companyId = \App\Support\CurrentCompany::id() ?: null;
        $companyCodes = \App\Models\Company::pluck('code', 'id');
        $stages = DealStage::with('translations')->where('is_active', true)
            ->when($companyId, fn ($q, $c) => $q->where(fn ($w) => $w->where('company_id', $c)->orWhereNull('company_id')))
            ->orderBy('order')->orderBy('company_id')->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->translatedName().(! $companyId && $s->company_id ? ' · '.$companyCodes[$s->company_id] : ''),
                'color' => $s->color, 'order' => $s->order, 'is_won' => $s->is_won,
            ]);

        $deals = $view === 'list'
            ? (clone $base)->latest()->paginate(20)->withQueryString()
            : (clone $base)->latest()->get();

        return Inertia::render('Deals/Index', [
            'deals' => $deals,
            'stages' => $stages,
            'view' => $view,
            'filters' => $request->only('search', 'responsible', 'stage', 'date_from', 'date_to', 'contract_from', 'contract_to'),
            'isLeadership' => $request->user()->hasAnyRole(['admin', 'director', 'financist']),
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
            'branches' => \Database\Seeders\StageSeeder::WORKSHOPS,
            'catalog' => \App\Models\Product::active()->orderBy('name')
                ->get(['id', 'name', 'unit', 'price'])
                ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'unit' => $p->unit, 'price' => (float) $p->price]),
            'currentCompanyId' => \App\Support\CurrentCompany::id(),
            // Цеха фирмы: если их несколько, кнопка «В цех» открывает выбор.
            'workshopsByCompany' => \App\Models\Company::where('is_active', true)->pluck('id')
                ->mapWithKeys(fn ($id) => [$id => \App\Models\ProjectStage::workshopsFor((int) $id)]),
        ]);
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
        $companyId = $memberIds->contains($requested) ? $requested : \App\Support\CurrentCompany::id();
        $company = $companyId ? \App\Models\Company::find($companyId) : null;

        $data['company_id'] = $company?->id;
        $data['number'] = $numbers->generate($company);
        // Первый этап ВОРОНКИ ФИРМЫ этой сделки (у каждой фирмы воронка своя).
        $data['deal_stage_id'] ??= DealStage::funnel($company?->id)->first()?->id;
        $data['status'] = $data['status'] ?? 'active';
        // Менеджер создаёт сделку только на себя — назначить ответственным другого нельзя.
        if (! $request->user()->hasAnyRole(['admin', 'director', 'financist'])) {
            $data['responsible_user_id'] = $request->user()->id;
        }

        Deal::create($data);

        return back()->with('success', 'Сделка создана.');
    }

    public function show(Deal $deal, FinanceService $finance): Response
    {
        $this->authorize('view', $deal);

        $deal->load([
            'client', 'responsible:id,name,avatar', 'department:id,name',
            'stage', 'project:id,number,name,status',
            'tasks' => fn ($q) => $q->with('assignee:id,name')->latest(),
            'invoices' => fn ($q) => $q->withSum('payments as payments_sum_amount', 'amount')
                ->with('payments')->latest(),
            'expenses' => fn ($q) => $q->with(['responsible:id,name,avatar', 'material:id,name,unit'])->latest(),
            'documents' => fn ($q) => $q->where('is_active', true)->with('user:id,name')->latest(),
            'comments' => fn ($q) => $q->with('user:id,name')->latest(),
        ]);

        $dealChat = \App\Models\Chat::firstOrCreate(
            ['deal_id' => $deal->id],
            ['type' => 'group', 'name' => 'Чат ' . $deal->number, 'is_active' => true]
        );

        $taxRate = ((float) \App\Models\Setting::get('tax_percent', 3)) / 100;
        $confirmedExpense = (float) $deal->expenses->where('status', 'confirmed')->sum('amount');
        $dealBudget = (float) $deal->budget;
        $dealTax = round($dealBudget * $taxRate, 2);
        // Доля партнёра: только % (partner_pct), сумма = % × сумма договора, минусуется из остатка.
        $dealPartner = \App\Services\PayrollService::partnerSum($dealBudget, $deal->partner_pct);
        $dealRemainder = round($dealBudget - $dealTax - $confirmedExpense - $dealPartner, 2);
        // Ступенчатый бонус: ступень по марже ДО налога (как «Маржа» на карточке),
        // сам бонус — % от остатка (после налога). Та же формула в ЗП/аналитике.
        $dealMarginPct = \App\Services\PayrollService::marginPct($dealBudget, $dealRemainder, $dealTax);
        // Ручной % финансиста по этой сделке (null = авто-ступень от маржи).
        $bonusOverride = $deal->bonus_rate_override !== null ? (float) $deal->bonus_rate_override : null;
        // Личный % ответственного менеджера — та же ставка, что и в ЗП.
        $dealUserPercent = \App\Services\PayrollService::userBonusPercent($deal->responsible_user_id);
        $dealBonusRate = \App\Services\PayrollService::effectiveBonusRate($dealMarginPct, $bonusOverride, $dealUserPercent);
        $dealBonus = \App\Services\PayrollService::marginBonus($dealBudget, $dealRemainder, $dealTax, $bonusOverride, $dealUserPercent);

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
            'stageTask' => $stageTask,
            // История этапов: сколько сделка провела на каждом и кто перевёл
            // (открытый лог — тикает, как тайминг у заказа цеха).
            'stageLogs' => \App\Models\DealStageLog::where('deal_id', $deal->id)
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
            'materials' => \App\Models\Material::query()
                ->when($deal->company_id, fn ($q, $c) => $q->where('company_id', $c))
                ->orderBy('name')->get(['id', 'name', 'unit', 'quantity', 'price']),
            'profit' => [
                'budget' => $dealBudget,
                'tax' => $dealTax, 'taxRate' => $taxRate * 100,
                'expense' => $confirmedExpense,
                'partner' => $dealPartner,
                'partnerPct' => $deal->partner_pct !== null ? (float) $deal->partner_pct : null,
                'remainder' => $dealRemainder,
                'bonus' => $dealBonus, 'bonusRate' => round($dealBonusRate * 100, 1),
                'bonusManual' => $bonusOverride !== null,
                'company' => round($dealRemainder - $dealBonus, 2),
            ],
            'chatId' => $dealChat->id,
            'workshops' => \App\Models\ProjectStage::workshopsFor($deal->company_id ? (int) $deal->company_id : null),
            'users' => User::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'stages' => DealStage::with('translations')->where('is_active', true)
                ->when($deal->company_id, fn ($q, $c) => $q->where(fn ($w) => $w->where('company_id', $c)->orWhereNull('company_id')))
                ->orderBy('order')->get()
                ->map(fn ($s) => ['id' => $s->id, 'name' => $s->translatedName(), 'color' => $s->color, 'order' => $s->order, 'is_won' => $s->is_won, 'checklist' => $s->checklist]),
            'finance' => $finance->summaryFor($deal),
            'history' => \App\Support\AuditFormatter::humanize(\App\Models\AuditLog::where('table_name', 'deals')->where('record_id', $deal->id)->with('user:id,name')->latest()->limit(100)->get(), ['deal_stage_id' => DealStage::pluck('name', 'id'), 'responsible_user_id' => User::pluck('name', 'id')]),
            'customFields' => app(\App\Services\CustomFieldService::class)->forEntity('deal', $deal->id),
            'can' => [
                'update' => request()->user()->can('update', $deal),
                'advance' => request()->user()->can('advance', $deal),
                'delete' => request()->user()->can('delete', $deal),
            ],
        ]);
    }

    public function update(DealRequest $request, Deal $deal): RedirectResponse
    {
        $this->authorize('update', $deal);
        $this->assertNotFrozen($request, $deal);
        $data = $request->validated();
        // Название сделки зеркалит название компании (поле убрано из UI).
        $data['name'] = $data['company_name'];
        $deal->update($data);

        return back()->with('success', 'Сделка обновлена.');
    }

    /**
     * Галочка-гейт текущего этапа: закрывает гейт-задачу («Выставить акт…»,
     * «Подтвердить дизайн…» и т.п.), после чего сделку можно двигать дальше.
     * Ставит её роль гейта этапа (технолог — «Замер и расчёт», снабженец —
     * «Закуп сырья», бухгалтер — АКТ/ЭСФ/Оплата) или админ.
     */
    public function completeStageTask(Request $request, Deal $deal): RedirectResponse
    {
        // Не 'update': технолог/снабженец не редактируют сделку, но гейт ставят.
        $this->authorize('view', $deal);

        $gateStage = self::gateStage($deal);
        abort_unless($gateStage !== null, 404);

        $gateRole = $gateStage->gate_task_role ?: 'financist';
        abort_unless(
            $request->user()->hasRole('admin') || $request->user()->hasRole($gateRole),
            403,
            'Галочку ставит только '.(self::GATE_ROLE_LABELS[$gateRole] ?? $gateRole).' или админ.'
        );

        $deal->tasks()->where('title', 'like', $gateStage->gate_task_title.'%')->where('status', '!=', 'done')
            ->get()->each(fn ($t) => $t->update(['status' => 'done', 'completed_at' => now()]));

        return back()->with('success', 'Галочка поставлена — сделку можно переводить дальше.');
    }

    /**
     * После «Акт утверждение» сделку изменяет только бухгалтер/админ:
     * менеджеру (и директору) недоступны редактирование, смена ответственного
     * и удаление сделки на этапах АКТ / ЭСФ / Оплата успешно.
     */
    private function assertNotFrozen(Request $request, Deal $deal): void
    {
        if ($request->user()->hasAnyRole(['admin', 'financist'])) {
            return;
        }
        $companyId = $deal->company_id ? (int) $deal->company_id : null;
        $frozenIds = collect([
            DealStage::actStage($companyId)?->id,
            DealStage::esfStage($companyId)?->id,
            DealStage::wonStage($companyId)?->id,
        ])->filter();

        abort_if(
            $frozenIds->contains($deal->deal_stage_id),
            403,
            'После «Акт утверждение» сделку изменяет только бухгалтер или админ.'
        );
    }

    /** Текущий этап сделки, если на нём настроен гейт (или null). */
    /** Подписи ролей гейт-задач (для сообщений и карточки сделки). */
    private const GATE_ROLE_LABELS = ['financist' => 'бухгалтер', 'designer' => 'технолог', 'supplier' => 'снабженец', 'manager' => 'менеджер', 'director' => 'директор', 'admin' => 'админ'];

    private static function gateStage(Deal $deal): ?DealStage
    {
        $stage = $deal->stage ?? DealStage::find($deal->deal_stage_id);

        return $stage && $stage->hasGate() ? $stage : null;
    }

    public function updateStage(Request $request, Deal $deal, StageTransitionService $transitions): RedirectResponse
    {
        $this->authorize('update', $deal);

        $validated = $request->validate(['deal_stage_id' => ['required', 'exists:deal_stages,id']]);
        $target = DealStage::findOrFail($validated['deal_stage_id']);

        // Причину отказа (гейты этапов) показываем красным баннером, а не
        // тихой ошибкой валидации, которую на канбане не видно.
        try {
            $transitions->moveToStage($deal, $target);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->first());
        }

        return back()->with('success', 'Этап сделки обновлён.');
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

    public function advance(Deal $deal, StageTransitionService $transitions): \Illuminate\Http\RedirectResponse
    {
        // Не 'update': у технолога есть право «Далее» со своего этапа (DealPolicy::advance).
        $this->authorize('advance', $deal);
        // Следующий этап — по ПОЗИЦИИ в воронке (не по order > current): при
        // задвоенном order переход не перескакивает соседний этап.
        $funnel = DealStage::funnel($deal->company_id ? (int) $deal->company_id : null)->values();
        $idx = $funnel->search(fn ($s) => $s->id === $deal->deal_stage_id);
        $next = $idx !== false ? $funnel->get($idx + 1) : $funnel->first();
        if ($next) {
            try {
                $transitions->moveToStage($deal, $next);
            } catch (\Illuminate\Validation\ValidationException $e) {
                return back()->with('error', collect($e->errors())->flatten()->first());
            }
            return back()->with('success', 'Сделка переведена на этап «'.$next->name.'».');
        }
        return back()->with('error', 'Это последний этап.');
    }

    public function sendToWorkshop(Request $request, Deal $deal, \App\Services\ProjectService $projects): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('update', $deal);
        if ($deal->project && $deal->project->status !== 'completed') {
            return back()->with('error', 'Заказ уже в цехе.');
        }
        // Если цехов несколько — при отправке нужно выбрать конкретный.
        $available = \App\Models\ProjectStage::workshopsFor($deal->company_id ? (int) $deal->company_id : null);
        $workshop = $request->string('workshop')->toString() ?: null;
        if (count($available) > 1 && ! in_array($workshop, $available, true)) {
            return back()->with('error', 'Выберите цех: '.implode(' или ', $available).'.');
        }
        $project = $projects->createFromDeal($deal, $workshop);
        $deal->update(['status' => 'closed', 'closed_at' => now()]);
        return back()->with('success', 'Отправлено в цех: '.$project->number.'.');
    }

    /**
     * Ручной % бонуса менеджера по сделке — ставит ТОЛЬКО финансист/админ.
     * null (пустое поле) = вернуть автоматическую ступень от маржи.
     */
    public function updateBonusRate(Request $request, Deal $deal): RedirectResponse
    {
        abort_unless($request->user()->hasAnyRole(['admin', 'financist']), 403, 'Процент бонуса меняет финансист или администратор.');
        $validated = $request->validate(['bonus_rate_override' => ['nullable', 'numeric', 'min:0', 'max:100']]);

        $deal->update(['bonus_rate_override' => $validated['bonus_rate_override'] ?? null]);

        return back()->with('success', isset($validated['bonus_rate_override'])
            ? 'Бонус менеджера по сделке: '.rtrim(rtrim(number_format((float) $validated['bonus_rate_override'], 2, '.', ''), '0'), '.').'% (вручную).'
            : 'Бонус менеджера: автоматически по ступеням маржи.');
    }

    public function updateResponsible(Request $request, Deal $deal): RedirectResponse
    {
        // Only the owner (or leadership) may (re)assign the responsible person.
        $this->authorize('update', $deal);
        $this->assertNotFrozen($request, $deal);
        $validated = $request->validate(['responsible_user_id' => ['nullable', 'exists:users,id']]);
        $deal->update(['responsible_user_id' => $validated['responsible_user_id'] ?: null]);

        return back()->with('success', 'Ответственный изменён.');
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
            ->when(\App\Support\CurrentCompany::id(), fn ($q, $c) => $q->where('company_id', $c))
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
                $d->overdue_days = (int) \Illuminate\Support\Carbon::parse($d->deadline)->startOfDay()->diffInDays($today);

                return $d;
            });

        // Просроченные заказы цеха: у заказа свой дедлайн (унаследован от
        // сделки) — горящий цех виден на той же странице.
        $projects = \App\Models\Project::query()
            ->with(['responsible:id,name,avatar', 'stage:id,name,color', 'deal:id,number,company_name,company_id'])
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->whereNotNull('deadline')
            ->whereDate('deadline', '<', $today)
            ->when(\App\Support\CurrentCompany::id(), fn ($q, $c) => $q->whereHas('deal', fn ($d) => $d->where('company_id', $c)))
            ->tap(fn ($q) => $this->scopeForViewer($q, $request->user()))
            ->orderBy('deadline')
            ->get()
            ->map(function ($p) use ($today) {
                $p->overdue_days = (int) \Illuminate\Support\Carbon::parse($p->deadline)->startOfDay()->diffInDays($today);

                return $p;
            });

        return Inertia::render('Deals/Overdue', ['deals' => $deals, 'projects' => $projects]);
    }

    /**
     * Look up an existing company by БИН (deals first, then clients).
     * Used by the create form to offer copying company data.
     */
    public function binLookup(Request $request): \Illuminate\Http\JsonResponse
    {
        $this->authorize('create', Deal::class);
        $bin = trim($request->string('bin')->toString());
        if ($bin === '') {
            return response()->json(['match' => null, 'history' => []]);
        }

        // Изоляция фирм: подсказки по БИН — только по сделкам ТЕКУЩЕЙ компании,
        // иначе менеджер одной фирмы по БИН увидел бы бюджеты/сделки другой.
        $client = Client::where('inn', $bin)->first();
        $deal = Deal::forCurrentCompany()->where('bin', $bin)->whereNotNull('company_name')->latest()->first();

        $match = null;
        if ($client) {
            $match = ['company_name' => $client->name, 'bin' => $client->inn, 'phone' => $client->phone, 'address' => $client->address];
        } elseif ($deal) {
            $match = ['company_name' => $deal->company_name, 'bin' => $deal->bin, 'phone' => null, 'address' => null];
        }

        // История по БИН — тоже только текущая компания.
        $history = Deal::forCurrentCompany()->where('bin', $bin)->with('stage:id,name,color')
            ->latest()->limit(30)
            ->get(['id', 'number', 'company_name', 'client_name', 'budget', 'deadline', 'deal_stage_id', 'created_at'])
            ->map(fn ($d) => [
                'id' => $d->id, 'number' => $d->number,
                'company' => $d->company_name, 'client' => $d->client_name,
                'budget' => (float) $d->budget, 'deadline' => optional($d->deadline)->toDateString(),
                'stage' => optional($d->stage)->name, 'color' => optional($d->stage)->color,
                'created' => optional($d->created_at)->toDateString(),
            ]);

        return response()->json(['match' => $match, 'history' => $history]);
    }
}
