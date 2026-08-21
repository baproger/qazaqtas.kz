<?php

namespace App\Services;

use App\Models\BonusPayout;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\DB;

/**
 * Бонус по месяцам: начислено, выплачено, накоплено.
 *
 * Сотрудник не обязан забирать бонус каждый месяц — он может копить и забрать
 * разом. Поэтому «сколько ему должны» — это не бонус месяца, а начисленное
 * минус выплаченное за всё время.
 *
 * Начисление считает PayrollService (по датам оплат клиентом), выплату —
 * этот сервис: он же создаёт расход, чтобы деньги ушли из кассы честно, как
 * при авансе и долге.
 */
class BonusPayoutService
{
    public function __construct(private PayrollService $payroll) {}

    /**
     * Годовая картина по сотрудникам: 12 месяцев начислений, выплаты и остаток.
     *
     * @param  \Illuminate\Support\Collection<int, User>  $users
     * @return array<int, array<string, mixed>>
     */
    public function yearFor($users, int $year): array
    {
        $ids = $users->pluck('id');
        $accrued = $this->payroll->bonusYear($ids, $year);
        $months = array_keys($accrued);

        // Выплаты берём за ВСЁ время, а не только за год: бонус за декабрь
        // прошлого года могли выдать в феврале этого, и остаток обязан это
        // учитывать.
        $payouts = BonusPayout::whereIn('user_id', $ids)->get()
            ->groupBy('user_id');

        $rows = [];
        foreach ($users as $user) {
            $paid = ($payouts[$user->id] ?? collect())->groupBy('month')
                ->map(fn ($group) => round((float) $group->sum('amount'), 2));

            $cells = [];
            $yearAccrued = 0.0;
            $yearPaid = 0.0;
            foreach ($months as $month) {
                $monthAccrued = round((float) ($accrued[$month][$user->id] ?? 0), 2);
                $monthPaid = round((float) ($paid[$month] ?? 0), 2);
                $yearAccrued += $monthAccrued;
                $yearPaid += $monthPaid;

                $cells[] = [
                    'month' => $month,
                    'accrued' => $monthAccrued,
                    'paid' => $monthPaid,
                    // Остаток месяца: отрицательным не бывает — выдали больше
                    // начисленного, значит остальное закрыли из других месяцев.
                    'left' => round(max($monthAccrued - $monthPaid, 0), 2),
                ];
            }

            $rows[] = [
                'uid' => $user->id,
                'name' => $user->name,
                'avatar' => $user->avatar,
                'months' => $cells,
                'accrued' => round($yearAccrued, 2),
                'paid' => round($yearPaid, 2),
                'left' => round(max($yearAccrued - $yearPaid, 0), 2),
            ];
        }

        return $rows;
    }

    /**
     * Выплатить бонус за перечисленные месяцы.
     *
     * Больше начисленного не выдаём: остаток месяца пересчитывается перед
     * выдачей, иначе двойной клик выдал бы бонус дважды.
     *
     * @param  array<int, string>  $months  YYYY-MM
     * @return array{paid: float, months: int}
     */
    public function pay(User $employee, array $months, string $method, ?User $actor = null, ?string $note = null): array
    {
        $months = array_values(array_unique($months));
        $accrued = $this->payroll->bonusByMonths([$employee->id], $months);
        $alreadyPaid = BonusPayout::where('user_id', $employee->id)
            ->whereIn('month', $months)->get()->groupBy('month')
            ->map(fn ($g) => round((float) $g->sum('amount'), 2));

        $companyId = CurrentCompany::id() ?: $employee->companies()->value('companies.id');
        $paidTotal = 0.0;
        $paidMonths = 0;

        DB::transaction(function () use ($employee, $months, $method, $actor, $note, $accrued, $alreadyPaid, $companyId, &$paidTotal, &$paidMonths) {
            $category = ExpenseCategory::firstOrCreate(
                ['code' => ExpenseCategory::EMPLOYEE],
                ['name' => 'Расходы по сотрудникам', 'is_active' => true]
            );

            foreach ($months as $month) {
                $left = round((float) ($accrued[$month][$employee->id] ?? 0) - (float) ($alreadyPaid[$month] ?? 0), 2);
                if ($left <= 0) {
                    continue;
                }

                // Деньги уходят по-настоящему: подтверждённый расход, как у
                // аванса и долга. Касса и банк уменьшаются сразу.
                $expense = Expense::create([
                    'company_id' => $companyId,
                    'category_id' => $category->id,
                    'type' => 'direct',
                    'amount' => $left,
                    'date' => now()->toDateString(),
                    'description' => 'Бонус сотруднику: '.$employee->name.' за '.$month
                        .($note ? ' — '.$note : ''),
                    'responsible_user_id' => $employee->id,
                    'employee_id' => $employee->id,
                    'employee_payout' => 'bonus',
                    'status' => 'confirmed',
                    'payment_method' => $method,
                    'confirmed_by' => $actor?->id,
                    'confirmed_at' => now(),
                ]);

                BonusPayout::create([
                    'user_id' => $employee->id,
                    'company_id' => $companyId,
                    'month' => $month,
                    'amount' => $left,
                    'payment_method' => $method,
                    'expense_id' => $expense->id,
                    'paid_by' => $actor?->id,
                    'note' => $note,
                ]);

                $paidTotal += $left;
                $paidMonths++;
            }
        });

        return ['paid' => round($paidTotal, 2), 'months' => $paidMonths];
    }

    /** Сколько бонуса выплачено сотрудникам за всё время (для ведомости ЗП). */
    public function paidTotals($userIds): \Illuminate\Support\Collection
    {
        return BonusPayout::whereIn('user_id', collect($userIds))
            ->groupBy('user_id')
            ->selectRaw('user_id, SUM(amount) as total')
            ->pluck('total', 'user_id')
            ->map(fn ($v) => round((float) $v, 2));
    }
}
