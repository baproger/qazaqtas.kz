<?php

namespace App\Policies;

use App\Models\DealItem;
use App\Models\User;

/**
 * Позиция сделки прав своих не имеет — их даёт сделка.
 *
 * Так к фото позиции сами собой применяются все правила сделки: менеджер
 * видит свои, бригадир — назначенные ему, чужая фирма закрыта. Разойдись эти
 * проверки, и снимок «Плитки Ромб» открывался бы по прямой ссылке тому, кому
 * сама сделка недоступна.
 */
class DealItemPolicy
{
    public function view(User $user, DealItem $item): bool
    {
        return $item->deal !== null && $user->can('view', $item->deal);
    }

    /** Прикрепить фото к позиции может тот, кто ведёт заказ: продажи и цех. */
    public function update(User $user, DealItem $item): bool
    {
        return $this->view($user, $item);
    }
}
