<?php

namespace App\Policies;

use App\Models\Client;
use App\Models\User;
use App\Support\AccessScope;

/**
 * Справочник контрагентов ОБЩИЙ для фирм холдинга — это осознанное решение:
 * один и тот же покупатель работает с обеими фирмами, и раздвоенный
 * справочник давал бы дубли. Область доступа при этом уважается: со
 * значением «Свои» менеджер видит только своих контрагентов (и ничейных).
 */
class ClientPolicy
{
    public function viewAny(User $user): bool { return $user->can('client.viewAny'); }
    // $c nullable: контроллеры спрашивают и «можно ли в принципе»
    // (can('update', Client::class)) — тогда отвечает только право.
    public function view(User $user, ?Client $c = null): bool { return $user->can('client.view') && $this->inScope($user, $c); }
    public function create(User $user): bool { return $user->can('client.create'); }
    public function update(User $user, ?Client $c = null): bool { return $user->can('client.update') && $this->inScope($user, $c); }
    public function delete(User $user, ?Client $c = null): bool { return $user->can('client.delete') && $this->inScope($user, $c); }

    /**
     * «Свои» = ответственный он сам, плюс ничейные (без ответственного).
     * Области шире «своих» дают весь справочник: у контрагента нет отдела,
     * и granular-границы здесь строить не из чего.
     */
    private function inScope(User $user, ?Client $c): bool
    {
        if ($c === null || AccessScope::for($user, 'client.viewAny') !== AccessScope::OWN) {
            return true;
        }

        return $c->responsible_user_id === null || $c->responsible_user_id === $user->id;
    }
}
