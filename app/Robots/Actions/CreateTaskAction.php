<?php

namespace App\Robots\Actions;

use App\Models\Deal;
use App\Models\StageRobotRun;
use App\Models\User;
use App\Notifications\TaskAssigned;
use App\Robots\RobotActionInterface;
use App\Support\RoleTraits;

class CreateTaskAction implements RobotActionInterface
{
    public static function type(): string
    {
        return 'create_task';
    }

    public static function label(): string
    {
        return 'Поставить задачу';
    }

    public static function schema(): array
    {
        return [
            ['key' => 'title', 'label' => 'Название задачи', 'type' => 'text', 'required' => true],
            ['key' => 'description', 'label' => 'Описание', 'type' => 'textarea'],
            ['key' => 'assignee', 'label' => 'Кому', 'type' => 'select', 'options' => ['responsible' => 'Ответственному за сделку', 'foreman' => 'Бригадиру сделки', 'role' => 'Всем с ролью…'], 'required' => true],
            ['key' => 'roles', 'label' => 'Роли (если «Всем с ролью»)', 'type' => 'roles'],
            ['key' => 'days', 'label' => 'Срок, дней', 'type' => 'number', 'required' => true],
            ['key' => 'priority', 'label' => 'Приоритет', 'type' => 'select', 'options' => ['low' => 'Низкий', 'normal' => 'Обычный', 'high' => 'Высокий']],
        ];
    }

    public function handle(Deal $deal, array $payload, StageRobotRun $run): array
    {
        $assignees = match ($payload['assignee'] ?? 'responsible') {
            'foreman' => collect([$deal->foreman_id ? User::find($deal->foreman_id) : null]),
            'role' => RoleTraits::users((array) ($payload['roles'] ?? []))->where('is_active', true)->get(),
            default => collect([$deal->responsible]),
        };
        $ids = [];
        foreach ($assignees->filter() as $u) {
            $task = $deal->tasks()->create([
                'title' => (string) $payload['title'], 'description' => $payload['description'] ?? null,
                'status' => 'new', 'priority' => $payload['priority'] ?? 'normal',
                'assignee_id' => $u->id, 'creator_id' => $deal->responsible_user_id ?? $u->id,
                'start_date' => now(), 'due_date' => now()->addDays(max(0, (int) ($payload['days'] ?? 1))),
            ]);
            $u->notify(new TaskAssigned($task));
            $ids[] = $task->id;
        }

        return ['tasks' => $ids];
    }
}
