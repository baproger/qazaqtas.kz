<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $u): bool { return $u->can('user.viewAny'); }
    public function view(User $u, User $target): bool { return $u->can('user.view'); }
    public function create(User $u): bool { return $u->can('user.create'); }
    public function update(User $u, User $target): bool { return $u->can('user.update'); }
    public function delete(User $u, User $target): bool { return $u->can('user.delete') && $u->id !== $target->id; }
}
