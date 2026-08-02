<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\Project;
use App\Models\ProjectStage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Настройки → Этапы: управление воронками компаний (BAIA/ASU) и общей
 * воронкой цеха. Спец-логика этапов держится на stage_type (не на названии);
 * гейт-задачи (текст/роль/срок) настраиваются на этапе.
 */
class StageController extends Controller
{
    private function guard(Request $request): void
    {
        abort_unless($request->user()->hasRole('admin') || $request->user()->can('setting.update'), 403);
    }

    private function model(string $kind): string
    {
        return $kind === 'project' ? ProjectStage::class : DealStage::class;
    }

    /** Воронка выбирается на странице: company=<id> (сделки) — не зависит от шапки. */
    private function funnelCompanyId(Request $request): ?int
    {
        $id = (int) $request->query('company', 0);
        if ($id && Company::whereKey($id)->exists()) {
            return $id;
        }

        return \App\Support\CurrentCompany::id() ?: (int) Company::orderBy('id')->value('id');
    }

    public function index(Request $request): Response
    {
        $this->guard($request);

        $companyId = $this->funnelCompanyId($request);

        // Авто-починка порядка: если order задвоился (например после переноса
        // данных), перенумеровываем воронку 1..N по (order, id). Идемпотентно.
        $this->reindexFunnel(DealStage::class, $companyId);
        $this->reindexFunnel(ProjectStage::class, $companyId);

        $dealStages = DealStage::query()
            ->withCount(['deals as active_deals_count' => fn ($q) => $q->whereNotIn('status', ['closed', 'cancelled'])])
            ->where(fn ($w) => $w->where('company_id', $companyId)->orWhereNull('company_id'))
            ->orderBy('order')->get();

        return Inertia::render('Settings/Stages', [
            'dealStages' => $dealStages,
            // Цех у каждой компании свой (BAIA — мебельный, ASU — швейный).
            'projectStages' => ProjectStage::withCount('projects')
                ->where(fn ($w) => $w->where('company_id', $companyId)->orWhereNull('company_id'))
                ->orderBy('order')->get(),
            'companies' => Company::orderBy('id')->get(['id', 'name']),
            'selectedCompanyId' => $companyId,
            'stageTypes' => DealStage::STAGE_TYPES,
            'gateRoles' => ['financist' => 'Бухгалтер', 'designer' => 'Дизайнер', 'supplier' => 'Снабженец', 'manager' => 'Менеджер', 'director' => 'Директор', 'admin' => 'Админ'],
            // Обязательные типы: без payment_won не работает подсчёт денег/won.
            'missingTypes' => collect(['payment_won' => 'Оплата успешно (won)', 'shop_gate' => 'Закуп / отправка в цех', 'logistics' => 'Логистика (возврат из цеха)'])
                ->reject(fn ($label, $type) => $dealStages->contains('stage_type', $type))
                ->all(),
        ]);
    }

    /** Перенумеровать воронку компании 1..N (лечит задвоенный/дырявый order). */
    private function reindexFunnel(string $model, ?int $companyId): void
    {
        $stages = $model::where('company_id', $companyId)->orderBy('order')->orderBy('id')->get();
        // У цеха нумерация ВНУТРИ каждого цеха (у BAIA их два) — 1..N на цех.
        $groups = $model === ProjectStage::class
            ? $stages->groupBy(fn ($s) => $s->workshop ?? '')
            : collect(['' => $stages]);
        foreach ($groups as $group) {
            foreach ($group->values() as $i => $s) {
                if ((int) $s->order !== $i + 1) {
                    $s->update(['order' => $i + 1]);
                }
            }
        }
    }

    public function store(Request $request): RedirectResponse
    {
        $this->guard($request);
        $data = $this->validated($request);
        $model = $this->model($data['kind']);

        // Новый этап попадает в воронку (сделок или цеха), выбранную на странице.
        $companyId = $this->funnelCompanyId($request);

        $max = $model::query()
            ->when($companyId, fn ($q, $c) => $q->where('company_id', $c))
            ->when($data['kind'] === 'project', fn ($q) => $q->where('workshop', $data['workshop'] ?? null))
            ->max('order') ?? 0;
        $stage = $model::create([
            'name' => $data['name'],
            'color' => $data['color'] ?? '#6B7280',
            'order' => $max + 1,
            'is_active' => true,
            'checklist' => [],
            'type' => $data['kind'] === 'project' ? 'project' : 'sale',
            'company_id' => $companyId,
            'workshop' => $data['kind'] === 'project' ? ($data['workshop'] ?? null) : null,
        ]);
        $stage->translations()->updateOrCreate(['locale' => app()->getLocale()], ['name' => $data['name']]);

        return back()->with('success', 'Этап добавлен.');
    }

    public function update(Request $request, string $kind, int $id): RedirectResponse
    {
        $this->guard($request);
        $data = $this->validated($request, false);
        $stage = $this->model($kind)::findOrFail($id);

        $updates = array_filter([
            'name' => $data['name'] ?? null,
            'color' => $data['color'] ?? null,
            'order' => $data['order'] ?? null,
        ], fn ($v) => $v !== null);

        // «Завершающий этап» — только у цеха: по нему заказ считается готовым
        // и сделка возвращается на «Логистику». Завершающий один на воронку.
        if ($kind === 'project' && $request->has('workshop')) {
            $updates['workshop'] = $data['workshop'] ?: null;
        }
        if ($kind === 'project' && $request->has('is_completed')) {
            $isCompleted = (bool) $data['is_completed'];
            if ($isCompleted) {
                // Завершающий один НА ЦЕХ (у BAIA их два — у каждого свой).
                ProjectStage::where('company_id', $stage->company_id)
                    ->where('workshop', $updates['workshop'] ?? $stage->workshop)
                    ->where('id', '!=', $stage->id)
                    ->update(['is_completed' => false]);
            }
            $updates['is_completed'] = $isCompleted;
        }

        // Тип и гейт — только у этапов сделок.
        if ($kind !== 'project' && $request->hasAny(['stage_type', 'gate_task_title', 'gate_task_role', 'gate_task_days'])) {
            if ($request->has('stage_type')) {
                $type = $data['stage_type'] ?? null;
                // Один спец-тип на воронку: два «Акта» сломали бы логику.
                if ($type && DealStage::where('stage_type', $type)->where('company_id', $stage->company_id)->where('id', '!=', $stage->id)->exists()) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'stage_type' => 'Тип «'.(DealStage::STAGE_TYPES[$type] ?? $type).'» уже назначен другому этапу этой воронки.',
                    ]);
                }
                $updates['stage_type'] = $type;
                // won-логика (деньги, ЗП, аналитика) читает is_won — синхронизируем.
                $updates['is_won'] = $type === 'payment_won';
            }
            foreach (['gate_task_title', 'gate_task_role', 'gate_task_days'] as $f) {
                if ($request->has($f)) {
                    $updates[$f] = $data[$f] ?? null;
                }
            }
        }

        $stage->update($updates);

        if (! empty($data['name'])) {
            // Keep the current-locale translation in sync so the rename shows on cards.
            $stage->translations()->updateOrCreate(['locale' => app()->getLocale()], ['name' => $data['name']]);
        }

        return back()->with('success', 'Этап обновлён.');
    }

    /**
     * Переместить этап вверх/вниз — обмен order с соседним этапом.
     */
    public function move(Request $request, string $kind, int $id): RedirectResponse
    {
        $this->guard($request);
        $dir = $request->validate(['direction' => ['required', 'in:up,down']])['direction'];

        $model = $this->model($kind);
        $stage = $model::findOrFail($id);
        $neighbor = $model::query()
            // Обмен местами только внутри воронки своей компании (сделки и цех).
            ->where('company_id', $stage->company_id)
            // Цех: стрелки двигают этап только внутри СВОЕГО цеха.
            ->when($kind === 'project', fn ($q) => $q->where('workshop', $stage->workshop))
            ->when($dir === 'up', fn ($q) => $q->where('order', '<', $stage->order)->orderByDesc('order'))
            ->when($dir === 'down', fn ($q) => $q->where('order', '>', $stage->order)->orderBy('order'))
            ->first();

        if (! $neighbor) {
            return back()->with('error', 'Этап уже '.($dir === 'up' ? 'первый' : 'последний').'.');
        }

        [$stageOrder, $neighborOrder] = [$stage->order, $neighbor->order];
        $stage->update(['order' => $neighborOrder]);
        $neighbor->update(['order' => $stageOrder]);

        return back()->with('success', 'Этап перемещён.');
    }

    /**
     * Удаление этапа. Если на этапе есть активные сделки (или заказы цеха) —
     * требуется transfer_to: они переносятся на указанный этап той же воронки.
     */
    public function destroy(Request $request, string $kind, int $id): RedirectResponse
    {
        $this->guard($request);
        $model = $this->model($kind);
        $stage = $model::findOrFail($id);
        $transferTo = (int) $request->input('transfer_to', 0);

        $occupants = $kind === 'project'
            ? Project::where('project_stage_id', $stage->id)
            : Deal::where('deal_stage_id', $stage->id)->whereNotIn('status', ['closed', 'cancelled']);

        if (($count = (clone $occupants)->count()) > 0) {
            if (! $transferTo) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'transfer_to' => "На этапе «{$stage->name}» — {$count} ".($kind === 'project' ? 'заказ(ов)' : 'сделок(ки)').'. Выберите этап, куда их перенести.',
                ]);
            }
            $target = $model::findOrFail($transferTo);
            if ($target->id === $stage->id || $target->company_id !== $stage->company_id) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'transfer_to' => 'Этап переноса должен быть другим этапом той же воронки.',
                ]);
            }
            $occupants->update($kind === 'project' ? ['project_stage_id' => $target->id] : ['deal_stage_id' => $target->id]);
        }

        $stage->delete();

        // Re-index remaining stages (внутри воронки своей компании) — 1..N без пробелов.
        $model::query()
            ->where('company_id', $stage->company_id)
            ->orderBy('order')->orderBy('id')->get()->each(fn ($s, $i) => $s->update(['order' => $i + 1]));

        return back()->with('success', 'Этап удалён'.($transferTo ? ' — записи перенесены.' : '.'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $requireKind = true): array
    {
        return $request->validate([
            'kind' => [$requireKind ? 'required' : 'nullable', Rule::in(['deal', 'project'])],
            'name' => [$requireKind ? 'required' : 'nullable', 'string', 'max:255'],
            'color' => ['nullable', 'string', 'max:20'],
            'order' => ['nullable', 'integer'],
            'stage_type' => ['nullable', Rule::in(array_keys(DealStage::STAGE_TYPES))],
            'gate_task_title' => ['nullable', 'string', 'max:255'],
            'gate_task_role' => ['nullable', Rule::in(['financist', 'designer', 'supplier', 'manager', 'director', 'admin'])],
            'gate_task_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'is_completed' => ['nullable', 'boolean'],
            'workshop' => ['nullable', 'string', 'max:100'],
        ]);
    }
}
