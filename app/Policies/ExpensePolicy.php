<?php

namespace App\Policies;

use App\Models\Expense;
use App\Models\User;

class ExpensePolicy
{
    public function viewAny(User $u): bool { return $u->can('expense.viewAny'); }
    public function view(User $u, Expense $e): bool { return $u->can('expense.view'); }
    public function create(User $u): bool { return $u->can('expense.create'); }
    public function update(User $u, Expense $e): bool { return $u->can('expense.update'); }
    public function delete(User $u, Expense $e): bool { return $u->can('expense.delete'); }
}
