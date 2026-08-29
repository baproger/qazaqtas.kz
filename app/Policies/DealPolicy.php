<?php

namespace App\Policies;

use App\Models\Deal;
use App\Models\User;
use App\Support\AccessScope;

class DealPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('deal.viewAny');
    }

    public function view(User $user, Deal $d): bool
    {
        return $user->can('deal.view') && $this->ownsOrLeads($user, $d, 'deal.view');
    }

    public function create(User $user): bool
    {
        return $user->can('deal.create');
    }

    public function update(User $user, Deal $d): bool
    {
        return $this->ownsOrLeads($user, $d, 'deal.update') && ($user->can('deal.update') || $d->responsible_user_id === $user->id);
    }

    // Удаление сделки — ТОЛЬКО админ (менеджеру/директору/бухгалтеру нельзя).
    public function delete(User $user, Deal $d): bool
    {
        return $user->hasRole('admin') && $this->ownsOrLeads($user, $d);
    }

    /**
     * «Далее →» и смена этапа. Всем, кто может править сделку, — как обычно;
     * плюс ДИЗАЙНЕРУ на его гейт-этапе «Дизайн и расчет» (выполнил работу —
     * сам отправил дальше) и БРИГАДИРУ, назначенному на эту сделку: он ведёт
     * её в цехе и двигает по этапам, хотя саму сделку не правит и денег не
     * видит. Гейт-галочки и запреты этапов (Акт/ЭСФ/Оплата — только
     * бухгалтер) проверяются отдельно, в StageTransitionService.
     */
    public function advance(User $user, Deal $d): bool
    {
        if ($this->update($user, $d)) {
            return true;
        }

        if ($this->isForemanOf($user, $d)) {
            return true;
        }

        // Правило этапа «также могут двигать»: технолог на замере и любая
        // роль, которую владелец добавил в конструкторе логики этапа.
        $extra = $d->stage?->effectiveRules()['extra_movers'] ?? [];

        return $extra !== []
            && $user->hasAnyRole($extra)
            && $this->ownsOrLeads($user, $d);
    }

    /**
     * Перенос сделки на ПРОИЗВОЛЬНЫЙ этап (перетаскивание, выбор в карточке).
     *
     * Это не то же самое, что «Далее»: технологу разрешён только шаг вперёд
     * со своего гейта, а таскать сделку по воронке он не должен. Бригадир —
     * должен: он ведёт её в цехе и возвращает назад, если работа не принята.
     */
    public function moveStage(User $user, Deal $d): bool
    {
        return $this->update($user, $d) || $this->isForemanOf($user, $d);
    }

    /** Назначенный бригадир этой сделки. */
    private function isForemanOf(User $user, Deal $d): bool
    {
        return $user->hasRole('foreman')
            && $d->foreman_id !== null
            && (int) $d->foreman_id === $user->id
            && $user->worksInCompany($d->company_id ? (int) $d->company_id : null);
    }

    /**
     * Leadership sees everything WITHIN ITS COMPANIES; a manager is limited to
     * deals they are responsible for. Сделка чужой фирмы недоступна
     * по прямой ссылке даже руководству, не привязанному к той компании.
     */
    private function ownsOrLeads(User $user, Deal $d, string $permission = 'deal.view'): bool
    {
        if (! $user->worksInCompany($d->company_id ? (int) $d->company_id : null)) {
            return false;
        }

        // Технолог и снабженец видят сделки компании — они подтверждают
        // гейт-этапы; править/удалять не могут (нет deal.update, см. update()).
        // Бригадир видит ТОЛЬКО те сделки, на которые его назначили: чужие
        // объекты не его дело.
        if ($user->hasAnyRole(['admin', 'director', 'financist', 'designer', 'supplier'])
            || $d->responsible_user_id === $user->id
            || ($user->hasRole('foreman') && (int) $d->foreman_id === $user->id)) {
            return true;
        }

        // Область доступа из Настроек → Права (§3): «отдела», «отдела и
        // подчинённых», «все». Список сделок её уже учитывал, а карточка —
        // нет: руководитель отдела видел сделку подчинённого в списке и
        // получал 403, открыв её.
        return $this->inScope($user, $d, $permission);
    }

    /** Сделка внутри области доступа человека по этому праву. */
    private function inScope(User $user, Deal $d, string $permission): bool
    {
        $scope = AccessScope::for($user, $permission);

        if ($scope === AccessScope::ALL) {
            return true;
        }
        if ($scope === AccessScope::DEPARTMENT || $scope === AccessScope::DEPARTMENT_TREE) {
            return $d->responsible_user_id !== null
                && in_array((int) $d->responsible_user_id, AccessScope::peerIds($user, $scope), true);
        }

        return false;
    }
}
