<?php

namespace App\Http\Controllers;

use App\Models\DealStage;
use App\Models\Role;
use App\Models\StageRobot;
use App\Models\StageRobotRun;
use App\Models\User;
use App\Robots\ActionRegistry;
use App\Robots\Conditions;
use App\Support\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Настройки → Автоматизация: роботы этапов и журнал их запусков. */
class StageRobotController extends Controller
{
    private function guard(Request $request): void
    {
        abort_unless($request->user()->hasRole('admin') || $request->user()->can('setting.update'), 403);
    }

    private function companyId(Request $request): ?int
    {
        $id = $request->integer('company') ?: CurrentCompany::id();

        return $id ?: null;
    }

    public function index(Request $request): Response
    {
        $this->guard($request);
        $companyId = $this->companyId($request);

        $stages = DealStage::funnel($companyId);
        $robots = StageRobot::query()
            ->where(fn ($w) => $w->where('company_id', $companyId)->orWhereNull('company_id'))
            ->withCount(['runs', 'runs as failed_runs_count' => fn ($q) => $q->where('status', 'failed')])
            ->orderBy('stage_id')->orderBy('sort')->orderBy('id')->get();

        $runs = StageRobotRun::query()->with(['robot:id,name,action_type', 'deal:id,number,company_name'])
            ->whereIn('robot_id', $robots->pluck('id'))
            ->latest('id')->limit(100)->get()
            ->map(fn ($r) => [
                'id' => $r->id, 'robot' => $r->robot?->name, 'action' => $r->robot?->action_type,
                'deal' => $r->deal ? ['id' => $r->deal->id, 'number' => $r->deal->number, 'company' => $r->deal->company_name] : null,
                'status' => $r->status, 'scheduled_at' => $r->scheduled_at?->toIso8601String(),
                'finished_at' => $r->finished_at?->toIso8601String(), 'error' => $r->error, 'output' => $r->output,
                'created_at' => $r->created_at?->toIso8601String(),
            ]);

        return Inertia::render('Settings/Robots', [
            'robots' => $robots,
            'runs' => $runs,
            'stages' => $stages->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'color' => $s->color])->values(),
            'selectedStageId' => $request->integer('stage') ?: null,
            'actions' => ActionRegistry::describe(),
            'roles' => Role::where('name', '!=', 'admin')->orderBy('name')->get()->map(fn ($r) => ['value' => $r->name, 'label' => $r->title()])->values(),
            'users' => User::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'fields' => Conditions::FIELDS,
            'ops' => Conditions::OPS,
            'triggers' => StageRobot::TRIGGERS,
            'sequences' => StageRobot::SEQUENCES,
            'companyId' => $companyId,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->guard($request);
        $data = $this->validated($request);
        $data['company_id'] = $this->companyId($request);
        $data['sort'] = (int) StageRobot::where('stage_id', $data['stage_id'] ?? null)->max('sort') + 1;
        StageRobot::create($data);

        return back()->with('success', 'Робот добавлен.');
    }

    public function update(Request $request, StageRobot $robot): RedirectResponse
    {
        $this->guard($request);
        $robot->update($this->validated($request));

        return back()->with('success', 'Робот обновлён.');
    }

    public function toggle(Request $request, StageRobot $robot): RedirectResponse
    {
        $this->guard($request);
        $robot->update(['is_active' => ! $robot->is_active]);

        return back();
    }

    public function duplicate(Request $request, StageRobot $robot): RedirectResponse
    {
        $this->guard($request);
        $copy = $robot->replicate();
        $copy->name = $robot->name.' (копия)';
        $copy->is_active = false;
        $copy->sort = $robot->sort + 1;
        $copy->save();

        return back()->with('success', 'Робот скопирован (выключен).');
    }

    public function destroy(Request $request, StageRobot $robot): RedirectResponse
    {
        $this->guard($request);
        $robot->delete();

        return back()->with('success', 'Робот удалён.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'stage_id' => ['nullable', 'integer', 'exists:deal_stages,id'],
            'trigger' => ['required', Rule::in(array_keys(StageRobot::TRIGGERS))],
            'sequence' => ['required', Rule::in(array_keys(StageRobot::SEQUENCES))],
            'delay_seconds' => ['nullable', 'integer', 'min:0', 'max:31536000'],
            'run_if_left' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'conditions' => ['nullable', 'array'],
            'conditions.all' => ['nullable', 'array'],
            'conditions.all.*.field' => ['required', Rule::in(array_keys(Conditions::FIELDS))],
            'conditions.all.*.op' => ['required', Rule::in(Conditions::OPS)],
            'conditions.all.*.value' => ['nullable'],
            'action_type' => ['required', Rule::in(array_keys(ActionRegistry::all()))],
            'action_payload' => ['nullable', 'array'],
        ]);
        $data['delay_seconds'] = (int) ($data['delay_seconds'] ?? 0);
        $data['run_if_left'] = (bool) ($data['run_if_left'] ?? false);
        $data['is_active'] = (bool) ($data['is_active'] ?? true);
        $data['conditions'] = ['all' => array_values((array) ($data['conditions']['all'] ?? []))];

        return $data;
    }
}
