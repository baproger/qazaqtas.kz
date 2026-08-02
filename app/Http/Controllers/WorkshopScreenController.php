<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectStage;
use App\Models\WorkshopScreen;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
            ->addSelect(['stage_entered_at' => \App\Models\ProjectStageLog::select('entered_at')
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
     * Экран «Офис»: лидер — по ЭФФЕКТИВНОСТИ (принесённая компании прибыль
     * за месяц по won-сделкам, та же формула, что в ЗП), а не по числу сделок.
     * Денег на экране нет — только баллы (0–100 от лучшего), маржа %, штуки.
     * Фильтр месяца (?month=YYYY-MM) — кто был лучшим в любом месяце.
     */
    private function office(WorkshopScreen $screen): Response
    {
        $companyId = $screen->company_id ? (int) $screen->company_id : null;
        $plan = max(1, (int) \App\Models\Setting::get('sales_plan_monthly', 20));
        $month = preg_match('/^\d{4}-\d{2}$/', (string) request()->query('month'))
            ? request()->query('month') : now()->format('Y-m');
        $mStart = $month.'-01';
        $mEnd = \Illuminate\Support\Carbon::parse($mStart)->endOfMonth()->toDateString();
        // Рейтинг по ЗАЯВКАМ за месяц: сколько заявок
        // менеджер добавил, сколько перевёл в сделки (кнопка «В работу» → создана
        // сделка) и конверсия %. Деньги на экране не показываются.
        $lots = \App\Models\PreDeal::query()
            ->when($companyId, fn ($q, $c) => $q->where('company_id', $c))
            ->whereDate('created_at', '>=', $mStart)->whereDate('created_at', '<=', $mEnd)
            ->groupBy('user_id')
            ->selectRaw("user_id, count(*) total,
                sum(case when status = 'confirmed' then 1 else 0 end) won,
                sum(case when status = 'confirmed' and deal_id is not null then 1 else 0 end) deals")
            ->get()->keyBy('user_id');

        // Чек-листы заявок («Позвонил клиенту», «Сделал замер»…): видно, работает
        // ли менеджер по заявке. checks = {itemId: true} на каждой заяве.
        $checkItemsList = \App\Models\PreDealChecklistItem::where('is_active', true)
            ->orderBy('order')->get(['id', 'label']);
        $checkItems = $checkItemsList->count();
        $monthLots = \App\Models\PreDeal::query()
            ->when($companyId, fn ($q, $c) => $q->where('company_id', $c))
            ->whereDate('created_at', '>=', $mStart)->whereDate('created_at', '<=', $mEnd)
            ->with('user:id,name')
            ->latest()
            ->get(['id', 'user_id', 'product', 'customer', 'margin', 'status', 'checks', 'created_at']);
        $checksDone = fn ($p) => count(array_filter($p->checks ?? []));
        $checksByUser = $monthLots->groupBy('user_id')->map(fn ($rows) => $rows->sum($checksDone));
        // Персональная воронка менеджера: заявки → каждый пункт чек-листа → в работе.
        $funnelFor = function ($rows) use ($checkItemsList) {
            return collect([['label' => 'Заявки', 'count' => $rows->count(), 'kind' => 'start']])
                ->concat($checkItemsList->map(fn ($it) => [
                    'label' => trim(str_ireplace('через WhatsApp', '', $it->label)),
                    'count' => $rows->filter(fn ($p) => ! empty(($p->checks ?? [])[(string) $it->id]))->count(),
                    'kind' => 'step',
                ]))
                ->push(['label' => 'В работе', 'count' => $rows->where('status', 'confirmed')->count(), 'kind' => 'won'])
                ->values();
        };
        $funnelByUser = $monthLots->groupBy('user_id')->map($funnelFor);
        $emptyFunnel = $funnelFor(collect());
        // Список заявок на экран (последние 40): заявка · менеджер · чек-лист · статус.
        $lotRows = $monthLots->take(40)->map(fn ($p) => [
            'manager' => $p->user?->name ?? '—',
            'product' => $p->product,
            'customer' => $p->customer,
            'won' => $p->status === 'confirmed',
            'checks_done' => min($checksDone($p), $checkItems),
            'checks_total' => $checkItems,
        ])->values();

        $managers = \App\Models\User::role('manager')->where('is_active', true)->get(['id', 'name', 'avatar'])
            ->map(function (\App\Models\User $u) use ($lots, $plan, $checksByUser, $checkItems, $funnelByUser, $emptyFunnel) {
                $m = $lots[$u->id] ?? null;
                $total = (int) ($m->total ?? 0);
                $won = (int) ($m->won ?? 0);

                return [
                    'name' => $u->name, 'avatar' => $u->avatar,
                    'total' => $total,                          // заявок добавил
                    'won' => $won,                              // переведено в сделки
                    'deals' => (int) ($m->deals ?? 0),          // из них стало сделками
                    'conversion' => $total > 0 ? (int) round($won / $total * 100) : 0,
                    'plan_pct' => min(100, (int) round($total / $plan * 100)),
                    // Чек-лист: сделано галочек / всего возможных (заявки × пункты).
                    'checks_done' => (int) ($checksByUser[$u->id] ?? 0),
                    'checks_total' => $total * $checkItems,
                    // Персональная воронка: заявки → звонок/замер/КП… → в работе.
                    'funnel' => $funnelByUser[$u->id] ?? $emptyFunnel,
                ];
            })
            ->sortBy([['won', 'desc'], ['conversion', 'desc'], ['total', 'desc']])->values();

        return Inertia::render('Screen/Office', [
            'screen' => ['company' => $screen->company?->name],
            'plan' => $plan,
            'month' => $month,
            'monthLabel' => \Illuminate\Support\Carbon::parse($mStart)->locale('ru')->translatedFormat('F Y'),
            'managers' => $managers,
            'leader' => $managers->first(),
            // Заявки месяца с чек-листами — видно, кто реально работает по заявкам.
            'lots' => $lotRows,
            // Воронка отдела КРУПНО: Заявки → этапы чек-листа
            // (Звонок, КП… — любые пункты из «⚙ Чек-лист») → Выигранные.
            // План заявок и чек-листа общий (каждая заявка проходит каждый шаг),
            // у подтверждённых — свой план (sales_plan_won_monthly).
            'funnel' => collect([[
                'label' => 'Заявки',
                'count' => $monthLots->count(),
                'plan' => $plan,
                'kind' => 'start',
            ]])->concat($checkItemsList->map(fn ($it) => [
                'label' => trim(str_ireplace('через WhatsApp', '', $it->label)),
                'count' => $monthLots->filter(fn ($p) => ! empty(($p->checks ?? [])[(string) $it->id]))->count(),
                'plan' => $plan,
                'kind' => 'step',
            ]))->push([
                'label' => 'Выигранные сделки',
                'count' => $monthLots->where('status', 'confirmed')->count(),
                'plan' => max(1, (int) \App\Models\Setting::get('sales_plan_won_monthly', 20)),
                'kind' => 'won',
            ])->values(),
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
        \App\Models\Setting::set('sales_plan_monthly', $data['plan']);
        if (! empty($data['plan_won'])) {
            \App\Models\Setting::set('sales_plan_won_monthly', $data['plan_won']);
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
    public function completeProject(Request $request, Project $project, \App\Services\ProjectService $projects): RedirectResponse
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
        $companies = \App\Models\Company::orderBy('id')->get(['id', 'name'])->map(function ($c) use ($screens) {
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
            'salesPlan' => (int) \App\Models\Setting::get('sales_plan_monthly', 20),
            'salesPlanWon' => (int) \App\Models\Setting::get('sales_plan_won_monthly', 20),
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
            'kind' => ['nullable', \Illuminate\Validation\Rule::in(['workshop', 'office'])],
        ]);

        WorkshopScreen::updateOrCreate(
            ['company_id' => $data['company_id'] ?? null, 'workshop' => $data['workshop'] ?? null, 'kind' => $data['kind'] ?? 'workshop'],
            ['code' => WorkshopScreen::freshCode(), 'is_active' => true]
        );

        return back()->with('success', 'Код экрана обновлён.');
    }
}
