<?php

namespace App\Http\Controllers;

use App\Http\Requests\UserRequest;
use App\Models\Department;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', User::class);

        // Без пагинации: страница группирует сотрудников по отделам,
        // поиск и фильтры — мгновенные на клиенте.
        $users = User::query()
            ->with(['department:id,name', 'roles:id,name', 'companies:companies.id,name'])
            ->orderBy('name')
            ->get()
            ->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'avatar' => $u->avatar,
                'email' => $u->email,
                'phone' => $u->phone,
                'birth_date' => $u->birth_date?->toDateString(),
                'hired_at' => $u->hired_at?->toDateString(),
                'is_active' => $u->is_active,
                'department' => $u->department,
                'department_id' => $u->department_id,
                'workshops' => $u->workshops ?? [],
                'role' => $u->roles->first()?->name,
                'company_ids' => $u->companies->pluck('id'),
                'company_names' => $u->companies->pluck('name')->join(', '),
                'salary' => (float) $u->salary,
                'has_contract' => (bool) $u->contract_path,
            ])
            ->values();

        return Inertia::render('Users/Index', [
            'users' => $users,
            'departments' => Department::where('is_active', true)->orderBy('name')->get(['id', 'name', 'head_user_id']),
            'roles' => Role::orderBy('name')->pluck('name'),
            'companies' => \App\Models\Company::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'can' => ['manage' => $request->user()->can('create', User::class)],
            // Цеха производства — чекбоксы доступа в форме сотрудника.
            'workshopOptions' => \App\Models\Company::where('is_active', true)->pluck('id')
                ->flatMap(fn ($id) => \App\Models\ProjectStage::workshopsFor((int) $id))->unique()->values(),
        ]);
    }

    /**
     * Профиль сотрудника: сделки, заказы цеха, задачи и ЗП в одном месте.
     * Видит руководство (user.view) или сам сотрудник; деньги (оклад/бонус) —
     * только admin/financist и сам сотрудник (директор — наблюдатель без ЗП-детали).
     */
    public function show(Request $request, User $user, \App\Services\PayrollService $payroll): Response
    {
        $viewer = $request->user();
        abort_unless($viewer->can('view', $user) || $viewer->id === $user->id, 403);

        $seesMoney = $viewer->hasAnyRole(['admin', 'financist', 'director']) || $viewer->id === $user->id;

        $deals = \App\Models\Deal::forCurrentCompany()
            ->where('responsible_user_id', $user->id)
            ->with('stage:id,name,is_won')
            ->orderByDesc('created_at')
            ->limit(30)
            ->get(['id', 'number', 'company_name', 'budget', 'deal_stage_id', 'status', 'deadline', 'created_at'])
            ->map(fn ($d) => [
                'id' => $d->id,
                'number' => $d->number,
                'company_name' => $d->company_name,
                'budget' => $seesMoney ? (float) $d->budget : null,
                'stage' => $d->stage?->name,
                'is_won' => (bool) $d->stage?->is_won,
                'status' => $d->status,
                'deadline' => $d->deadline?->toDateString(),
            ]);

        $projects = \App\Models\Project::query()
            ->where('responsible_user_id', $user->id)
            ->with('stage:id,name')
            ->orderByDesc('created_at')
            ->limit(30)
            ->get(['id', 'number', 'name', 'workshop', 'project_stage_id', 'status', 'deadline'])
            ->map(fn ($p) => [
                'id' => $p->id,
                'number' => $p->number,
                'name' => $p->name,
                'workshop' => $p->workshop,
                'stage' => $p->stage?->name,
                'status' => $p->status,
                'deadline' => $p->deadline?->toDateString(),
            ]);

        $tasks = \App\Models\Task::where('assignee_id', $user->id)
            ->orderByRaw("status = 'done'")->orderByDesc('created_at')
            ->limit(30)
            ->get(['id', 'title', 'status', 'priority', 'due_date'])
            ->map(fn ($t) => [
                'id' => $t->id,
                'title' => $t->title,
                'status' => $t->status,
                'priority' => $t->priority,
                'due_date' => $t->due_date?->toDateString(),
                'overdue' => $t->status !== 'done' && $t->due_date && $t->due_date->isPast(),
            ]);

        // ЗП-строка из единого источника правды (как на странице Зарплата).
        $payrollRow = $seesMoney
            ? $payroll->perUser(true)->firstWhere('uid', $user->id)
            : null;
        $adjustments = $seesMoney
            ? \App\Models\PayrollAdjustment::where('user_id', $user->id)
                ->orderByDesc('date')->limit(20)->get()
                ->map(fn ($a) => [
                    'id' => $a->id, 'type' => $a->type, 'amount' => (float) $a->amount,
                    'days' => $a->days !== null ? (float) $a->days : null,
                    'date' => $a->date?->toDateString(), 'note' => $a->note,
                ])
            : [];

        $headOf = Department::where('head_user_id', $user->id)->pluck('name');

        return Inertia::render('Users/Show', [
            'person' => [
                'id' => $user->id,
                'name' => $user->name,
                'avatar' => $user->avatar,
                'email' => $user->email,
                'phone' => $user->phone,
                'birth_date' => $user->birth_date?->toDateString(),
                'hired_at' => $user->hired_at?->toDateString(),
                'is_active' => $user->is_active,
                'department' => $user->department?->name,
                'head_of' => $headOf,
                'role' => $user->roles->first()?->name,
                'companies' => $user->companies->pluck('name'),
                'salary' => $seesMoney ? (float) $user->salary : null,
                'has_contract' => (bool) $user->contract_path,
            ],
            'deals' => $deals,
            'projects' => $projects,
            'tasks' => $tasks,
            'payrollRow' => $payrollRow,
            'adjustments' => $adjustments,
            'can' => ['manage' => $viewer->can('update', $user)],
        ]);
    }

    /**
     * Экспорт списка сотрудников в CSV (открывается в Excel): имя, отдел, роль,
     * телефон, email, компании, даты. Только для тех, кто видит страницу.
     */
    public function export(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $this->authorize('viewAny', User::class);

        $roleLabels = [
            'admin' => 'СЕО (админ)', 'director' => 'Директор', 'financist' => 'Финансист-Бухгалтер',
            'manager' => 'Менеджер', 'employee' => 'Сотрудник (цех)', 'lawyer' => 'Юрист',
            'cook' => 'Повар', 'designer' => 'Технолог', 'supplier' => 'Снабженец',
        ];
        $users = User::with(['department:id,name', 'roles:id,name', 'companies:companies.id,name'])
            ->orderBy('name')->get();

        return response()->streamDownload(function () use ($users, $roleLabels) {
            $out = fopen('php://output', 'w');
            // BOM — иначе Excel открывает кириллицу кракозябрами.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Имя', 'Отдел', 'Роль', 'Телефон', 'Email', 'Компании', 'В компании с', 'День рождения', 'Статус'], ';');
            foreach ($users as $u) {
                fputcsv($out, [
                    $u->name,
                    $u->department?->name ?? '—',
                    $roleLabels[$u->roles->first()?->name] ?? ($u->roles->first()?->name ?? '—'),
                    $u->phone ?? '—',
                    $u->email,
                    $u->companies->pluck('name')->join(', '),
                    $u->hired_at?->format('d.m.Y') ?? '—',
                    $u->birth_date?->format('d.m.Y') ?? '—',
                    $u->is_active ? 'Активен' : 'Отключён',
                ], ';');
            }
            fclose($out);
        }, 'Сотрудники — '.now()->format('d.m.Y').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function store(UserRequest $request): RedirectResponse
    {
        $this->authorize('create', User::class);

        $data = $request->validated();
        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'department_id' => $data['department_id'] ?? null,
            'workshops' => array_values(array_filter($data['workshops'] ?? [])) ?: null,
            'phone' => $data['phone'] ?? null,
            'birth_date' => $data['birth_date'] ?? null,
            'hired_at' => $data['hired_at'] ?? null,
            'salary' => $data['salary'] ?? 0,
            'contract_path' => $request->hasFile('contract') ? $request->file('contract')->store('contracts') : null,
            'is_active' => $data['is_active'] ?? true,
            'language' => 'ru',
        ]);
        $this->guardRoleAssignment($request, $data['role']);
        $user->assignRole($data['role']);

        if ($user->department_id) {
            $user->departments()->syncWithoutDetaching([$user->department_id]);
        }
        // Фирмы сотрудника (можно несколько); без выбора — привязка ко всем.
        $user->companies()->sync($this->companyIds($request));

        return back()->with('success', 'Сотрудник добавлен.');
    }

    public function update(UserRequest $request, User $user): RedirectResponse
    {
        $this->authorize('update', $user);

        $data = $request->validated();
        // ДО записи полей: не-админ не редактирует админа (иначе поля успели
        // бы обновиться до 403 на роли).
        $this->guardRoleAssignment($request, $data['role'], $user);
        $user->update([
            'name' => $data['name'],
            'email' => $data['email'],
            'department_id' => $data['department_id'] ?? null,
            'workshops' => array_values(array_filter($data['workshops'] ?? [])) ?: null,
            'phone' => $data['phone'] ?? null,
            'birth_date' => $data['birth_date'] ?? null,
            'hired_at' => $data['hired_at'] ?? null,
            'salary' => $data['salary'] ?? 0,
            'is_active' => $data['is_active'] ?? true,
        ]);
        if ($request->hasFile('contract')) {
            if ($user->contract_path) {
                \Illuminate\Support\Facades\Storage::delete($user->contract_path);
            }
            $user->update(['contract_path' => $request->file('contract')->store('contracts')]);
        }
        if (! empty($data['password'])) {
            $user->update(['password' => Hash::make($data['password'])]);
        }
        $user->syncRoles([$data['role']]);
        $user->companies()->sync($this->companyIds($request));

        return back()->with('success', 'Сотрудник обновлён.');
    }

    /**
     * Трудовой договор: скачать может руководство или сам сотрудник.
     */
    public function contract(Request $request, User $user): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        abort_unless(
            $request->user()->hasAnyRole(['admin', 'director', 'financist']) || $request->user()->id === $user->id,
            403
        );
        abort_unless($user->contract_path && \Illuminate\Support\Facades\Storage::exists($user->contract_path), 404);

        return \Illuminate\Support\Facades\Storage::download(
            $user->contract_path,
            'Договор — '.$user->name.'.'.pathinfo($user->contract_path, PATHINFO_EXTENSION)
        );
    }

    /**
     * Validated company ids from the form; empty selection = both firms
     * (safe default so the employee is never locked out).
     */
    private function companyIds(Request $request): array
    {
        $ids = collect($request->input('company_ids', []))->map(fn ($v) => (int) $v)->filter();
        $valid = \App\Models\Company::where('is_active', true)->pluck('id');

        $picked = $ids->intersect($valid);

        return ($picked->isEmpty() ? $valid : $picked)->values()->all();
    }

    /**
     * Роль admin назначает/снимает ТОЛЬКО действующий admin (директор — нет,
     * иначе наблюдатель выдал бы себе полный доступ). Плюс защита последнего
     * активного администратора от разжалования при обновлении.
     */
    private function guardRoleAssignment(Request $request, string $role, ?User $target = null): void
    {
        $actorIsAdmin = $request->user()->hasRole('admin');
        $targetWasAdmin = $target?->hasRole('admin') ?? false;

        // Выдать или снять роль admin может только admin.
        if (($role === 'admin' || $targetWasAdmin) && ! $actorIsAdmin) {
            abort(403, 'Роль «Администратор» назначает и снимает только администратор.');
        }
        // Нельзя разжаловать последнего активного админа.
        if ($targetWasAdmin && $role !== 'admin' && $this->activeAdminCount() <= 1) {
            abort(403, 'Нельзя снять роль с последнего администратора.');
        }
    }

    private function activeAdminCount(): int
    {
        return User::where('is_active', true)->role('admin')->count();
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        $this->authorize('delete', $user);
        // Администратора деактивирует только администратор.
        if ($user->hasRole('admin') && ! $request->user()->hasRole('admin')) {
            abort(403, 'Администратора деактивирует только администратор.');
        }
        // Последнего активного админа нельзя деактивировать — иначе система
        // останется без владельца (Gate::before на admin).
        if ($user->hasRole('admin') && $this->activeAdminCount() <= 1) {
            abort(403, 'Нельзя деактивировать последнего администратора.');
        }

        // Soft-delete + deactivate rather than hard removal.
        $user->update(['is_active' => false]);
        $user->delete();

        return back()->with('success', 'Сотрудник деактивирован.');
    }
}
