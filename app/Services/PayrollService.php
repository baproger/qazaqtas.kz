<?php

namespace App\Services;

use App\Models\Deal;
use App\Models\DealStage;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Collection;

class PayrollService
{
    /**
     * Ступенчатый бонус менеджера от маржи сделки (остаток / бюджет):
     *   маржа ≤ 10%  → бонуса нет
     *   11% – 15%    → 5% от остатка
     *   16% – 20%    → 7% от остатка
     *   21% – 30%    → 10% от остатка
     *   31% – 40%    → 13% от остатка
     *   от 41%       → 15% от остатка
     */
    public static function bonusRateForMargin(float $marginPct): float
    {
        return match (true) {
            $marginPct <= 10 => 0.0,
            $marginPct <= 15 => 0.05,
            $marginPct <= 20 => 0.07,
            $marginPct <= 30 => 0.10,
            $marginPct <= 40 => 0.13,
            default => 0.15,
        };
    }

    /** Доля партнёра сделки: только % (deals.partner_pct), сумма = % × сумма договора. */
    public static function partnerSum(float $budget, mixed $pct): float
    {
        return $pct !== null ? round($budget * (float) $pct / 100, 2) : 0.0;
    }

    /**
     * Маржа сделки для выбора ступени — ДО налога, как на карточке сделки:
     * (сумма − расходы) / сумма = (остаток + налог) / сумма.
     */
    public static function marginPct(float $budget, float $remainder, float $tax = 0): float
    {
        return $budget > 0 ? round(($remainder + $tax) / $budget * 100, 1) : 0.0;
    }

    /**
     * Эффективная ставка бонуса (доля, не %): ручной % финансиста по сделке
     * (deals.bonus_rate_override) или авто-ступень от маржи.
     */
    public static function effectiveBonusRate(float $marginPct, ?float $override = null): float
    {
        return $override !== null ? $override / 100 : self::bonusRateForMargin($marginPct);
    }

    /**
     * Bonus for one deal: remainder = budget − tax − expenses. The tier is picked
     * by the PRE-TAX margin (the one shown on the deal card) and applied to the
     * remainder. $override — ручной % финансиста по этой сделке (null = авто).
     */
    public static function marginBonus(float $budget, float $remainder, float $tax = 0, ?float $override = null): float
    {
        if ($budget <= 0 || $remainder <= 0) {
            return 0.0;
        }

        return round($remainder * self::effectiveBonusRate(self::marginPct($budget, $remainder, $tax), $override), 2);
    }

    /**
     * Canonical company-wide finance figures over WON deals — the single source of
     * truth shared by Dashboard, Analytics and Finance so every page shows the same
     * numbers. All values are factual (won stage = «Оплата успешно») and scoped to
     * the current firm.
     *
     *   budget    = Σ won-deal budgets
     *   income    = Σ payments on won deals (factual money in)
     *   expense   = Σ confirmed expenses on won deals
     *   tax       = tax_percent% of budget
     *   remainder = budget − tax − expense
     *   bonus     = Σ per-manager ЗП (only deals with a responsible manager)
     *   company   = remainder − bonus  (company net profit)
     *
     * @return array<string, float>
     */
    public function companyTotals(): array
    {
        $taxRate = ((float) Setting::get('tax_percent', 3)) / 100;
        $wonIds = Deal::won()->forCurrentCompany()->pluck('id');
        // Доход/бюджет — только по успешным (won) сделкам (факт прихода денег).
        $budget = (float) Deal::whereIn('id', $wonIds)->sum('budget');
        $income = (float) Payment::whereHas('invoice', fn ($q) => $q->where('invoiceable_type', 'deal')->whereIn('invoiceable_id', $wonIds))->sum('amount');
        // Расход — по ВСЕМ сделкам компании (не только won): деньги потрачены
        // сразу, как подтверждён расход, ещё до оплаты сделки. Иначе затраты
        // «прячутся» до won и бухгалтерия видит их задним числом.
        $expense = (float) Expense::where('status', 'confirmed')->where('expenseable_type', 'deal')
            ->whereIn('expenseable_id', Deal::forCurrentCompany()->select('id'))->sum('amount');
        $tax = round($budget * $taxRate, 2);
        // Доля партнёра (% × сумма договора) — деньги партнёру, вычитается из остатка.
        $partner = round((float) Deal::whereIn('id', $wonIds)
            ->selectRaw('COALESCE(SUM(ROUND(budget * COALESCE(partner_pct, 0) / 100, 2)), 0) s')->value('s'), 2);
        $remainder = round($budget - $tax - $expense - $partner, 2);
        $bonus = round($this->perUser()->sum('bonus'), 2);
        $company = round($remainder - $bonus, 2);

        return compact('budget', 'income', 'expense', 'tax', 'remainder', 'bonus', 'company');
    }

    /**
     * Per-deal breakdown for the payroll screen, grouped by responsible user.
     * Includes deals on «Оплата успешно» (won — counted in ЗП) and «Акт утверждение»
     * (pending — shown so the financist sees what is about to land). Each deal carries
     * the same money math as the deal card, so the ЗП figure can be verified line by line.
     *
     * @return Collection<int, Collection<int, array<string, mixed>>>  keyed by user id
     */
    public function dealBreakdown(): Collection
    {
        $taxRate = ((float) Setting::get('tax_percent', 3)) / 100;

        $stages = DealStage::where('is_active', true)->orderBy('order')->get();
        $wonStageIds = $stages->where('is_won', true)->pluck('id');
        $stageNames = $stages->pluck('name', 'id');

        // «На подходе» = сделки на Акте и ЭСФ (по stage_type, не по имени/позиции —
        // этапы переименовываются и перемещаются в настройках).
        $pendingIds = $stages->whereIn('stage_type', ['act', 'esf'])->pluck('id');
        if ($pendingIds->isEmpty() && ($fallback = $stages->slice(-2, 1)->first())) {
            $pendingIds = collect([$fallback->id]);
        }
        $stageFilter = $wonStageIds->merge($pendingIds)->unique()->all();

        $deals = Deal::forCurrentCompany()->whereNotNull('responsible_user_id')
            ->whereIn('deal_stage_id', $stageFilter)
            ->where('status', '!=', 'cancelled')
            ->orderByDesc('budget')
            ->get(['id', 'number', 'company_name', 'budget', 'partner_pct', 'bonus_rate_override', 'deal_stage_id', 'responsible_user_id', 'status']);

        $ids = $deals->pluck('id');
        $paidByDeal = Payment::query()
            ->join('invoices', 'payments.invoice_id', '=', 'invoices.id')
            ->where('invoices.invoiceable_type', 'deal')
            ->whereIn('invoices.invoiceable_id', $ids)
            ->groupBy('invoices.invoiceable_id')
            ->selectRaw('invoices.invoiceable_id as did, SUM(payments.amount) as v')->pluck('v', 'did');
        $expenseByDeal = Expense::where('status', 'confirmed')->where('expenseable_type', 'deal')
            ->whereIn('expenseable_id', $ids)
            ->groupBy('expenseable_id')->selectRaw('expenseable_id as did, SUM(amount) as v')->pluck('v', 'did');

        return $deals->map(function ($d) use ($paidByDeal, $expenseByDeal, $taxRate, $wonStageIds, $stageNames) {
            $budget = (float) $d->budget;
            $paid = (float) ($paidByDeal[$d->id] ?? 0);
            $expense = (float) ($expenseByDeal[$d->id] ?? 0);
            $tax = round($budget * $taxRate, 2);
            $partner = self::partnerSum($budget, $d->partner_pct);
            $remainder = round($budget - $tax - $expense - $partner, 2);
            // Пропорционально оплаченному — как в perUser, строки сходятся с итогом.
            $payRatio = $budget > 0 ? min(1, $paid / $budget) : 0;
            $override = $d->bonus_rate_override !== null ? (float) $d->bonus_rate_override : null;
            $bonus = round(self::marginBonus($budget, $remainder, $tax, $override) * $payRatio, 2);
            $marginPct = self::marginPct($budget, $remainder, $tax);

            return [
                'uid' => (int) $d->responsible_user_id,
                'id' => $d->id,
                'number' => $d->number,
                'company' => $d->company_name,
                'stage' => $stageNames[$d->deal_stage_id] ?? '—',
                'is_won' => $wonStageIds->contains($d->deal_stage_id),
                'budget' => $budget,
                'paid' => $paid,
                'expense' => $expense,
                'partner' => $partner,
                'tax' => $tax,
                'margin_pct' => $marginPct,
                'bonus_rate' => round(self::effectiveBonusRate($marginPct, $override) * 100, 2),
                // Ручной % финансиста (бейдж «вручную» на странице ЗП).
                'bonus_manual' => $override !== null,
                'bonus' => $bonus,
                'net' => round($remainder - $bonus, 2),
            ];
        })->groupBy('uid');
    }

    /**
     * Per-manager totals. The bonus is computed PER DEAL (each deal falls into its
     * own margin tier) and then summed — not one rate over the aggregate.
     */
    public function perUser(bool $includeAllActive = false): Collection
    {
        $taxRate = ((float) Setting::get('tax_percent', 3)) / 100;

        $deals = Deal::won()->forCurrentCompany()->whereNotNull('responsible_user_id')
            ->get(['id', 'budget', 'partner_pct', 'bonus_rate_override', 'responsible_user_id']);
        $ids = $deals->pluck('id');

        $paidByDeal = Payment::query()
            ->join('invoices', 'payments.invoice_id', '=', 'invoices.id')
            ->where('invoices.invoiceable_type', 'deal')
            ->whereIn('invoices.invoiceable_id', $ids)
            ->groupBy('invoices.invoiceable_id')
            ->selectRaw('invoices.invoiceable_id as did, SUM(payments.amount) as v')->pluck('v', 'did');
        $expenseByDeal = Expense::where('status', 'confirmed')->where('expenseable_type', 'deal')
            ->whereIn('expenseable_id', $ids)
            ->groupBy('expenseable_id')->selectRaw('expenseable_id as did, SUM(amount) as v')->pluck('v', 'did');

        $totalByUser = Deal::forCurrentCompany()->whereNotNull('responsible_user_id')
            ->groupBy('responsible_user_id')->selectRaw('responsible_user_id as uid, count(*) as c')->pluck('c', 'uid');

        $perDeal = $deals->map(function ($d) use ($paidByDeal, $expenseByDeal, $taxRate) {
            $budget = (float) $d->budget;
            $expense = (float) ($expenseByDeal[$d->id] ?? 0);
            $tax = round($budget * $taxRate, 2);
            $remainder = round($budget - $tax - $expense - self::partnerSum($budget, $d->partner_pct), 2);

            // Бонус к ВЫПЛАТЕ — пропорционально фактически оплаченной доле
            // сделки (won без полной оплаты не даёт полный бонус авансом).
            $paid = (float) ($paidByDeal[$d->id] ?? 0);
            $payRatio = $budget > 0 ? min(1, $paid / $budget) : 0;

            return [
                'uid' => (int) $d->responsible_user_id,
                'income' => $paid,
                'expense' => $expense,
                'budget' => $budget,
                'tax' => $tax,
                'remainder' => $remainder,
                'bonus' => round(self::marginBonus($budget, $remainder, $tax,
                    $d->bonus_rate_override !== null ? (float) $d->bonus_rate_override : null) * $payRatio, 2),
            ];
        })->groupBy('uid');

        // В ведомость попадают и сотрудники без сделок, но с окладом (цех, офис).
        // Страница ЗП показывает ВСЕХ активных сотрудников — финансист вводит
        // оклад/аванс/корректировку любому, даже без сделок и оклада.
        $salaryUids = $includeAllActive
            ? User::where('is_active', true)->pluck('id')
            : User::where('is_active', true)->where('salary', '>', 0)->pluck('id');
        $uids = $perDeal->keys()->merge($totalByUser->keys())->merge($salaryUids)->unique()->filter()->values();

        $people = User::whereIn('id', $uids)->get(['id', 'name', 'avatar', 'salary'])->keyBy('id');
        // Drop orphaned responsible ids (deleted users) so only real employees show.
        $uids = $uids->filter(fn ($id) => $people->has($id))->values();

        return $uids->map(function ($uid) use ($perDeal, $totalByUser, $people) {
            $rows = $perDeal[$uid] ?? collect();
            $income = (float) $rows->sum('income');
            $expense = (float) $rows->sum('expense');
            $budget = (float) $rows->sum('budget');
            $tax = round((float) $rows->sum('tax'), 2);
            $remainder = round((float) $rows->sum('remainder'), 2);
            $bonus = round((float) $rows->sum('bonus'), 2);
            $company = round($remainder - $bonus, 2);
            $margin = $budget > 0 ? round($company / $budget * 100, 1) : 0.0;

            return [
                'uid' => (int) $uid,
                'user' => $people[$uid]->name ?? '—',
                'avatar' => $people[$uid]->avatar ?? null,
                'deals' => (int) ($totalByUser[$uid] ?? 0),
                'closed' => count($rows),
                'income' => $income,
                'expense' => $expense,
                'budget' => $budget,
                'tax' => $tax,
                'remainder' => $remainder,
                'bonus' => $bonus,
                // ЗП сотрудника = оклад (из карточки сотрудника) + бонус по марже.
                'salary' => (float) ($people[$uid]->salary ?? 0),
                'payout' => round((float) ($people[$uid]->salary ?? 0) + $bonus, 2),
                'company' => $company,
                'net' => $company,
                'margin' => $margin,
            ];
        })->values();
    }
}
