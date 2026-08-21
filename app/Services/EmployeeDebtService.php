<?php

namespace App\Services;

use App\Models\EmployeeDebt;
use App\Models\EmployeeDebtPayment;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Долги сотрудников: план удержания и его исполнение.
 *
 * Правило одно и живёт здесь: удерживаем `min(платёж в месяц, бонус месяца,
 * остаток долга)` и ТОЛЬКО из бонуса — оклад не трогаем никогда. Нет бонуса
 * в месяце → нет записи, остаток целиком едет в следующий. Ведомость ЗП
 * показывает план этим же методом, поэтому «удержим −Y» в строке и то, что
 * реально спишет команда, — одно и то же число.
 */
class EmployeeDebtService
{
    public function __construct(private BonusPayoutService $bonuses) {}

    /**
     * План удержания сотрудника за месяц (плюс сами долги — для ведомости).
     *
     * @return array{balance: float, monthly: float, charge: float, bonus: float, items: array<int, array<string, mixed>>}
     */
    public function planFor(int $userId, string $month): array
    {
        return $this->planForUsers([$userId], $month)[$userId]
            ?? ['balance' => 0.0, 'monthly' => 0.0, 'charge' => 0.0, 'bonus' => 0.0, 'items' => []];
    }

    /**
     * Планы сразу по нескольким сотрудникам — для ведомости ЗП.
     *
     * Поштучный вызов давал по три запроса на каждого должника: ведомость с
     * десятком долгов превращалась в тридцать лишних запросов. Здесь долги
     * берутся одним запросом, бонусы — одним батчем.
     *
     * @param  array<int, int>|Collection<int, int>  $userIds
     * @return array<int, array{balance: float, monthly: float, charge: float, bonus: float, items: array<int, array<string, mixed>>}>
     */
    public function planForUsers($userIds, string $month): array
    {
        $userIds = collect($userIds)->map(fn ($id) => (int) $id)->unique()->values();
        if ($userIds->isEmpty()) {
            return [];
        }

        $byUser = EmployeeDebt::open()->whereIn('user_id', $userIds)
            ->orderBy('created_at')->orderBy('id')->with('payments')->get()
            ->groupBy('user_id');
        if ($byUser->isEmpty()) {
            return [];
        }

        // Весь бонус месяца: сделки и выработка цеха вместе — план удержания
        // обязан совпадать с тем, что реально спишет `debts:charge`.
        $bonuses = $this->bonuses->accrualsByMonths($byUser->keys(), [$month])[$month] ?? [];

        $plans = [];
        foreach ($byUser as $userId => $debts) {
            $plans[(int) $userId] = $this->plan($debts, (float) ($bonuses[(int) $userId] ?? 0), $month);
        }

        return $plans;
    }

    /**
     * Разложить бонус месяца по открытым долгам — старые гасятся первыми.
     *
     * @param  Collection<int, EmployeeDebt>  $debts
     * @return array{balance: float, monthly: float, charge: float, bonus: float, items: array<int, array<string, mixed>>}
     */
    private function plan($debts, float $bonus, string $month): array
    {
        $left = $bonus;
        $charge = 0.0;
        $balance = 0.0;
        $monthly = 0.0;
        $items = [];

        foreach ($debts as $debt) {
            $debtBalance = $debt->balance();
            $balance += $debtBalance;
            $monthly += (float) $debt->monthly_payment;

            // Месяц уже погашен (команда отработала) — второй раз не планируем.
            $done = $debt->payments->firstWhere('month', $month);
            $take = $done ? 0.0 : round(min((float) $debt->monthly_payment, $left, $debtBalance), 2);
            if ($take > 0) {
                $charge += $take;
                $left -= $take;
            }

            $items[] = [
                'id' => $debt->id,
                'amount' => (float) $debt->amount,
                'balance' => $debtBalance,
                'monthly' => (float) $debt->monthly_payment,
                'charge' => max($take, 0.0),
                'note' => $debt->note,
                'method' => $debt->payment_method,
                'date' => $debt->created_at?->toDateString(),
                // Выдачу с удержаниями отменить уже нельзя — списания прошли.
                'has_payments' => $debt->payments->isNotEmpty(),
            ];
        }

        return [
            'balance' => round($balance, 2),
            'monthly' => round($monthly, 2),
            'charge' => round($charge, 2),
            'bonus' => round($bonus, 2),
            'items' => $items,
        ];
    }

    /**
     * Списать удержания за месяц по всем открытым долгам.
     *
     * Идемпотентность держится на unique(долг, месяц) в БД, а не на условии в
     * коде: два параллельных прогона не спишут дважды, потому что второй
     * insert не пройдёт.
     *
     * @return array{charged: int, sum: float, closed: int}
     */
    public function chargeMonth(string $month): array
    {
        $charged = 0;
        $closed = 0;
        $sum = 0.0;

        $byUser = EmployeeDebt::open()->orderBy('created_at')->orderBy('id')
            ->with('payments')->get()->groupBy('user_id');

        foreach ($byUser as $userId => $debts) {
            // Долг гасится из ВСЕГО бонуса месяца — и со сделок, и за
            // выработку цеха: для сотрудника это одни и те же деньги.
            $left = (float) ($this->bonuses
                ->accrualsByMonths([(int) $userId], [$month])[$month][(int) $userId] ?? 0);

            foreach ($debts as $debt) {
                if ($left <= 0) {
                    break;
                }
                if ($debt->payments->firstWhere('month', $month)) {
                    continue;
                }

                $take = round(min((float) $debt->monthly_payment, $left, $debt->balance()), 2);
                if ($take <= 0) {
                    continue;
                }

                try {
                    DB::transaction(function () use ($debt, $month, $take, &$closed) {
                        EmployeeDebtPayment::create([
                            'employee_debt_id' => $debt->id,
                            'month' => $month,
                            'amount' => $take,
                        ]);

                        if ($debt->fresh()->balance() <= 0) {
                            $debt->update(['closed_at' => now()]);
                            $closed++;
                        }
                    });
                } catch (UniqueConstraintViolationException) {
                    // Этот месяц по долгу уже списан — повторный прогон молчит.
                    continue;
                }

                $left -= $take;
                $sum += $take;
                $charged++;
            }
        }

        return ['charged' => $charged, 'sum' => round($sum, 2), 'closed' => $closed];
    }
}
