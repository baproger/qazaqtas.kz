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
 * Настройки → Этапы: управление воронками фирм и общей
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

    /**
     * Этапы одной воронки: свои фирме плюс общие (company_id = null).
     *
     * Ровно этот набор страница и показывает, поэтому им же считается порядок.
     * Раньше нумерация и стрелки работали строго по company_id — общие этапы
     * оставались со своей нумерацией, в списке появлялись два этапа с order=1,
     * а стрелка искала соседа только среди «своих» и упиралась в «этап уже
     * первый», хотя выше него на экране были другие.
     */
    private function funnelStages(string $model, ?int $companyId, ?string $workshop = null)
    {
        return $model::query()
            ->where(fn ($w) => $w->where('company_id', $companyId)->orWhereNull('company_id'))
            // Цех: у каждого города своя воронка и своя нумерация.
            ->when($model === ProjectStage::class, fn ($q) => $q->where('workshop', $workshop))
            ->orderBy('order')->orderBy('id')
            ->get();
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
            // Цех у каждой фирмы свой.
            'projectStages' => ProjectStage::withCount('projects')
                ->where(fn ($w) => $w->where('company_id', $companyId)->orWhereNull('company_id'))
                ->orderBy('order')->get(),
            'companies' => Company::orderBy('id')->get(['id', 'name']),
            'selectedCompanyId' => $companyId,
            'stageTypes' => DealStage::STAGE_TYPES,
            'gateRoles' => ['financist' => 'Бухгалтер', 'designer' => 'Технолог', 'supplier' => 'Снабженец', 'manager' => 'Менеджер', 'director' => 'Директор', 'admin' => 'Админ'],
            // Обязательные типы: без payment_won не работает подсчёт денег/won.
            'missingTypes' => collect(['payment_won' => 'Оплата успешно (won)', 'shop_gate' => 'Закуп / отправка в цех', 'logistics' => 'Логистика (возврат из цеха)'])
                ->reject(fn ($label, $type) => $dealStages->contains('stage_type', $type))
                ->all(),
        ]);
    }

    /** Перенумеровать воронку 1..N (лечит задвоенный и дырявый order). */
    private function reindexFunnel(string $model, ?int $companyId): void
    {
        $stages = $model::query()
            ->where(fn ($w) => $w->where('company_id', $companyId)->orWhereNull('company_id'))
            ->orderBy('order')->orderBy('id')->get();

        // У цеха нумерация ВНУТРИ каждого города — 1..N на цех.
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

        // Конец воронки считаем по тому же набору, что показан на странице,
        // иначе новый этап получал номер, уже занятый общим этапом.
        $max = (int) $this->funnelStages($model, $companyId, $data['workshop'] ?? null)->max('order');
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
                // Завершающий один НА ЦЕХ (если цехов несколько — у каждого свой).
                ProjectStage::where('company_id', $stage->company_id)
                    ->where('workshop', $updates['workshop'] ?? $stage->workshop)
                    ->where('id', '!=', $stage->id)
                    ->update(['is_completed' => false]);
            }
            $updates['is_completed'] = $isCompleted;
        }

        // Требование документа — только у этапов сделок.
        if ($kind !== 'project' && $request->has('requires_document')) {
            $updates['requires_document'] = (bool) $data['requires_document'];
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
     * Новый порядок воронки: с клиента приходит список id сверху вниз.
     *
     * Раньше стрелка меняла order местами с соседом. Это ломалось всякий раз,
     * когда номера задваивались (обмен ничего не менял) и когда сосед по
     * экрану лежал в другой области видимости. Здесь порядок задаётся целиком
     * и всегда получается 1..N без дыр — то же действие обслуживает и стрелки,
     * и перетаскивание мышью.
     */
    public function reorder(Request $request, string $kind): RedirectResponse
    {
        $this->guard($request);

        $model = $this->model($kind);
        $ids = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['required', 'integer'],
        ])['ids'];

        $companyId = $this->funnelCompanyId($request);
        $workshop = $request->input('workshop') ?: null;

        // Двигать можно только этапы показанной воронки: посторонний id из
        // запроса не должен переставлять чужие этапы.
        $allowed = $this->funnelStages($model, $companyId, $workshop)->keyBy('id');
        $ordered = collect($ids)->filter(fn (int $id) => $allowed->has($id))->values();

        if ($ordered->count() !== $allowed->count()) {
            return back()->with('error', 'Список этапов изменился — обновите страницу.');
        }

        foreach ($ordered as $i => $id) {
            $stage = $allowed[$id];
            if ((int) $stage->order !== $i + 1) {
                $stage->update(['order' => $i + 1]);
            }
        }

        return back()->with('success', 'Порядок этапов сохранён.');
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
            'requires_document' => ['nullable', 'boolean'],
            'workshop' => ['nullable', 'string', 'max:100'],
        ]);
    }
}
