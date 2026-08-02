<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\User;

class TaskPolicy
{
    public function viewAny(User $user): bool { return $user->can('task.viewAny'); }
    public function view(User $user, Task $t): bool { return $user->can('task.view'); }
    public function create(User $user): bool { return $user->can('task.create'); }

    // Исполнитель/автор — всегда; иначе право + доступ к родительской сделке/
    // заказу (изоляция фирм). Без этого через custom-fields можно было бы
    // править задачу чужой компании (IDOR), т.к. task.update выдан широко.
    public function update(User $user, Task $t): bool
    {
        if ($t->assignee_id === $user->id || $t->creator_id === $user->id) {
            return true;
        }
        $entity = $t->taskable;

        return $entity
            ? ($user->can('task.update') && $user->can('view', $entity))
            : $user->can('task.update');
    }

    public function delete(User $user, Task $t): bool
    {
        if ($t->creator_id === $user->id) {
            return true;
        }
        $entity = $t->taskable;

        return $entity
            ? ($user->can('task.delete') && $user->can('view', $entity))
            : $user->can('task.delete');
    }
}
