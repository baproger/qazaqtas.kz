<?php

namespace App\Http\Controllers;

use App\Models\BonusPayout;
use App\Models\User;
use App\Services\BonusPayoutService;
use App\Support\FinanceAudit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «Бонусы» — год целиком: сколько начислено в каждом месяце, что уже
 * выплачено и сколько человек накопил.
 *
 * Одна месячная цифра отвечала лишь на вопрос «сколько заработано в этом
 * месяце» и ничего не говорила о том, сколько сотруднику ещё должны. Копить
 * бонус и забирать его разом — обычная у нас практика, и её должно быть
 * видно.
 */
class BonusController extends Controller
{
    private function guard(Request $request): void
    {
        abort_unless($request->user()->can('payroll.view'), 403);
    }

    private function canPay(Request $request): bool
    {
        return $request->user()->hasAnyRole(['admin', 'financist']);
    }

    public function index(Request $request, BonusPayoutService $bonuses): Response
    {
        $this->guard($request);

        $year = (int) ($request->integer('year') ?: now()->year);
        $leadership = $request->user()->hasAnyRole(['admin', 'director', 'financist']);

        // Сотрудник видит только свою строку — чужие бонусы не его дело.
        $users = User::where('is_active', true)
            ->when(! $leadership, fn ($q) => $q->whereKey($request->user()->id))
            ->orderBy('name')->get(['id', 'name', 'avatar']);

        $rows = collect($bonuses->yearFor($users, $year))
            // Сотрудники без единого начисления и выплаты только шумят.
            ->filter(fn ($r) => $r['accrued'] > 0 || $r['paid'] > 0)
            ->values();

        return Inertia::render('Finance/Bonuses', [
            'year' => $year,
            'rows' => $rows,
            'totals' => [
                'accrued' => round((float) $rows->sum('accrued'), 2),
                'paid' => round((float) $rows->sum('paid'), 2),
                'left' => round((float) $rows->sum('left'), 2),
            ],
            'canPay' => $this->canPay($request),
            // История выплат — чтобы было видно, когда бонус реально забрали.
            'payouts' => BonusPayout::whereIn('user_id', $rows->pluck('uid'))
                ->with('user:id,name')->latest('id')->limit(50)->get()
                ->map(fn ($p) => [
                    'id' => $p->id,
                    'user' => $p->user?->name,
                    'month' => $p->month,
                    'amount' => (float) $p->amount,
                    'method' => $p->payment_method,
                    'date' => $p->created_at?->toDateString(),
                ]),
        ]);
    }

    /** Выплата бонуса за выбранные месяцы: деньги уходят из кассы или банка. */
    public function pay(Request $request, BonusPayoutService $bonuses): RedirectResponse
    {
        abort_unless($this->canPay($request), 403, 'Бонус выплачивает бухгалтер или админ.');

        $data = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'months' => ['required', 'array', 'min:1'],
            'months.*' => ['regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'payment_method' => ['required', Rule::in(['cash', 'bank'])],
            'note' => ['nullable', 'string', 'max:255'],
        ], [
            'months.required' => 'Выберите месяцы, за которые выплачивается бонус.',
            'payment_method.required' => 'Выберите, откуда выдаются деньги: касса или банк.',
        ]);

        $employee = User::findOrFail($data['user_id']);
        // Изоляция фирм: бонус платят своим сотрудникам, как оклад и долг.
        $companies = $employee->companies()->pluck('companies.id');
        abort_unless(
            $companies->isEmpty() || $companies->contains(fn ($id) => $request->user()->worksInCompany((int) $id)),
            403,
            'Сотрудник другой фирмы.'
        );

        $result = $bonuses->pay($employee, $data['months'], $data['payment_method'], $request->user(), $data['note'] ?? null);

        if ($result['months'] === 0) {
            return back()->with('error', 'За выбранные месяцы бонус уже выплачен.');
        }

        return back()->with('success', 'Бонус выплачен: '
            .number_format($result['paid'], 0, '.', ' ').' ₸ за '.$result['months'].' мес.');
    }

    /** Отмена выплаты: строка и её расход уходят вместе — деньги вернулись. */
    public function destroy(Request $request, BonusPayout $payout): RedirectResponse
    {
        abort_unless($this->canPay($request), 403, 'Выплату отменяет бухгалтер или админ.');

        \Illuminate\Support\Facades\DB::transaction(function () use ($payout) {
            \App\Models\Expense::find($payout->expense_id)?->delete();
            $payout->delete();
        });

        FinanceAudit::notifyDeleted('Выплата бонуса на '
            .number_format((float) $payout->amount, 0, '.', ' ').' ₸ за '.$payout->month);

        return back()->with('success', 'Выплата бонуса отменена — деньги вернулись в кассу.');
    }
}
