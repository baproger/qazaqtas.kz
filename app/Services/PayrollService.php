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
    /** Своё производство — базовая ставка бонуса. */
    public const TYPE_PRODUCTION = 'production';

    /** Перепродажа (купили → склад → продали) — своя ставка. */
    public const TYPE_RESALE = 'resale';

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
     * Эффективная ставка бонуса (доля, не %). Приоритет — от частного к общему:
     *   1. ручной % по конкретной сделке (deals.bonus_rate_override);
     *   2. личный % сотрудника (users.bonus_percent) — основной режим;
     *   3. авто-ступень от маржи — только если у сотрудника % не задан,
     *      чтобы старые расчёты не обнулились.
     */
    public static function effectiveBonusRate(float $marginPct, ?float $override = null, ?float $userPercent = null): float
    {
        if ($override !== null) {
            return $override / 100;
        }

        if ($userPercent !== null) {
            return $userPercent / 100;
        }

        return self::bonusRateForMargin($marginPct);
    }

    /**
     * Bonus for one deal: remainder = budget − tax − expenses. The tier is picked
     * by the PRE-TAX margin (the one shown on the deal card) and applied to the
     * remainder. $override — ручной % финансиста по этой сделке (null = авто).
     */
    public static function marginBonus(
        float $budget,
        float $remainder,
        float $tax = 0,
        ?float $override = null,
        ?float $userPercent = null,
    ): float {
        if ($budget <= 0 || $remainder <= 0) {
            return 0.0;
        }

        // Процент считается от ЧИСТОГО ОСТАТКА сделки (после налога и расходов).
        $rate = self::effectiveBonusRate(self::marginPct($budget, $remainder, $tax), $override, $userPercent);

        return round($remainder * $rate, 2);
    }

    /**
     * Бонус менеджера по сделке — ЕДИНАЯ точка расчёта.
     *
     * Ставка зависит от ТИПА сделки: своё производство — один процент,
     * перепродажа — другой (правило владельца от 21.08.2026, заменило
     * ступенчатую шкалу от маржи). Считается от ОСТАТКА сделки: сумма минус
     * налог, расходы и доля партнёра — то есть с того, что сделка реально
     * принесла, а не с оборота.
     *
     * Ручной % по сделке и личный % сотрудника остаются переопределением
     * ставки: сначала ручной, затем личный, затем ставка типа.
     *
     * @param  float  $remainder  остаток сделки (сумма − налог − расходы − партнёр)
     * @param  string  $dealType  production | resale
     * @return array{rate: float, total: float}
     */
    public static function dealBonus(
        float $remainder,
        ?float $override = null,
        ?float $userPercent = null,
        string $dealType = self::TYPE_PRODUCTION,
    ): array {
        $rate = $override ?? $userPercent ?? self::rateForType($dealType);

        // Убыточная сделка бонуса не приносит: платить процент от минуса не с
        // чего, а отрицательный бонус превратился бы в удержание.
        $total = $remainder > 0 ? round($remainder * $rate / 100, 2) : 0.0;

        return ['rate' => (float) $rate, 'total' => $total];
    }

    /** Ставка бонуса по типу сделки — из настроек, без правки кода. */
    public static function rateForType(string $dealType): float
    {
        return $dealType === self::TYPE_RESALE
            ? (float) Setting::get('bonus_resale_percent', 2)
            : (float) Setting::get('bonus_sale_percent', 1);
    }

    /**
     * Личный % бонуса сотрудника; null — процент не задан.
     *
     * Значения кэшируются на время запроса: расчёт ЗП и Сводный отчёт
     * перебирают сотни сделок, и запрос на каждую был бы лишним. Кэш
     * сбрасывается при любом сохранении сотрудника (User::booted), иначе
     * после смены процента деньги считались бы по старому значению.
     */
    private static array $bonusPercentCache = [];

    public static function userBonusPercent(?int $userId): ?float
    {
        if (! $userId) {
            return null;
        }

        if (! array_key_exists($userId, self::$bonusPercentCache)) {
            $value = \App\Models\User::whereKey($userId)->value('bonus_percent');
            self::$bonusPercentCache[$userId] = $value === null ? null : (float) $value;
        }

        return self::$bonusPercentCache[$userId];
    }

    /** Сбросить кэш процентов — вызывается при сохранении сотрудника. */
    public static function forgetBonusPercents(): void
    {
        self::$bonusPercentCache = [];
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
        // Нет этапов с типами act/esf — значит в воронке их нет, и блока «на
        // подходе» тоже нет. Раньше здесь подставлялся «второй с конца», и в
        // воронке без акта под «деньги на подходе» попадал случайный этап —
        // перестановка воронки в настройках меняла цифры ЗП без правки кода.
        $pendingIds = $stages->whereIn('stage_type', ['act', 'esf'])->pluck('id');
        $stageFilter = $wonStageIds->merge($pendingIds)->unique()->all();

        $deals = Deal::forCurrentCompany()->whereNotNull('responsible_user_id')
            ->whereIn('deal_stage_id', $stageFilter)
            ->where('status', '!=', 'cancelled')
            ->orderByDesc('budget')
            ->get(['id', 'number', 'company_name', 'budget', 'partner_pct', 'bonus_rate_override', 'deal_stage_id', 'responsible_user_id', 'status', 'deal_type']);

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
            $userPercent = self::userBonusPercent($d->responsible_user_id);
            $parts = self::dealBonus($remainder, $override, $userPercent, $d->deal_type ?? self::TYPE_PRODUCTION);
            $bonus = round($parts['total'] * $payRatio, 2);
            $marginPct = self::marginPct($budget, $remainder, $tax);
            // Личный % сотрудника участвует и в показываемой ставке: иначе
            // строка показывала авто-ступень, а платили по личному проценту —
            // бухгалтер видел число, которое не сходится с выплатой.

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
                'bonus_rate' => $parts['rate'],
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
    /**
     * Бонус сотрудника за КОНКРЕТНЫЙ месяц.
     *
     * Месяц определяется по дате ДОГОВОРА, а без неё — по дате создания:
     * ровно то же правило, что у фильтра «Месяц» на Финансах и в Сводном
     * отчёте (ReportController). Разойдись они — ведомость перестала бы
     * сходиться с отчётом, и спорить пришлось бы о том, какая страница врёт.
     *
     * Формула не своя: тот же marginBonus (ручной % по сделке и личный %
     * сотрудника работают сами) × доля фактической оплаты. Это единственная
     * точка расчёта «бонус за месяц» — её зовут и погашение долгов, и плитка
     * в ведомости; второй раз нигде не пересчитывать.
     *
     * @param  string  $month  формат YYYY-MM
     */
    public function bonusByUserForMonth(int $userId, string $month): float
    {
        return $this->bonusByUsersForMonth([$userId], $month)[$userId] ?? 0.0;
    }

    /**
     * То же самое сразу для НЕСКОЛЬКИХ сотрудников — ведомость ЗП строит
     * плитку и колонку месяца по всем строкам, и звать метод в цикле значило
     * бы три запроса на каждого. Формула тут не своя: она одна на оба метода,
     * ниже по коду.
     *
     * @param  array<int, int>|\Illuminate\Support\Collection<int, int>  $userIds
     * @return array<int, float>  бонус по id сотрудника
     */
    public function bonusByUsersForMonth($userIds, string $month): array
    {
        return $this->bonusByMonths($userIds, [$month])[$month] ?? [];
    }

    /**
     * Бонусы по МЕСЯЦАМ ОПЛАТЫ.
     *
     * Месяц бонуса — тот, когда деньги пришли от клиента, а не когда подписан
     * договор: бонус платится с денег, а не с обещания. Сделка, оплаченная
     * частями, отдаёт бонус теми же частями — по 30% оплаты приходит 30%
     * бонуса в свой месяц, и сумма месяцев всегда равна бонусу сделки.
     *
     * @param  array<int, int>|\Illuminate\Support\Collection<int, int>  $userIds
     * @param  array<int, string>  $months  список YYYY-MM
     * @return array<string, array<int, float>>  [месяц => [id сотрудника => бонус]]
     */
    public function bonusByMonths($userIds, array $months): array
    {
        $userIds = collect($userIds)->map(fn ($id) => (int) $id)->unique()->values();
        $months = array_values(array_unique($months));
        $empty = array_fill_keys($months, []);
        if ($userIds->isEmpty() || $months === []) {
            return $empty;
        }

        // Берём ВСЕ выигранные сделки этих сотрудников: платёж мог прийти в
        // нужном месяце по сделке любого возраста.
        $deals = Deal::won()->forCurrentCompany()
            ->whereIn('responsible_user_id', $userIds)
            ->get(['id', 'budget', 'partner_pct', 'bonus_rate_override', 'responsible_user_id', 'deal_type']);

        if ($deals->isEmpty()) {
            return $empty;
        }

        $ids = $deals->pluck('id');
        $taxRate = ((float) Setting::get('tax_percent', 3)) / 100;

        $expenseByDeal = Expense::where('status', 'confirmed')->where('expenseable_type', 'deal')
            ->whereIn('expenseable_id', $ids)
            ->groupBy('expenseable_id')->selectRaw('expenseable_id as did, SUM(amount) as v')->pluck('v', 'did');

        // Платежи по сделкам с датой: по ней и раскладываем бонус по месяцам.
        $payments = Payment::query()
            ->join('invoices', 'payments.invoice_id', '=', 'invoices.id')
            ->where('invoices.invoiceable_type', 'deal')
            ->whereIn('invoices.invoiceable_id', $ids)
            ->orderBy('payments.payment_date')->orderBy('payments.id')
            ->get(['invoices.invoiceable_id as did', 'payments.amount', 'payments.payment_date']);

        // Полный бонус сделки — при 100% оплаты. Доля месяца = деньги месяца
        // ÷ сумма договора, поэтому Σ месяцев = бонус × доля оплаты.
        $fullBonus = [];
        foreach ($deals as $d) {
            $budget = (float) $d->budget;
            $expense = (float) ($expenseByDeal[$d->id] ?? 0);
            $tax = round($budget * $taxRate, 2);
            $remainder = round($budget - $tax - $expense - self::partnerSum($budget, $d->partner_pct), 2);

            $fullBonus[$d->id] = [
                'budget' => $budget,
                'uid' => (int) $d->responsible_user_id,
                'bonus' => self::dealBonus(
                    $remainder,
                    $d->bonus_rate_override !== null ? (float) $d->bonus_rate_override : null,
                    self::userBonusPercent($d->responsible_user_id),
                    $d->deal_type ?? self::TYPE_PRODUCTION,
                )['total'],
            ];
        }

        $result = $empty;
        $paidSoFar = [];
        foreach ($payments as $payment) {
            $deal = $fullBonus[$payment->did] ?? null;
            if (! $deal || $deal['budget'] <= 0) {
                continue;
            }

            // Переплата бонуса не приносит: за пределами суммы договора
            // считать нечего.
            $already = $paidSoFar[$payment->did] ?? 0.0;
            $counted = max(0, min((float) $payment->amount, $deal['budget'] - $already));
            $paidSoFar[$payment->did] = $already + $counted;
            if ($counted <= 0) {
                continue;
            }

            $month = \Illuminate\Support\Carbon::parse($payment->payment_date)->format('Y-m');
            if (! array_key_exists($month, $result)) {
                continue;   // месяц вне запрошенного окна
            }

            $share = $deal['bonus'] * ($counted / $deal['budget']);
            $result[$month][$deal['uid']] = round(($result[$month][$deal['uid']] ?? 0) + $share, 2);
        }

        return $result;
    }

    /**
     * Двенадцать месяцев года по сотрудникам — для страницы «Бонусы».
     *
     * @return array<string, array<int, float>>
     */
    public function bonusYear($userIds, int $year): array
    {
        $months = collect(range(1, 12))
            ->map(fn ($m) => sprintf('%04d-%02d', $year, $m))->all();

        return $this->bonusByMonths($userIds, $months);
    }

    public function perUser(bool $includeAllActive = false): Collection
    {
        $taxRate = ((float) Setting::get('tax_percent', 3)) / 100;

        $deals = Deal::won()->forCurrentCompany()->whereNotNull('responsible_user_id')
            ->get(['id', 'budget', 'partner_pct', 'bonus_rate_override', 'responsible_user_id', 'deal_type']);
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
                'bonus' => round(self::dealBonus(
                    $remainder,
                    $d->bonus_rate_override !== null ? (float) $d->bonus_rate_override : null,
                    self::userBonusPercent($d->responsible_user_id),
                    $d->deal_type ?? self::TYPE_PRODUCTION,
                )['total'] * $payRatio, 2),
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
