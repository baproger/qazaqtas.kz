<?php

namespace App\Http\Controllers;

use App\Http\Requests\TaskRequest;
use App\Models\Deal;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    // Tasks are managed inline inside deal/project cards (TaskPanel). There is no
    // standalone tasks board, so only the mutation endpoints below are exposed.

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
            $task->assignee?->notify(new \App\Notifications\TaskAssigned($task));
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
            'status' => ['required', 'in:new,in_progress,review,done'],
        ]);

        $task->status = $validated['status'];
        $task->completed_at = $validated['status'] === 'done' ? now() : null;
        $task->save();

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
