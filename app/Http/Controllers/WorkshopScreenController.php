<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\DealStageLog;
use App\Models\Project;
use App\Models\ProjectStage;
use App\Models\ProjectStageLog;
use App\Models\Setting;
use App\Models\User;
use App\Models\WorkshopScreen;
use App\Services\ProjectService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ТВ-экран цеха (без логина): вход по коду из админки. Один код = один цех —
 * чужой цех с этого экрана не открыть. Денег на экране нет.
 */
class WorkshopScreenController extends Controller
{
    public function show(Request $request): Response
    {
        $screen = WorkshopScreen::with('company:id,name,code')
            ->where('is_active', true)->find($request->session()->get('workshop_screen_id'));
        // Код сверяем при каждом показе: «Новый код» в админке отключает
        // все экраны, вошедшие по старому коду.
        if ($screen && $screen->code !== $request->session()->get('workshop_screen_code')) {
            $screen = null;
        }
        if (! $screen) {
            $request->session()->forget('workshop_screen_id');

            return Inertia::render('Screen/Enter');
        }

        if ($screen->kind === 'office') {
            return $this->office($screen);
        }

        $companyId = $screen->company_id ? (int) $screen->company_id : null;
        $stages = ProjectStage::companyQuery($companyId, $screen->workshop)
            ->with('translations')->get()
            ->map(fn ($s) => ['id' => $s->id, 'name' => $s->translatedName(), 'color' => $s->color, 'is_completed' => $s->is_completed]);

        $projects = Project::query()
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->when($companyId, fn ($q, $c) => $q->whereHas('deal', fn ($d) => $d->where('company_id', $c)))
            ->when($screen->workshop, fn ($q, $w) => $q->where('workshop', $w))
            ->with(['stage:id,name', 'deal:id,number,company_name,address,deadline,description,note'])
            ->addSelect(['stage_entered_at' => ProjectStageLog::select('entered_at')
                ->whereColumn('project_id', 'projects.id')->whereNull('left_at')
                ->latest('entered_at')->limit(1)])
            ->latest()->get()
            ->map(fn ($p) => [
                'id' => $p->id, 'number' => $p->number,
                'name' => $p->deal?->company_name ?: $p->name,
                'stage_id' => $p->project_stage_id,
                'address' => $p->deal?->address,
                'deadline' => optional($p->deal?->deadline ?? $p->deadline)->toDateString(),
                'overdue' => ($p->deal?->deadline ?? $p->deadline)?->isPast() ?? false,
                'description' => $p->deal?->description,
                'note' => $p->deal?->note,
                'stage_entered_at' => $p->stage_entered_at,
            ]);

        return Inertia::render('Screen/Workshop', [
            'screen' => ['workshop' => $screen->workshop, 'company' => $screen->company?->name],
            'stages' => $stages,
            'projects' => $projects,
        ]);
    }

    /**
     * Экран «Офис»: воронка отдела и рейтинг менеджеров по СДЕЛКАМ.
     *
     * Раньше экран считал заявки и их чек-лист. Заявок больше нет, и считать
     * стало нечего — воронка переехала на настоящие этапы сделки: сколько
     * сделок месяца ДОШЛО до «Договора», до «Цеха», до «Оплаты успешно».
     *
     * «Дошло» берём из `deal_stage_logs`, а не из текущего этапа: сделка,
     * стоящая на «Оплате», прошла и «Договор», и «Цех», — считай по текущему
     * этапу, и все ранние ступени воронки оказались бы пустыми.
     *
     * Денег на экране нет: он висит на стене, мимо ходят все.
     * Фильтр месяца (?month=YYYY-MM) — кто был лучшим в любом месяце.
     */
    private function office(WorkshopScreen $screen): Response
    {
        $companyId = $screen->company_id ? (int) $screen->company_id : null;
        $plan = max(1, (int) Setting::get('sales_plan_monthly', 20));
        $month = preg_match('/^\d{4}-\d{2}$/', (string) request()->query('month'))
            ? request()->query('month') : now()->format('Y-m');
        $mStart = $month.'-01';
        $mEnd = Carbon::parse($mStart)->endOfMonth()->toDateString();

        // Ступени воронки = этапы фирмы (плюс общие) в их порядке. Тот же
        // набор, что видит менеджер в канбане: разойдись они, экран показывал
        // бы воронку, которой в системе нет.
        $stages = DealStage::funnel($companyId);
        $wonStageIds = $stages->where('is_won', true)->pluck('id');

        // Сделки месяца — по дате СОЗДАНИЯ: экран отвечает на вопрос «сколько
        // завели в этом месяце и что с ними стало».
        $deals = Deal::query()
            ->when($companyId, fn ($q, $c) => $q->where('company_id', $c))
            ->whereDate('created_at', '>=', $mStart)->whereDate('created_at', '<=', $mEnd)
            ->with('responsible:id,name')
            ->latest()
            ->get(['id', 'number', 'responsible_user_id', 'company_name', 'client_name', 'deal_stage_id', 'status', 'created_at']);

        // Какие этапы каждая сделка уже проходила: id сделки → набор этапов.
        $reached = DealStageLog::whereIn('deal_id', $deals->pluck('id'))
            ->get(['deal_id', 'deal_stage_id'])
            ->groupBy('deal_id')
            ->map(fn ($rows) => $rows->pluck('deal_stage_id')->filter()->unique()->all());

        // Текущий этап тоже считается пройденным: у сделок, заведённых до
        // появления журнала, лога может не быть вовсе.
        $passed = function (Deal $d, int $stageId) use ($reached): bool {
            return $d->deal_stage_id === $stageId || in_array($stageId, $reached[$d->id] ?? [], true);
        };
        $isWon = fn (Deal $d) => $wonStageIds->contains($d->deal_stage_id);

        // Воронка по набору сделок: Сделки → каждый этап → выигранные.
        $funnelFor = function ($rows) use ($stages, $passed, $isWon, $plan) {
            return collect([[
                'label' => 'Сделки', 'count' => $rows->count(), 'plan' => $plan, 'kind' => 'start',
            ]])->concat($stages->where('is_won', false)->map(fn ($st) => [
                'label' => $st->name,
                'count' => $rows->filter(fn ($d) => $passed($d, $st->id))->count(),
                'plan' => $plan,
                'kind' => 'step',
            ]))->push([
                'label' => 'Оплата успешно',
                'count' => $rows->filter($isWon)->count(),
                'plan' => max(1, (int) Setting::get('sales_plan_won_monthly', 20)),
                'kind' => 'won',
            ])->values();
        };

        $byUser = $deals->groupBy('responsible_user_id');
        $emptyFunnel = $funnelFor(collect());

        // Список сделок на экран (последние 40): сделка · менеджер · этап.
        $dealRows = $deals->take(40)->map(fn ($d) => [
            'manager' => $d->responsible?->name ?? '—',
            'number' => $d->number,
            'product' => $d->client_name,
            'customer' => $d->company_name,
            'stage' => $stages->firstWhere('id', $d->deal_stage_id)?->name ?? '—',
            'won' => $isWon($d),
        ])->values();

        $managers = User::role('manager')->where('is_active', true)->get(['id', 'name', 'avatar'])
            ->map(function (User $u) use ($byUser, $plan, $isWon, $funnelFor, $emptyFunnel) {
                $rows = $byUser[$u->id] ?? collect();
                $total = $rows->count();
                $won = $rows->filter($isWon)->count();

                return [
                    'name' => $u->name, 'avatar' => $u->avatar,
                    'total' => $total,                          // сделок завёл
                    'won' => $won,                              // из них оплачены
                    'deals' => $total,                          // сделка и есть сделка
                    'conversion' => $total > 0 ? (int) round($won / $total * 100) : 0,
                    'plan_pct' => min(100, (int) round($total / $plan * 100)),
                    // Персональная воронка: сделки → этапы → оплата успешно.
                    'funnel' => $total > 0 ? $funnelFor($rows) : $emptyFunnel,
                ];
            })
            ->sortBy([['won', 'desc'], ['conversion', 'desc'], ['total', 'desc']])->values();

        return Inertia::render('Screen/Office', [
            'screen' => ['company' => $screen->company?->name],
            'plan' => $plan,
            'month' => $month,
            'monthLabel' => Carbon::parse($mStart)->locale('ru')->translatedFormat('F Y'),
            'managers' => $managers,
            'leader' => $managers->first(),
            // Сделки месяца — видно, кто что завёл и где оно стоит.
            'lots' => $dealRows,
            // Воронка отдела КРУПНО: Сделки → этапы воронки → Оплата успешно.
            'funnel' => $funnelFor($deals),
        ]);
    }

    /** План сделок на месяц для экрана «Офис» — ставит админ или финансист. */
    public function plan(Request $request): RedirectResponse
    {
        abort_unless($request->user()->hasAnyRole(['admin', 'financist']) || $request->user()->can('setting.update'), 403);
        $data = $request->validate([
            'plan' => ['required', 'integer', 'min:1', 'max:1000'],
            // Отдельный план ВЫИГРАННЫХ сделок (воронка на экране «Офис»).
            'plan_won' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);
        Setting::set('sales_plan_monthly', $data['plan']);
        if (! empty($data['plan_won'])) {
            Setting::set('sales_plan_won_monthly', $data['plan_won']);
        }

        return back()->with('success', 'План на месяц: заявок '.$data['plan'].(! empty($data['plan_won']) ? ', подтверждённых '.$data['plan_won'] : '').'.');
    }

    public function enter(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:20']]);
        $screen = WorkshopScreen::where('code', trim($data['code']))->where('is_active', true)->first();
        if (! $screen) {
            return back()->withErrors(['code' => 'Неверный код. Проверьте код экрана у администратора.']);
        }
        $request->session()->put('workshop_screen_id', $screen->id);
        $request->session()->put('workshop_screen_code', $screen->code);

        return redirect()->route('screen.show');
    }

    public function leave(Request $request): RedirectResponse
    {
        $request->session()->forget('workshop_screen_id');

        return redirect()->route('screen.show');
    }

    /**
     * «Далее» с ТВ-экрана: работник цеха двигает заказ на следующий этап
     * прямо с экрана. Доступ — по коду экрана в сессии (как и показ);
     * заказ обязан принадлежать фирме и цеху ЭТОГО экрана.
     */
    /** Гард действий с экрана: активный код в сессии + заказ своего цеха/фирмы. */
    private function screenForAction(Request $request, Project $project): WorkshopScreen
    {
        $screen = WorkshopScreen::where('is_active', true)->find($request->session()->get('workshop_screen_id'));
        if (! $screen || $screen->code !== $request->session()->get('workshop_screen_code') || $screen->kind === 'office') {
            abort(403);
        }
        abort_unless($project->workshop === $screen->workshop, 403, 'Заказ другого цеха.');
        abort_if($screen->company_id && (int) ($project->deal?->company_id) !== (int) $screen->company_id, 403, 'Заказ другой фирмы.');
        abort_if(in_array($project->status, ['completed', 'cancelled'], true), 403);

        return $screen;
    }

    public function advanceProject(Request $request, Project $project): RedirectResponse
    {
        $screen = $this->screenForAction($request, $project);

        // «Далее» — по позиции в воронке цеха (как в ERP); с последнего
        // этапа дальше не двигаем — там кнопка «Готово» (completeProject).
        $funnel = ProjectStage::funnel($screen->company_id ? (int) $screen->company_id : null, $project->workshop)->values();
        $idx = $funnel->search(fn ($s) => $s->id === $project->project_stage_id);
        $next = $idx !== false ? $funnel->get($idx + 1) : $funnel->first();
        if (! $next) {
            return back()->with('error', 'Это последний этап — нажмите «Готово».');
        }
        $project->project_stage_id = $next->id;
        $project->save();

        return back()->with('success', 'Этап: «'.$next->name.'».');
    }

    /**
     * «Готово» с ТВ-экрана: доступно ТОЛЬКО на последнем этапе воронки цеха
     * («Отправка») — заказ завершается, сделка возвращается на «Логистику».
     */
    public function completeProject(Request $request, Project $project, ProjectService $projects): RedirectResponse
    {
        $screen = $this->screenForAction($request, $project);

        $funnel = ProjectStage::funnel($screen->company_id ? (int) $screen->company_id : null, $project->workshop)->values();
        abort_unless($funnel->isNotEmpty() && $funnel->last()->id === $project->project_stage_id, 403,
            '«Готово» доступно только на последнем этапе.');

        [$ok, $message] = $projects->completeAndReturnDeal($project);

        return back()->with($ok ? 'success' : 'error', $message);
    }

    /** Настройки → Экраны: все цеха всех компаний, коды и статусы. */
    public function admin(Request $request): Response
    {
        $this->guardAdmin($request);

        $screens = WorkshopScreen::get()->keyBy(fn ($s) => ($s->company_id ?? 0).'|'.($s->workshop ?? '').'|'.$s->kind);
        $companies = Company::orderBy('id')->get(['id', 'name'])->map(function ($c) use ($screens) {
            // Та же выборка, что и на канбане цеха (ProjectStage::companyQuery):
            // свои этапы фирмы, а если их нет — общие. Иначе на свежей базе
            // «Экраны» показывали «Единый цех», хотя цехов несколько.
            $rows = collect(ProjectStage::workshopsFor($c->id))
                ->map(fn ($w) => ['workshop' => $w, 'label' => $w]);
            $hasUnassigned = ProjectStage::companyQuery($c->id)->whereNull('workshop')->exists();
            if ($rows->isEmpty() || $hasUnassigned) {
                $rows->push(['workshop' => null, 'label' => 'Единый цех']);
            }

            return [
                'id' => $c->id, 'name' => $c->name,
                'rows' => $rows->map(fn ($r) => $r + [
                    'screen' => ($sc = $screens->get($c->id.'|'.($r['workshop'] ?? '').'|workshop'))
                        ? ['id' => $sc->id, 'code' => $sc->code, 'is_active' => $sc->is_active] : null,
                ])->values(),
                'office' => ($sc = $screens->get($c->id.'||office'))
                    ? ['id' => $sc->id, 'code' => $sc->code, 'is_active' => $sc->is_active] : null,
            ];
        });

        return Inertia::render('Settings/Screens', [
            'companies' => $companies,
            'salesPlan' => (int) Setting::get('sales_plan_monthly', 20),
            'salesPlanWon' => (int) Setting::get('sales_plan_won_monthly', 20),
        ]);
    }

    /** Включить/выключить экран (код перестаёт работать сразу). */
    public function toggle(Request $request, WorkshopScreen $screen): RedirectResponse
    {
        $this->guardAdmin($request);
        $screen->update(['is_active' => ! $screen->is_active]);

        return back()->with('success', $screen->is_active ? 'Экран включён.' : 'Экран отключён.');
    }

    private function guardAdmin(Request $request): void
    {
        abort_unless($request->user()->hasRole('admin') || $request->user()->can('setting.update'), 403);
    }

    /** Админка: выдать/перегенерировать код экрана цеха. */
    public function upsert(Request $request): RedirectResponse
    {
        $this->guardAdmin($request);
        $data = $request->validate([
            'company_id' => ['nullable', 'exists:companies,id'],
            'workshop' => ['nullable', 'string', 'max:100'],
            'kind' => ['nullable', Rule::in(['workshop', 'office'])],
        ]);

        WorkshopScreen::updateOrCreate(
            ['company_id' => $data['company_id'] ?? null, 'workshop' => $data['workshop'] ?? null, 'kind' => $data['kind'] ?? 'workshop'],
            ['code' => WorkshopScreen::freshCode(), 'is_active' => true]
        );

        return back()->with('success', 'Код экрана обновлён.');
    }
}
