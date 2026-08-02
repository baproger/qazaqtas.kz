<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

class ProjectPolicy
{
    public function viewAny(User $user): bool { return $user->can('project.viewAny'); }
    public function view(User $user, Project $p): bool
    {
        if (! $user->can('project.view')) {
            return false;
        }
        // Заказ принадлежит фирме исходной сделки — чужая фирма недоступна.
        $companyId = $p->deal?->company_id;
        if (! $user->worksInCompany($companyId ? (int) $companyId : null)) {
            return false;
        }
        // Leadership and workshop staff (observers) see the whole Цех; a manager only their own.
        if ($user->hasAnyRole(['admin', 'director', 'financist', 'employee'])) {
            return true;
        }

        return $p->responsible_user_id === $user->id || $p->deal?->responsible_user_id === $user->id;
    }
    public function create(User $user): bool { return $user->can('project.create'); }
    // update/delete требуют и права, и доступа к заказу (view уже проверяет
    // компанию/владение) — иначе через custom-fields можно было бы править
    // заказ чужой фирмы (IDOR).
    public function update(User $user, Project $p): bool { return $user->can('project.update') && $this->view($user, $p); }
    public function delete(User $user, Project $p): bool { return $user->can('project.delete') && $this->view($user, $p); }
}
