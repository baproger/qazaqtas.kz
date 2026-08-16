<?php

namespace App\Policies;

use App\Models\Expense;
use App\Models\User;

/**
 * Права на расходы.
 *
 * Ключевые запреты дублируются ролями прямо здесь, а не держатся только на
 * permissions: право можно вернуть через админку по неосторожности, а деньги
 * ошибок не прощают — правило должно пережить такую правку.
 */
class ExpensePolicy
{
    /** Кто распоряжается деньгами: подтверждает, правит подтверждённое, удаляет. */
    private function isAccountant(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'financist']);
    }

    public function viewAny(User $u): bool
    {
        return $u->can('expense.viewAny');
    }

    public function view(User $u, Expense $e): bool
    {
        return $u->can('expense.view');
    }

    public function create(User $u): bool
    {
        return $u->can('expense.create');
    }

    /**
     * Правка.
     *
     * Подтверждённый бухгалтером расход заморожен для автора: чек приложен,
     * способ выбран, деньги ушли из кассы — менять сумму задним числом
     * нельзя. Иначе запрет на удаление обходился бы правкой суммы на 1 ₸.
     *
     * Признак заморозки — confirmed_by, а НЕ status: списание материала со
     * склада система проводит сама (status=confirmed, confirmed_by пустой,
     * бухгалтер его не видел) — для правки оно не заморожено.
     *
     * Чужую заявку компании не-бухгалтер не правит.
     */
    public function update(User $u, Expense $e): bool
    {
        if (! $u->can('expense.update')) {
            return false;
        }

        if ($this->isAccountant($u)) {
            return true;
        }

        if ($e->confirmed_by !== null) {
            return false;
        }

        // Свой черновик автор правит; чужой — нет.
        return $e->responsible_user_id === null || $e->responsible_user_id === $u->id;
    }

    /**
     * Удаление — только бухгалтер и админ, любые расходы, включая ещё не
     * подтверждённые.
     *
     * Следствие: менеджер, ошибившийся в материальном списании, не удаляет
     * его сам — просит бухгалтера (остаток вернётся на склад) и заводит
     * заново. Это осознанная цена: удаление расхода двигает деньги и склад.
     */
    public function delete(User $u, Expense $e): bool
    {
        return $this->isAccountant($u) && $u->can('expense.delete');
    }
}
