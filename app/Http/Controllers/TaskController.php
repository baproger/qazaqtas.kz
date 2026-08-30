<?php

namespace App\Http\Controllers;

use App\Events\TaskStatusUpdated;
use App\Http\Requests\TaskRequest;
use App\Models\Deal;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskAssigned;
use App\Support\AccessScope;
use App\Support\NotificationResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TaskController extends Controller
{
    /**
     * Страница задач: мои / поручил я / все (по области права task.viewAny).
     * Быстрые действия — переключение статуса и автосохранение полей.
     */
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Task::class);
        $user = $request->user();

        $view = $request->string('view')->toString() ?: 'mine';
        $status = $request->string('status')->toString();
        $type = $request->string('type')->toString();
        $search = trim($request->string('search')->toString());

        $tasks = Task::query()->with(['assignee:id,name,avatar', 'creator:id,name', 'taskable'])
            ->when($view === 'mine', fn ($q) => $q->where('assignee_id', $user->id))
            ->when($view === 'created', fn ($q) => $q->where('creator_id', $user->id))
            ->when($view === 'all', fn ($q) => $q->visibleTo($user))
            ->when($status === 'open', fn ($q) => $q->whereNotIn('status', Task::CLOSED))
            ->when($status !== '' && $status !== 'open', fn ($q) => $q->where('status', $status))
            ->when($type !== '', fn ($q) => $q->where('type', $type))
            ->when($search !== '', fn ($q) => $q->where('title', 'like', "%{$search}%"))
            ->orderByRaw("case when status in ('done','canceled') then 1 else 0 end")
            ->orderByRaw('case when due_date is null then 1 else 0 end')->orderBy('due_date')->orderByDesc('id')
            ->paginate(50)->withQueryString()
            ->through(fn (Task $t) => [
                'id' => $t->id, 'title' => $t->title, 'description' => $t->description, 'status' => $t->status, 'priority' => $t->priority, 'type' => $t->type,
                'due_date' => $t->due_date?->toDateString(), 'completed_at' => $t->completed_at?->toIso8601String(), 'created_at' => $t->created_at?->toIso8601String(),
                'assignee' => $t->assignee ? ['id' => $t->assignee->id, 'name' => $t->assignee->name, 'avatar' => $t->assignee->avatar] : null,
                'creator' => $t->creator?->name,
                'link' => $this->linkFor($t),
                'can_edit' => $user->can('update', $t),
            ]);

        $mineOpen = Task::where('assignee_id', $user->id)->whereNotIn('status', Task::CLOSED);

        return Inertia::render('Tasks/Index', [
            'tasks' => $tasks,
            'filters' => ['view' => $view, 'status' => $status ?: 'open', 'type' => $type, 'search' => $search],
            'counts' => [
                'mine' => (clone $mineOpen)->count(),
                'overdue' => (clone $mineOpen)->whereNotNull('due_date')->whereDate('due_date', '<', now())->count(),
                'today' => (clone $mineOpen)->whereDate('due_date', now())->count(),
            ],
            'canSeeAll' => AccessScope::for($user, 'task.viewAny') !== AccessScope::OWN,
            'statuses' => Task::STATUSES, 'types' => Task::TYPES, 'priorities' => Task::PRIORITIES,
            'users' => User::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /** Ссылка на сущность задачи: сделка, заказ цеха. */
    private function linkFor(Task $t): ?array
    {
        return match ($t->taskable_type) {
            'deal' => $t->taskable ? ['label' => $t->taskable->number, 'url' => route('deals.show', $t->taskable_id)] : null,
            'project' => $t->taskable ? ['label' => $t->taskable->number ?? ('#'.$t->taskable_id), 'url' => route('projects.show', $t->taskable_id)] : null,
            default => null,
        };
    }

    /** Быстрое переключение: открыта ↔ готово. */
    public function toggle(Request $request, Task $task): RedirectResponse
    {
        $this->authorize('update', $task);
        $this->assertTaskAccess($task);
        $this->changeStatus($task, $task->status === 'done' ? 'new' : 'done', $request->user()->id);

        return back();
    }

    /** Автосохранение полей с фронта (debounce): только то, что прислали. */
    public function autosave(Request $request, Task $task): RedirectResponse
    {
        $this->authorize('update', $task);
        $this->assertTaskAccess($task);
        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'due_date' => ['sometimes', 'nullable', 'date'],
            'priority' => ['sometimes', Rule::in(array_keys(Task::PRIORITIES))],
            'assignee_id' => ['sometimes', 'nullable', 'exists:users,id'],
        ]);
        $task->update($data);

        return back();
    }

    /** Смена статуса атомарно + событие для синхронизации и вещания. */
    private function changeStatus(Task $task, string $to, ?int $byUserId): void
    {
        $from = $task->status;
        if ($from === $to) {
            return;
        }
        DB::transaction(function () use ($task, $to) {
            $task->status = $to;
            $task->completed_at = $to === 'done' ? now() : null;
            $task->save();
            if (in_array($to, Task::CLOSED, true)) {
                NotificationResolver::taskDone($task);
            }
        });
        event(new TaskStatusUpdated($task->fresh(), $from, $to, $byUserId));
    }

    // Требуется доступ к родительской сущности задачи: сделка/проект — через can('view'),
    // личная задача (user) — только сам владелец. Не даём трогать задачи чужих сделок.
    private function assertTaskableAccess(?string $type, ?int $id): void
    {
        if (! $type || ! $id) {
            return; // личная задача создателя без привязки
        }
        if ($type === 'user') {
            abort_unless($id === request()->user()->id, 403);

            return;
        }
        $entity = $type === 'project' ? Project::find($id) : Deal::find($id);
        abort_unless($entity && request()->user()->can('view', $entity), 403);
    }

    // Доступ к существующей задаче: руководство — всё; иначе исполнитель/автор
    // или тот, кто видит родительскую сделку/проект.
    private function assertTaskAccess(Task $task): void
    {
        $user = request()->user();
        if ($user->hasAnyRole(['admin', 'director', 'financist'])) {
            return;
        }
        if ($task->assignee_id === $user->id || $task->creator_id === $user->id) {
            return;
        }
        $entity = $task->taskable;
        abort_unless($entity && $user->can('view', $entity), 403);
    }

    public function store(TaskRequest $request): RedirectResponse
    {
        $this->authorize('create', Task::class);
        $this->assertTaskableAccess($request->input('taskable_type'), (int) $request->input('taskable_id') ?: null);

        $data = $request->validated();
        $data['creator_id'] = $request->user()->id;
        $data['priority'] ??= 'medium';
        $data['status'] ??= 'new';

        $task = Task::create($data);

        if ($task->assignee_id && $task->assignee_id !== $request->user()->id) {
            $task->assignee?->notify(new TaskAssigned($task));
        }

        return back()->with('success', 'Задача создана.');
    }

    public function update(TaskRequest $request, Task $task): RedirectResponse
    {
        $this->authorize('update', $task);
        $this->assertTaskAccess($task);

        $data = $request->validated();
        // Перепривязка задачи к другой сущности запрещена — иначе можно увести её на чужую сделку.
        unset($data['taskable_type'], $data['taskable_id']);
        $task->update($data);

        return back()->with('success', 'Задача обновлена.');
    }

    public function updateStatus(Request $request, Task $task): RedirectResponse
    {
        $this->authorize('update', $task);
        $this->assertTaskAccess($task);

        $validated = $request->validate([
            'status' => ['required', Rule::in(array_keys(Task::STATUSES))],
        ]);

        // Закрытая задача гасит свои уведомления у получателей; событие
        // TaskStatusUpdated синхронизирует сделку и вещает в браузеры.
        $this->changeStatus($task, $validated['status'], $request->user()->id);

        return back()->with('success', 'Статус задачи обновлён.');
    }

    public function destroy(Task $task): RedirectResponse
    {
        $this->authorize('delete', $task);
        $this->assertTaskAccess($task);
        $task->delete();

        return back()->with('success', 'Задача удалена.');
    }
}
