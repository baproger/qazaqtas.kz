<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\Project;
use App\Models\ProjectStage;
use App\Models\Role;
use App\Support\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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

        return CurrentCompany::id() ?: (int) Company::orderBy('id')->value('id');
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
            'stageTypeHints' => DealStage::STAGE_TYPE_HINTS,
            // Тип уникален в воронке: иначе система не знала бы, какой из двух
            // этапов считать «Оплатой». Показываем, кто какой тип держит, —
            // чтобы было понятно, куда идти освобождать.
            'typeOwners' => $dealStages->whereNotNull('stage_type')
                ->mapWithKeys(fn ($s) => [$s->stage_type => $s->name])->all(),
            // Кому ставится гейт-задача: особые адресаты + роли из БД.
            'gateRoles' => DealStage::GATE_SPECIAL + Role::where('name', '!=', 'admin')->orderBy('name')->get()
                ->mapWithKeys(fn ($r) => [$r->name => 'Всем с ролью «'.$r->title().'»'])->all(),
            // Роли для конструктора логики — из БД, как их назвал владелец.
            'roles' => Role::where('name', '!=', 'admin')->orderBy('name')->get()
                ->map(fn ($r) => ['value' => $r->name, 'label' => $r->title()])->values(),
            // Действующие правила каждого этапа (явные или выведенные из типа).
            'stageRules' => $dealStages->mapWithKeys(fn ($s) => [$s->id => $s->effectiveRules()])->all(),
            // Обязательные типы: без payment_won не работает подсчёт денег/won.
            'missingTypes' => collect(['payment_won', 'shop_gate', 'logistics'])
                ->mapWithKeys(fn ($type) => [$type => DealStage::STAGE_TYPES[$type]])
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
        // Свойства «финальная проверка» и «не считать просроченной».
        foreach (['is_closing', 'ignores_deadline'] as $flag) {
            if ($kind !== 'project' && $request->has($flag)) {
                $updates[$flag] = (bool) $data[$flag];
            }
        }

        // Конструктор логики — только у этапов сделок. Пришли правила —
        // сохраняем явно; с этого момента тип больше их не выводит.
        if ($kind !== 'project' && $request->has('rules')) {
            $updates['rules'] = $this->normalizeRules((array) ($data['rules'] ?? []), $stage);
        }

        // Тип и гейт — только у этапов сделок.
        if ($kind !== 'project' && $request->hasAny(['stage_type', 'gate_task_title', 'gate_task_role', 'gate_task_days'])) {
            if ($request->has('stage_type')) {
                $type = $data['stage_type'] ?? null;
                // Один спец-тип на воронку: два «Акта» сломали бы логику.
                if ($type && DealStage::where('stage_type', $type)->where('company_id', $stage->company_id)->where('id', '!=', $stage->id)->exists()) {
                    throw ValidationException::withMessages([
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
     * Копия этапа со всей логикой — рядом с оригиналом. Системный тип не
     * копируется (он один на воронку), остальное — как есть.
     */
    public function duplicate(Request $request, string $kind, int $id): RedirectResponse
    {
        $this->guard($request);
        $model = $this->model($kind);
        $source = $model::findOrFail($id);

        $copy = $source->replicate(['stage_type', 'is_won', 'is_completed']);
        $copy->name = $source->name.' (копия)';
        $copy->order = $source->order + 1;
        if ($kind !== 'project') {
            // Явные правила — чтобы копия не зависела от типа, которого у неё нет.
            $copy->rules = $source->effectiveRules();
        }
        $copy->save();
        $copy->translations()->updateOrCreate(['locale' => app()->getLocale()], ['name' => $copy->name]);

        // Сдвигаем всё, что стояло после оригинала, и перенумеровываем 1..N.
        $companyId = $source->company_id ? (int) $source->company_id : null;
        $model::where('id', '!=', $copy->id)->where('order', '>', $source->order)
            ->where(fn ($w) => $w->where('company_id', $companyId)->orWhereNull('company_id'))
            ->increment('order');
        $this->reindexFunnel($model, $companyId);

        return back()->with('success', 'Этап скопирован: «'.$copy->name.'».');
    }

    /**
     * Правила из формы → чистый набор: только известные роли и этапы своей
     * воронки, только допустимые значения. Подделанный запрос ничего не
     * добавит.
     *
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    private function normalizeRules(array $rules, DealStage $stage): array
    {
        $roles = Role::pluck('name')->all();
        $onlyRoles = fn ($list) => array_values(array_intersect(array_map('strval', (array) $list), $roles));
        $funnelIds = DealStage::where(fn ($w) => $w->where('company_id', $stage->company_id)->orWhereNull('company_id'))
            ->where('id', '!=', $stage->id)->pluck('id')->all();
        $req = (array) ($rules['require'] ?? []);

        return [
            'leave_roles' => $onlyRoles($rules['leave_roles'] ?? []),
            'enter_roles' => $onlyRoles($rules['enter_roles'] ?? []),
            'extra_movers' => $onlyRoles($rules['extra_movers'] ?? []),
            'from_stages' => array_values(array_intersect(array_map('intval', (array) ($rules['from_stages'] ?? [])), $funnelIds)),
            'require' => [
                'invoice' => (bool) ($req['invoice'] ?? false),
                'payment' => in_array($req['payment'] ?? 'none', ['none', 'partial', 'full'], true) ? $req['payment'] : 'none',
                'items_done' => (bool) ($req['items_done'] ?? false),
            ],
        ];
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
                throw ValidationException::withMessages([
                    'transfer_to' => "На этапе «{$stage->name}» — {$count} ".($kind === 'project' ? 'заказ(ов)' : 'сделок(ки)').'. Выберите этап, куда их перенести.',
                ]);
            }
            $target = $model::findOrFail($transferTo);
            if ($target->id === $stage->id || $target->company_id !== $stage->company_id) {
                throw ValidationException::withMessages([
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
            'gate_task_role' => ['nullable', Rule::in(array_merge(array_keys(DealStage::GATE_SPECIAL), Role::pluck('name')->all()))],
            'gate_task_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'is_completed' => ['nullable', 'boolean'],
            'requires_document' => ['nullable', 'boolean'],
            'is_closing' => ['nullable', 'boolean'],
            'ignores_deadline' => ['nullable', 'boolean'],
            'workshop' => ['nullable', 'string', 'max:100'],
            'rules' => ['nullable', 'array'],
            'rules.leave_roles' => ['nullable', 'array'], 'rules.leave_roles.*' => ['string'],
            'rules.enter_roles' => ['nullable', 'array'], 'rules.enter_roles.*' => ['string'],
            'rules.extra_movers' => ['nullable', 'array'], 'rules.extra_movers.*' => ['string'],
            'rules.from_stages' => ['nullable', 'array'], 'rules.from_stages.*' => ['integer'],
            'rules.require' => ['nullable', 'array'],
            'rules.require.invoice' => ['nullable', 'boolean'],
            'rules.require.items_done' => ['nullable', 'boolean'],
            'rules.require.payment' => ['nullable', Rule::in(['none', 'partial', 'full'])],
        ]);
    }
}
