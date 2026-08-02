<?php

namespace App\Policies;

use App\Models\Deal;
use App\Models\User;

class DealPolicy
{
    public function viewAny(User $user): bool { return $user->can('deal.viewAny'); }
    public function view(User $user, Deal $d): bool { return $user->can('deal.view') && $this->ownsOrLeads($user, $d); }
    public function create(User $user): bool { return $user->can('deal.create'); }
    public function update(User $user, Deal $d): bool { return $this->ownsOrLeads($user, $d) && ($user->can('deal.update') || $d->responsible_user_id === $user->id); }
    // Удаление сделки — ТОЛЬКО админ (менеджеру/директору/бухгалтеру нельзя).
    public function delete(User $user, Deal $d): bool { return $user->hasRole('admin') && $this->ownsOrLeads($user, $d); }

    /**
     * «Далее →» (следующий этап). Всем, кто может править сделку, — как обычно;
     * плюс ДИЗАЙНЕРУ, но только когда сделка стоит на ЕГО гейт-этапе
     * «Дизайн и расчет» (stage_type=design): выполнил работу — сам отправил
     * дальше, не дожидаясь менеджера. Гейт-галочка всё равно проверяется.
     */
    public function advance(User $user, Deal $d): bool
    {
        if ($this->update($user, $d)) {
            return true;
        }

        return $user->hasRole('designer')
            && $this->ownsOrLeads($user, $d)
            && $d->stage?->stage_type === 'design';
    }

    /**
     * Leadership sees everything WITHIN ITS COMPANIES; a manager is limited to
     * deals they are responsible for. Сделка чужой фирмы недоступна
     * по прямой ссылке даже руководству, не привязанному к той компании.
     */
    private function ownsOrLeads(User $user, Deal $d): bool
    {
        if (! $user->worksInCompany($d->company_id ? (int) $d->company_id : null)) {
            return false;
        }

        // Технолог и снабженец видят сделки компании — они подтверждают
        // гейт-этапы; править/удалять не могут (нет deal.update, см. update()).
        return $user->hasAnyRole(['admin', 'director', 'financist', 'designer', 'supplier']) || $d->responsible_user_id === $user->id;
    }
}
