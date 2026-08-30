<?php

namespace App\Policies;

use App\Models\Service;
use App\Models\User;

/**
 * Партнёр — строго свои услуги (IDOR закрыт: чужая заявка недоступна и по
 * прямому id). Модерация — ассистент и админ (админ проходит Gate::before).
 */
class ServicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['partner', 'assistant', 'admin']);
    }

    public function view(User $user, Service $service): bool
    {
        return $service->partner_id === $user->id || $this->moderate($user);
    }

    public function create(User $user): bool
    {
        return $user->hasRole('partner');
    }

    public function update(User $user, Service $service): bool
    {
        return $service->partner_id === $user->id;
    }

    public function delete(User $user, Service $service): bool
    {
        return $service->partner_id === $user->id || $this->moderate($user);
    }

    public function moderate(User $user): bool
    {
        return $user->hasRole('assistant');
    }
}
