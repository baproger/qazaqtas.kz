<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;
use App\Support\RoleTraits;

class ProjectPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('project.viewAny');
    }

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
        // Бригадир — цеховая роль: он ведёт бригаду на этом же заказе и должен
        // открыть карточку, чтобы увидеть, что и к какому сроку делать. Город
        // ограничивает assertWorkshopAccess (users.workshops), деньги —
        // canSeeMoney: сумм в карточке ему не приходит.
        if (RoleTraits::seesWholeWorkshop($user)) {
            return true;
        }

        return $p->responsible_user_id === $user->id || $p->deal?->responsible_user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->can('project.create');
    }

    // update/delete требуют и права, и доступа к заказу (view уже проверяет
    // компанию/владение) — иначе через custom-fields можно было бы править
    // заказ чужой фирмы (IDOR).
    public function update(User $user, Project $p): bool
    {
        return $user->can('project.update') && $this->view($user, $p);
    }

    public function delete(User $user, Project $p): bool
    {
        return $user->can('project.delete') && $this->view($user, $p);
    }
}
