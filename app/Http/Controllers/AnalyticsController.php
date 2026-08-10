<?php

namespace App\Http\Controllers;

use App\Models\Deal;
use App\Models\DealStage;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Material;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Setting;
use App\Models\Task;
use App\Models\User;
use App\Services\PayrollService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AnalyticsController extends Controller
{
    /** Морф-условие «сущность принадлежит текущей компании» (deal | project). */
    private function morphCompanyScope($q, string $typeCol, string $idCol, int $companyId): void
    {
        $q->where(fn ($w) => $w
            ->where(fn ($d) => $d->where($typeCol, 'deal')
                ->whereIn($idCol, Deal::where('company_id', $companyId)->select('id')))
            ->orWhere(fn ($p) => $p->where($typeCol, 'project')
                ->whereIn($idCol, Project::whereHas('deal', fn ($d) => $d->where('company_id', $companyId))->select('id'))));
    }

    public function index(Request $request): Response
    {
        // financist — как на бывшем Дашборде: видит деньги, но без report.viewAny.
        abort_unless($request->user()->can('report.viewAny') || $request->user()->hasAnyRole(['admin', 'financist']), 403);

        // ---- Фильтры: период (для «за период» и топа менеджеров), менеджер,
        // этап, поиск (№ / контрагент / договор). Применяются к воронке,
        // блоку «за период» и топу менеджеров. ----
        $from = $request->string('from')->toString() ?: now()->startOfMonth()->toDateString();
        $to = $request->string('to')->toString() ?: now()->toDateString();
        $managerId = $request->integer('manager') ?: null;
        $stageId = $request->integer('stage') ?: null;
        $search = $request->string('search')->toString();
        $dealFilter = fn ($q) => $q
            ->when($managerId, fn ($w, $m) => $w->where('responsible_user_id', $m))
            ->when($stageId, fn ($w, $s) => $w->where('deal_stage_id', $s))
            ->when($search, fn ($w, $s) => $w->where(fn ($ww) => $ww
                ->where('number', 'like', "%{$s}%")->orWhere('company_name', 'like', "%{$s}%")
                ->orWhere('client_name', 'like', "%{$s}%")->orWhere('bin', 'like', "%{$s}%")));

        $wonIds = Deal::won()->forCurrentCompany()->pluck('id');

        // Deals by stage (funnel) — этапы ТЕКУЩЕЙ компании (иначе одинаковые
        // одноимённые этапы разных фирм выглядят как дубли); в режиме «Все
        // компании» показываем обе воронки с пометкой фирмы.
        $companyId = \App\Support\CurrentCompany::id() ?: null;
        $stages = DealStage::with('translations')->where('is_active', true)
            ->when($companyId, fn ($q, $c) => $q->where(fn ($w) => $w->where('company_id', $c)->orWhereNull('company_id')))
            ->orderBy('order')->get();
        $companyNames = \App\Models\Company::pluck('name', 'id');
        // Воронка — АКТИВНЫЕ сделки по этапам (перенесено с Дашборда: где затор),
        // учитывает фильтры менеджер/этап/поиск.
        $dealsByStage = Deal::query()->forCurrentCompany()
            ->whereNotIn('status', ['closed', 'cancelled'])
            ->tap($dealFilter)
            ->selectRaw('deal_stage_id, count(*) as cnt, coalesce(sum(budget),0) as total')
            ->groupBy('deal_stage_id')->get()->keyBy('deal_stage_id');

        $funnel = $stages->map(fn ($s) => [
            'name' => $s->translatedName().(! $companyId && $s->company_id ? ' · '.($companyNames[$s->company_id] ?? '') : ''),
            'color' => $s->color,
            'count' => (int) ($dealsByStage[$s->id]->cnt ?? 0),
            'total' => (float) ($dealsByStage[$s->id]->total ?? 0),
        ])->values();

        // Deals by status.
        $byStatus = Deal::query()->forCurrentCompany()->selectRaw('status, count(*) as cnt')->groupBy('status')
            ->pluck('cnt', 'status');

        // Monthly income (payments) and expenses — grouped in PHP for DB portability.
        $monthsCount = in_array((int) $request->integer('months', 6), [3, 6, 12], true) ? (int) $request->integer('months', 6) : 6;
        $months = collect(range($monthsCount - 1, 0))->map(fn ($i) => now()->subMonths($i)->format('Y-m'));
        $payments = Payment::whereHas('invoice', fn ($q) => $q->where('invoiceable_type', 'deal')->whereIn('invoiceable_id', $wonIds))->get(['amount', 'payment_date']);
        // Расходы по месяцам — по ВСЕМ сделкам компании (не только won): затрата
        // видна в месяце, когда потрачена, а не когда сделка станет успешной.
        $expenses = Expense::where('status', 'confirmed')->where('expenseable_type', 'deal')
            ->whereIn('expenseable_id', Deal::forCurrentCompany()->select('id'))->get(['amount', 'date']);

        $monthly = $months->map(function ($m) use ($payments, $expenses) {
            $income = $payments->filter(fn ($p) => optional($p->payment_date)->format('Y-m') === $m)->sum('amount');
            $expense = $expenses->filter(fn ($e) => optional($e->date)->format('Y-m') === $m)->sum('amount');

            return ['month' => $m, 'income' => (float) $income, 'expense' => (float) $expense];
        });

        // Conversion: won deals vs total.
        $wonStageIds = DealStage::where('is_won', true)->pluck('id');
        $total = Deal::query()->forCurrentCompany()->count();
        // Отменённые сделки, оставшиеся на won-этапе, успехом не считаются.
        $won = Deal::query()->forCurrentCompany()->whereIn('deal_stage_id', $wonStageIds)->where('status', '!=', 'cancelled')->count();

        // ABC по ФАКТИЧЕСКОМУ доходу (оплачено). Пороги по просьбе владельца
        // (25.07.2026): A — накопительно до 30%, B — следующие 20% (до 50%),
        // C — остальное (~10% и хвост).
        $dealIncome = Payment::query()
            ->join('invoices', 'payments.invoice_id', '=', 'invoices.id')
            ->where('invoices.invoiceable_type', 'deal')
            ->whereIn('invoices.invoiceable_id', $wonIds)
            ->whereNotNull('invoices.invoiceable_id')
            ->groupBy('invoices.invoiceable_id')
            ->selectRaw('invoices.invoiceable_id as deal_id, SUM(payments.amount) as income')
            ->pluck('income', 'deal_id');

        $ranked = Deal::whereIn('id', $dealIncome->keys())->get(['id', 'number', 'name', 'company_name'])
            ->map(fn ($d) => ['number' => $d->number, 'name' => $d->company_name ?: $d->name, 'value' => (float) $dealIncome[$d->id]])
            ->sortByDesc('value')->values();
        $totalIncome = (float) $ranked->sum('value');
        $cumulative = 0.0;
        $abc = $ranked->map(function ($row) use (&$cumulative, $totalIncome) {
            $value = (float) $row['value'];
            $share = $totalIncome > 0 ? $value / $totalIncome * 100 : 0;
            $cumulative += $share;
            $class = $cumulative <= 30 ? 'A' : ($cumulative <= 50 ? 'B' : 'C');

            return [
                'number' => $row['number'], 'name' => $row['name'], 'value' => $value,
                'share' => round($share, 1), 'cumulative' => round($cumulative, 1), 'class' => $class,
            ];
        });
        $abcSummary = collect(['A', 'B', 'C'])->mapWithKeys(fn ($c) => [$c => [
            'count' => $abc->where('class', $c)->count(),
            'value' => round($abc->where('class', $c)->sum('value'), 2),
        ]]);

        // ---- Per-employee analytics (deals by stage + overdue + ЗП/margin) ----
        $payroll = app(PayrollService::class);
        $salaryRows = $payroll->perUser();
        $companyTotals = $payroll->companyTotals();
        // «На подходе» = Акт + ЭСФ (по stage_type, у каждой компании своя воронка).
        $allStages = DealStage::where('is_active', true)->orderBy('order')->get();
        $pendingIds = $allStages->whereIn('stage_type', ['act', 'esf'])->pluck('id');
        if ($pendingIds->isEmpty() && ($fallback = $allStages->slice(-2, 1)->first())) {
            $pendingIds = collect([$fallback->id]);
        }
        // Из просрочки исключается только ЭСФ (и won): Акт — просрочка.
        $esfIds = $allStages->where('stage_type', 'esf')->pluck('id');
        $today = now()->startOfDay();
        $taxRate = ((float) Setting::get('tax_percent', 3)) / 100;

        $empDealsRaw = Deal::query()->forCurrentCompany()
            ->whereNotNull('responsible_user_id')
            ->where('status', '!=', 'cancelled')
            ->where(function ($w) use ($wonStageIds, $pendingIds, $today) {
                $w->whereIn('deal_stage_id', $wonStageIds)
                    ->orWhereIn('deal_stage_id', $pendingIds)
                    ->orWhere(fn ($o) => $o->whereNotNull('deadline')->whereDate('deadline', '<', $today)
                        ->whereNotIn('status', ['closed', 'cancelled'])
                        ->whereDoesntHave('stage', fn ($s) => $s->where('is_won', true)->orWhere('stage_type', 'esf')));
            })
            ->get(['id', 'number', 'company_name', 'budget', 'partner_pct', 'bonus_rate_override', 'deadline', 'deal_stage_id', 'responsible_user_id', 'status']);

        // Confirmed expenses per deal → per-deal margin (same formula as the deal card).
        $dealExpense = Expense::where('status', 'confirmed')->where('expenseable_type', 'deal')
            ->whereIn('expenseable_id', $empDealsRaw->pluck('id'))
            ->groupBy('expenseable_id')->selectRaw('expenseable_id as did, sum(amount) as v')->pluck('v', 'did');

        $empDeals = $empDealsRaw->groupBy('responsible_user_id');

        $wonIdsList = $wonStageIds->all();
        $mapDeal = function ($d) use ($dealExpense, $taxRate) {
            $budget = (float) $d->budget;
            $tax = round($budget * $taxRate, 2);
            $expense = (float) ($dealExpense[$d->id] ?? 0);
            $remainder = round($budget - $tax - $expense - PayrollService::partnerSum($budget, $d->partner_pct), 2);
            // Бонус: ручной % финансиста по сделке (bonus_rate_override) или авто-ступень.
            $bonus = PayrollService::marginBonus($budget, $remainder, $tax,
                $d->bonus_rate_override !== null ? (float) $d->bonus_rate_override : null,
                PayrollService::userBonusPercent($d->responsible_user_id));
            $company = round($remainder - $bonus, 2);

            return [
                'id' => $d->id,
                'number' => $d->number,
                'company' => $d->company_name,
                'budget' => $budget,
                'net' => $company,
                'margin' => $budget > 0 ? round($company / $budget * 100, 1) : 0.0,
                'deadline' => optional($d->deadline)->toDateString(),
            ];
        };

        $byEmployee = $salaryRows->map(function ($row) use ($empDeals, $wonIdsList, $pendingIds, $esfIds, $today, $mapDeal) {
            $deals = $empDeals->get($row['uid'], collect());
            $won = $deals->whereIn('deal_stage_id', $wonIdsList)->map($mapDeal)->values();
            $act = $deals->whereIn('deal_stage_id', $pendingIds->all())->map($mapDeal)->values();
            $overdue = $deals->filter(fn ($d) => $d->deadline && $d->deadline->startOfDay() < $today
                    && ! in_array($d->deal_stage_id, $wonIdsList) && ! $esfIds->contains($d->deal_stage_id)
                    && ! in_array($d->status, ['closed', 'cancelled']))
                ->map(fn ($d) => array_merge($mapDeal($d), ['overdue_days' => (int) $d->deadline->startOfDay()->diffInDays($today)]))
                ->sortByDesc('overdue_days')->values();

            return [
                'uid' => $row['uid'],
                'user' => $row['user'],
                'avatar' => $row['avatar'],
                'income' => $row['income'],
                'expense' => $row['expense'],
                'net' => $row['net'],
                'tax' => $row['tax'],
                'bonus' => $row['bonus'],
                'margin' => $row['margin'],
                'closed' => $row['closed'],
                'won_deals' => $won,
                'act_deals' => $act,
                'overdue_deals' => $overdue,
            ];
        })->sortByDesc('bonus')->values();

        // ---- Деньги (перенесено с Дашборда): дебиторка = выставлено − оплачено ----
        $invBase = Invoice::query()->when($companyId, fn ($q, $c) => $this->morphCompanyScope($q, 'invoiceable_type', 'invoiceable_id', $c));
        $invoiced = (float) (clone $invBase)->sum('amount');
        $invoicePaid = (float) Payment::whereIn('invoice_id', (clone $invBase)->select('id'))->sum('amount');

        // ---- Деньги компании (как на Финансах): касса/банк, все расходы с разбивкой ----
        $balances = app(\App\Services\FinanceService::class)->companyBalances($companyId ?: null);
        $expFull = Expense::where('status', 'confirmed')
            ->when($companyId, fn ($q, $c) => $q->where(function ($w) use ($c) {
                $this->morphCompanyScope($w, 'expenseable_type', 'expenseable_id', $c);
                $w->orWhere('company_id', $c); // расходы компании (аренда/бензин…)
            }));
        $byCat = (clone $expFull)->whereNotNull('category_id')
            ->groupBy('category_id')->selectRaw('category_id, sum(amount) s')->pluck('s', 'category_id');
        $catNames = \App\Models\ExpenseCategory::whereIn('id', $byCat->keys())->pluck('name', 'id');
        $categoryRows = $byCat->map(fn ($s, $id) => ['name' => $catNames[$id] ?? '—', 'sum' => (float) $s])
            ->sortByDesc('sum')->values();
        $dealExpensesSum = (float) (clone $expFull)->whereNull('category_id')->sum('amount');
        // Разбивка расходов по сделкам/цеху по видам: склад / доставка / закуп / прочие.
        $dealSplitRow = (clone $expFull)->whereNull('category_id')
            ->selectRaw("
                sum(case when material_id is not null then amount else 0 end) as material,
                sum(case when material_id is null and type = 'delivery' then amount else 0 end) as delivery,
                sum(case when material_id is null and type = 'assembly' then amount else 0 end) as assembly,
                sum(case when material_id is null and type = 'purchase' then amount else 0 end) as purchase,
                sum(case when material_id is null and (type is null or type not in ('delivery','purchase','assembly')) then amount else 0 end) as other")
            ->first();
        $dealSplit = [
            'material' => (float) ($dealSplitRow->material ?? 0),
            'delivery' => (float) ($dealSplitRow->delivery ?? 0),
            'assembly' => (float) ($dealSplitRow->assembly ?? 0),
            'purchase' => (float) ($dealSplitRow->purchase ?? 0),
            'other' => (float) ($dealSplitRow->other ?? 0),
        ];
        $incomeManual = (float) \App\Models\CashReceipt::query()
            ->when($companyId, fn ($q, $c) => $q->where('company_id', $c))->sum('amount');
        $payrollTotal = round((float) $salaryRows->sum('payout'), 2);
        // Все расходы компании: категории + по сделкам/цеху + ЗП (оклады+бонусы) + налог.
        $expensesFull = round($categoryRows->sum('sum') + $dealExpensesSum + $payrollTotal + $companyTotals['tax'], 2);
        $companyMoney = [
            'cash' => $balances['cash'],
            'bank' => $balances['bank'],
            'income' => round($invoicePaid + $incomeManual, 2),
            'incomeInvoices' => $invoicePaid,
            'incomeManual' => $incomeManual,
            'categories' => $categoryRows,
            'dealExpenses' => $dealExpensesSum,
            'dealSplit' => $dealSplit,
            'payroll' => $payrollTotal,
            'tax' => $companyTotals['tax'],
            'expensesTotal' => round($expensesFull, 2),
            'net' => round($invoicePaid + $incomeManual - $expensesFull, 2),
        ];

        // ---- «Требует внимания» (без фильтров — это сигналы по всей компании) ----
        $overdueDeals = Deal::forCurrentCompany()->whereNotNull('deadline')->whereDate('deadline', '<', $today)
            ->whereNotIn('status', ['closed', 'cancelled'])
            // ЭСФ/Оплата — не просрочка; Акт утверждение — считается просрочкой.
            ->whereDoesntHave('stage', fn ($s) => $s->where('is_won', true)->orWhere('stage_type', 'esf'))->count();

        $overdueTasks = Task::where('status', '!=', 'done')->whereNotNull('due_date')->where('due_date', '<', now())
            ->when($companyId, fn ($q, $c) => $q->where(fn ($w) => $w
                ->whereNull('taskable_type') // личные задачи не делятся по фирмам
                ->orWhere(fn ($m) => $this->morphCompanyScope($m, 'taskable_type', 'taskable_id', $c))))
            ->count();

        $expBase = Expense::query()->when($companyId, fn ($q, $c) => $this->morphCompanyScope($q, 'expenseable_type', 'expenseable_id', $c));
        $pendingExpenses = [
            'count' => (clone $expBase)->where('status', 'pending')->count(),
            'sum' => (float) (clone $expBase)->where('status', 'pending')->sum('amount'),
        ];

        $zeroMaterials = Material::forCurrentCompany()->where('quantity', '<=', 0)->count();

        // ---- «За период»: при активном фильтре по сделкам деньги считаются
        // только по счетам/расходам отфильтрованных сделок, иначе — по компании
        // целиком (включая проекты), как на бывшем Дашборде. ----
        $hasDealFilter = $managerId || $stageId || $search !== '';
        $filteredDealIds = Deal::forCurrentCompany()->tap($dealFilter)->select('id');
        $periodPaidBase = $hasDealFilter
            ? Payment::whereIn('invoice_id', Invoice::where('invoiceable_type', 'deal')->whereIn('invoiceable_id', $filteredDealIds)->select('id'))
            : Payment::whereIn('invoice_id', (clone $invBase)->select('id'));
        $periodExpBase = $hasDealFilter
            ? Expense::where('status', 'confirmed')->where('expenseable_type', 'deal')->whereIn('expenseable_id', $filteredDealIds)
            : (clone $expBase)->where('status', 'confirmed');

        $period = [
            'paid' => (float) $periodPaidBase->whereDate('payment_date', '>=', $from)->whereDate('payment_date', '<=', $to)->sum('amount'),
            'expenses' => (float) $periodExpBase->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)->sum('amount'),
            'newDeals' => Deal::forCurrentCompany()->tap($dealFilter)
                ->whereDate('created_at', '>=', $from)->whereDate('created_at', '<=', $to)->count(),
        ];

        // Топ менеджеров за период: созданные сделки + бюджет (uid — для ссылки).
        $topManagers = Deal::forCurrentCompany()->where('status', '!=', 'cancelled')->tap($dealFilter)
            ->whereDate('created_at', '>=', $from)->whereDate('created_at', '<=', $to)
            ->whereNotNull('responsible_user_id')
            ->selectRaw('responsible_user_id, count(*) deals, sum(budget) budget')
            ->groupBy('responsible_user_id')->orderByDesc('budget')->limit(5)
            ->with('responsible:id,name')->get()
            ->map(fn ($r) => ['uid' => $r->responsible_user_id, 'user' => $r->responsible?->name ?? '—', 'deals' => (int) $r->deals, 'budget' => (float) $r->budget]);

        return Inertia::render('Analytics/Index', [
            'byEmployee' => $byEmployee,
            'monthsFilter' => $monthsCount,
            'funnel' => $funnel,
            'byStatus' => $byStatus,
            'monthly' => $monthly,
            'abc' => $abc->take(20)->values(),
            'abcSummary' => $abcSummary,
            'conversion' => [
                'total' => $total,
                'won' => $won,
                'rate' => $total > 0 ? round($won / $total * 100, 1) : 0,
            ],
            // Canonical company figures (identical to Finance, via PayrollService).
            // 'contracts' — сумма договоров ВСЕХ сделок (кроме отменённых);
            // budget/tax/net из companyTotals считаются только по won-сделкам.
            'totals' => array_merge($companyTotals, [
                'contracts' => (float) Deal::forCurrentCompany()->where('status', '!=', 'cancelled')->sum('budget'),
                'net' => $companyTotals['company'],
                'debt' => max(0, $invoiced - $invoicePaid),
                'taxRate' => $taxRate * 100,
            ]),
            'companyMoney' => $companyMoney,
            'attention' => [
                'overdueDeals' => $overdueDeals,
                'overdueTasks' => $overdueTasks,
                'pendingExpenses' => $pendingExpenses,
                'zeroMaterials' => $zeroMaterials,
            ],
            'period' => $period,
            'topManagers' => $topManagers,
            'filters' => ['from' => $from, 'to' => $to, 'manager' => $managerId, 'stage' => $stageId, 'search' => $search],
            'managers' => User::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'stageOptions' => $stages->map(fn ($s) => ['id' => $s->id, 'name' => $s->translatedName().(! $companyId && $s->company_id ? ' · '.($companyNames[$s->company_id] ?? '') : '')])->values(),
        ]);
    }
}
