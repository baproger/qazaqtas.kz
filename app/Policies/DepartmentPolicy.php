<?php

namespace App\Policies;

use App\Models\Department;
use App\Models\User;

class DepartmentPolicy
{
    public function viewAny(User $user): bool { return $user->can('department.viewAny'); }
    public function view(User $user, Department $d): bool { return $user->can('department.view'); }
    public function create(User $user): bool { return $user->can('department.create'); }
    public function update(User $user, Department $d): bool { return $user->can('department.update'); }
    public function delete(User $user, Department $d): bool { return $user->can('department.delete'); }
}
