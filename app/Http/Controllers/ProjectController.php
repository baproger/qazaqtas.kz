<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\Project;
use App\Models\ProjectStage;
use App\Models\User;
use App\Services\FinanceService;
use App\Services\ProductionProgressService;
use App\Support\AuditFormatter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    private function canSeeMoney(Request $request): bool
    {
        return $request->user()->hasAnyRole(['admin', 'director', 'financist', 'manager']);
    }

    /**
     * Доступ сотрудника к цеху заказа (users.workshops): работник «Металл
     * цеха Шымкента не видит и не двигает заказы Алматы или Тараза; пустой
     * список = все цеха (руководство и сотрудники без ограничения по цеху).
     */
    private function assertWorkshopAccess(Project $project): void
    {
        abort_unless(auth()->user()->worksInWorkshop($project->workshop), 403,
            'Заказ другого цеха: у вас доступ только к своему цеху.');
    }

    /**
     * Может ли этот человек записывать выработку по позиции заказа.
     *
     * Бригадир пишет от имени СВОЕЙ бригады этого цеха: чужую выработку он
     * себе не припишет. Руководство пишет за любую.
     */
    private function canReport(Request $request, Project $project): bool
    {
        $user = $request->user();
        if (! $user->worksInWorkshop($project->workshop)) {
            return false;
        }
        if ($user->hasAnyRole(['admin', 'director'])) {
            return true;
        }

        return \App\Models\Brigade::where('foreman_id', $user->id)->where('is_active', true)->exists();
    }

    private function scope($query, Request $request)
    {
        $user = $request->user();
        if ($user->hasRole('manager') && ! $user->hasAnyRole(['admin', 'director', 'financist'])) {
            $uid = $user->id;

            return $query->where(fn ($w) => $w
                ->where('responsible_user_id', $uid)
                ->orWhereHas('deal', fn ($d) => $d->where('responsible_user_id', $uid)));
        }

        return $query;
    }

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Project::class);

        $view = $request->string('view', 'kanban')->toString();

        $base = Project::query()
            // cancelled = заказ отменён (в т.ч. каскадом при удалении сделки).
            ->whereNotIn('status', ['completed', 'cancelled'])
            // Цех тоже разделён по фирмам: заказ принадлежит компании исходной сделки.
            ->when(\App\Support\CurrentCompany::id(), fn ($q, $c) => $q->whereHas('deal', fn ($d) => $d->where('company_id', $c)))
            // Цеху на карточке нужны срок, описание, заметка и адрес (город) из сделки.
            // foreman_id + deal.foreman: на доске видно, чья бригада ведёт заказ.
            ->with(['client:id,name', 'responsible:id,name,avatar', 'stage:id,name,color,order', 'deal:id,number,company_name,client_name,address,deadline,description,note,foreman_id', 'deal.foreman:id,name'])
            ->withCount(['tasks as overdue_count' => fn ($q) => $q->where('status', '!=', 'done')->whereNotNull('due_date')->where('due_date', '<', now())])
            // Тайминг: когда заказ вошёл на текущий этап (открытый лог).
            ->addSelect(['stage_entered_at' => \App\Models\ProjectStageLog::select('entered_at')
                ->whereColumn('project_id', 'projects.id')->whereNull('left_at')
                ->latest('entered_at')->limit(1)]);
        $this->scope($base, $request);
        // Доступ по цехам: сотрудник с ограничением видит только свои цеха
        // (заказы без указанного цеха видны всем).
        $userWorkshops = $request->user()->workshops ?? [];
        if (! empty($userWorkshops)) {
            $base->where(fn ($w) => $w->whereNull('workshop')->orWhereIn('workshop', $userWorkshops));
        }
        // Поиск во вложенной скобке — иначе orWhere «вырывается» из скоупа
        // компании/владельца и показал бы чужие заказы (утечка между фирмами).
        $base->when($request->string('search')->toString(), fn ($q, $s) => $q
            ->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('number', 'like', "%{$s}%")));

        // Канбан показывает воронку цеха ТЕКУЩЕЙ фирмы;
        // в режиме «Все компании» — этапы всех цехов С
        // ПОМЕТКОЙ фирмы (иначе одинаковые «Формовка» выглядят как дубли).
        // companyQuery: свои этапы приоритетны, «общие» (null) — только фолбэк.
        $companyId = \App\Support\CurrentCompany::id() ?: null;
        $companyCodes = \App\Models\Company::pluck('code', 'id');
        $stages = ProjectStage::companyQuery($companyId)
            ->with('translations')->get()
            // Секции чужих цехов скрываем у сотрудников с ограничением.
            ->filter(fn ($s) => empty($userWorkshops) || $s->workshop === null || in_array($s->workshop, $userWorkshops, true))
            ->values()
            ->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->translatedName().(! $companyId && $s->company_id ? ' · '.($companyCodes[$s->company_id] ?? '') : ''),
                'color' => $s->color, 'order' => $s->order, 'is_completed' => $s->is_completed,
                // Цех — канбан рисует отдельную секцию на каждый.
                'workshop' => $s->workshop,
            ]);

        $projects = $view === 'list'
            ? (clone $base)->latest()->paginate(20)->withQueryString()
            : (clone $base)->latest()->get();

        // Цех не видит суммы — прячем budget из сериализуемой модели, а не только в UI.
        $canSeeMoney = $this->canSeeMoney($request);
        if (! $canSeeMoney) {
            ($projects instanceof \Illuminate\Pagination\AbstractPaginator ? $projects->getCollection() : $projects)
                ->transform(fn ($p) => $p->makeHidden('budget'));
        }

        return Inertia::render('Projects/Index', [
            'projects' => $projects,
            'stages' => $stages,
            'view' => $view,
            'filters' => $request->only('search'),
            'canSeeMoney' => $canSeeMoney,
        ]);
    }

    public function show(Project $project, FinanceService $finance, Request $request, ProductionProgressService $progress): Response
    {
        $this->authorize('view', $project);
        $this->assertWorkshopAccess($project);

        $canSeeMoney = $this->canSeeMoney($request);

        // Цены позиций — это деньги: цеху их не выбираем вовсе. Что делать и
        // сколько — выбираем всегда: без этого карточка бесполезна.
        $itemColumns = ['id', 'deal_id', 'name', 'unit', 'quantity', 'sort'];
        if ($canSeeMoney) {
            $itemColumns[] = 'price';
            $itemColumns[] = 'amount';
        }

        $project->load([
            'client', 'responsible:id,name,avatar', 'department:id,name',
            // company_id ОБЯЗАТЕЛЕН в select: по нему фильтруется воронка цеха
            // ниже — без него грузились обе фирмы (Формовка+Формовка в степпере).
            // Остальные поля сделки — то, ради чего цех открывает карточку:
            // что делать, для кого, куда везти, к какому сроку и кто ведёт.
            // budget в select не входит: суммы сделки в цехе нет ни у кого.
            'stage', 'deal:id,number,name,company_name,company_id,client_name,address,lot_number,unit,contract_date,deadline,description,note,responsible_user_id,foreman_id',
            'deal.responsible:id,name,avatar',
            'deal.foreman:id,name,avatar',
            'deal.items' => fn ($q) => $q->select(array_merge($itemColumns, ['finished_at', 'finished_by']))
                ->with('finisher:id,name')
                // Фото каждой позиции: по ним в цехе сверяют отливку.
                ->with(['documents' => fn ($d) => $d->where('is_active', true)->with('user:id,name')->latest()]),
            // Фото объекта менеджер снимает в сделке, а нужны они в цехе.
            // Одна карточка — все снимки заказа, чьи бы они ни были.
            'deal.documents' => fn ($q) => $q->where('is_active', true)->with('user:id,name')->latest(),
            'tasks' => fn ($q) => $q->with('assignee:id,name')->latest(),
            'documents' => fn ($q) => $q->where('is_active', true)->with('user:id,name')->latest(),
            'comments' => fn ($q) => $q->with('user:id,name')->latest(),
        ]);

        if (! $canSeeMoney) {
            $project->makeHidden('budget');
        }

        // Finance & history follow the originating deal so the whole lifecycle
        // (sale → production) is one continuous picture for the manager.
        $source = $project->deal_id
            ? Deal::with([
                'invoices' => fn ($q) => $q->withSum('payments as payments_sum_amount', 'amount')->with('payments')->latest(),
                'expenses' => fn ($q) => $q->with('responsible:id,name,avatar')->latest(),
            ])->find($project->deal_id)
            : null;
        $source ??= $project->load([
            'invoices' => fn ($q) => $q->withSum('payments as payments_sum_amount', 'amount')->with('payments')->latest(),
            'expenses' => fn ($q) => $q->with('responsible:id,name,avatar')->latest(),
        ]);

        // Merge audit history of the project and its deal.
        $history = AuditFormatter::humanize(
            AuditLog::query()
                ->where(function ($q) use ($project) {
                    $q->where(fn ($w) => $w->where('table_name', 'projects')->where('record_id', $project->id));
                    if ($project->deal_id) {
                        $q->orWhere(fn ($w) => $w->where('table_name', 'deals')->where('record_id', $project->deal_id));
                    }
                })
                ->with('user:id,name')->latest()->limit(150)->get(),
            [
                'project_stage_id' => ProjectStage::pluck('name', 'id'),
                'deal_stage_id' => DealStage::pluck('name', 'id'),
                'responsible_user_id' => User::pluck('name', 'id'),
            ]
        );

        // Цех не должен видеть изменения суммы сделки в истории.
        if (! $canSeeMoney) {
            $history = $history->reject(fn ($log) => $log->field_name === 'budget')->values();
        }

        return Inertia::render('Projects/Show', [
            'project' => $project,
            'users' => User::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            // Остатки касса/банк — бухгалтеру в форме расхода («доступно N»).
            'balances' => $request->user()->hasAnyRole(['admin', 'financist'])
                ? $finance->companyBalances($project->deal?->company_id ? (int) $project->deal->company_id : null)
                : null,
            // Этапы цеха компании этого заказа (по исходной сделке); свои
            // приоритетны, «общие» — фолбэк (иначе степпер двоит Формовка+Формовка…).
            'stages' => ProjectStage::companyQuery($project->deal?->company_id ? (int) $project->deal->company_id : null, $project->workshop)
                ->with('translations')->get()
                ->map(fn ($s) => ['id' => $s->id, 'name' => $s->translatedName(), 'color' => $s->color, 'order' => $s->order, 'is_completed' => $s->is_completed]),
            'finance' => $canSeeMoney ? $finance->summaryFor($source) : null,
            'financeEntityType' => $project->deal_id ? 'deal' : 'project',
            'financeEntityId' => $source->id,
            'financeInvoices' => $canSeeMoney ? $source->invoices : [],
            'financeExpenses' => $canSeeMoney ? $source->expenses : [],
            'canSeeMoney' => $canSeeMoney,
            // Переход в саму сделку — только тем, кого туда пустит DealPolicy:
            // назначенному бригадиру да, стороннему цеховому работнику нет.
            // Ссылка, ведущая в 403, хуже отсутствующей.
            'canOpenDeal' => $project->deal !== null && $request->user()->can('view', $project->deal),
            // Сколько по каждой позиции сделано: тот же счёт, что в сделке и
            // на производстве. Бригадир видит остаток, не уходя со страницы.
            'itemProgress' => $progress->forItems($project->deal?->items ?? []),
            // Кто может записывать работу по позиции: бригадир своей бригады
            // в этом цехе и руководство. Кнопки, которые отобьёт сервер,
            // рисовать нельзя — они учат не доверять интерфейсу.
            'canReport' => $this->canReport($request, $project),
            // Незакрытые позиции держат заказ в цехе — кнопку «Готово»
            // показываем, но говорим, чего не хватает.
            'unfinishedItems' => (int) ($project->deal?->items()->whereNull('finished_at')->count() ?? 0),
            'history' => $history,
            // Тайминг этапов: сколько заказ провёл на каждом (открытый — тикает).
            'stageLogs' => $project->stageLogs()->orderBy('entered_at')->orderBy('id')->get()
                ->map(fn ($l) => [
                    'stage' => $l->stage_name,
                    'entered_at' => $l->entered_at->toIso8601String(),
                    'left_at' => $l->left_at?->toIso8601String(),
                    'seconds' => $l->left_at ? (int) $l->duration_seconds : (int) abs(now()->diffInSeconds($l->entered_at)),
                    'open' => $l->left_at === null,
                ]),
        ]);
    }

    public function updateStage(Request $request, Project $project): RedirectResponse
    {
        // Право на просмотр здесь намеренно: доской цеха пользуются
        // сотрудники, у которых нет project.update (employee, технолог,
        // снабженец, повар, юрист). Правило — «видишь доску своего цеха,
        // значит двигаешь карточки в ней»; изоляцию цеха держит
        // assertWorkshopAccess ниже. Поднимать до update можно только вместе
        // с выдачей этого права цеховым ролям — это решение владельца.
        $this->authorize('view', $project);
        $this->assertWorkshopAccess($project);

        $validated = $request->validate(['project_stage_id' => ['required', 'exists:project_stages,id']]);
        $stage = ProjectStage::findOrFail($validated['project_stage_id']);
        // Изоляция цехов: этап чужой фирмы недоступен.
        $companyId = $project->deal?->company_id ? (int) $project->deal->company_id : null;
        if ($stage->company_id && (int) $stage->company_id !== $companyId) {
            return back()->with('error', 'Этап принадлежит цеху другой компании.');
        }
        // Перенос на этап (включая «Отправку») — ТОЛЬКО смена этапа. Завершение
        // заказа и возврат сделки на «Логистику» — ТОЛЬКО кнопкой «Готово»
        // (projects.toAct), а не автоматом при перетаскивании.
        $project->project_stage_id = $stage->id;
        $project->save();

        return back()->with('success', 'Этап проекта обновлён.');
    }

    public function advance(Project $project): RedirectResponse
    {
        $this->authorize('view', $project);
        $this->assertWorkshopAccess($project);
        // «Далее» — по ПОЗИЦИИ в воронке цеха своей компании (не по order >
        // current): при задвоенном order соседний этап не перескакивается.
        $funnel = ProjectStage::funnel($project->deal?->company_id ? (int) $project->deal->company_id : null, $project->workshop)->values();
        $idx = $funnel->search(fn ($s) => $s->id === $project->project_stage_id);
        $next = $idx !== false ? $funnel->get($idx + 1) : $funnel->first();
        if (! $next) {
            return back()->with('error', 'Это последний этап.');
        }
        // «Далее» доводит только ДО последнего этапа; завершение — кнопкой «Готово».
        $project->project_stage_id = $next->id;
        $project->save();

        return back()->with('success', 'Цех: этап «'.$next->name.'».');
    }

    /**
     * Workshop "Готово": from the last workshop stage («Отправка»), send the
     * order back to the Deals board and close the workshop project.
     */
    public function sendToAct(Project $project): RedirectResponse
    {
        $this->authorize('view', $project);
        $this->assertWorkshopAccess($project);

        return $this->completeAndReturnDeal($project);
    }

    /**
     * Завершить заказ цеха и вернуть исходную сделку на «Логистику» (воронка
     * компании сделки); дальше менеджер двигает Логистика → Сборка → Акт.
     */
    private function completeAndReturnDeal(Project $project): RedirectResponse
    {
        // Единая логика с «Готово» на ТВ-экране цеха (ProjectService).
        [$ok, $message] = app(\App\Services\ProjectService::class)->completeAndReturnDeal($project);

        return back()->with($ok ? 'success' : 'error', $message);
    }
}
