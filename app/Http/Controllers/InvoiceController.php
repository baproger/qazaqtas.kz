<?php

namespace App\Http\Controllers;

use App\Http\Requests\InvoiceRequest;
use App\Models\Deal;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\User;
use App\Services\InvoiceNumberService;
use App\Services\PayrollService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InvoiceController extends Controller
{
    // Менеджер работает только со своими сделками — счета чужих сделок недоступны.
    private function assertOwnership(User $user, ?Model $entity): void
    {
        // Изоляция фирм: счета чужой компании (BAIA/ASU) недоступны никому,
        // кто к этой компании не привязан, — включая финансиста и директора.
        $companyId = $entity instanceof Project ? $entity->deal?->company_id : $entity?->company_id;
        abort_unless($entity === null || $user->worksInCompany($companyId ? (int) $companyId : null), 403);

        if ($user->hasRole('manager') && ! $user->hasAnyRole(['admin', 'director', 'financist'])) {
            abort_unless($entity && $entity->responsible_user_id === $user->id, 403);
        }
    }

    private function resolve(?string $type, ?int $id): ?Model
    {
        if (! $id) {
            return null;
        }

        return $type === 'project' ? Project::find($id) : Deal::find($id);
    }

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Invoice::class);

        // Finance page is leadership-only; managers/workshop staff handle money inside deal cards.
        abort_unless($request->user()->hasAnyRole(['admin', 'director', 'financist']), 403);

        // Финансы разделены по фирмам: счёт принадлежит компании своей сделки
        // (счета цеховых заказов идут через сделку заказа).
        $invBase = Invoice::query()
            ->when(\App\Support\CurrentCompany::id(), fn ($q, $c) => $q->where(fn ($w) => $w
                ->where(fn ($d) => $d->where('invoiceable_type', 'deal')
                    ->whereIn('invoiceable_id', \App\Models\Deal::where('company_id', $c)->select('id')))
                ->orWhere(fn ($p) => $p->where('invoiceable_type', 'project')
                    ->whereIn('invoiceable_id', \App\Models\Project::whereHas('deal', fn ($d) => $d->where('company_id', $c))->select('id')))));

        // Ссылка на сделку/заказ счёта — «откуда деньги» есть ВСЕГДА:
        // даже у удалённой сделки показываем номер и заказчика (серым).
        $mapInvoice = function ($i) {
            $target = $i->invoiceable;
            $link = null;
            if ($target instanceof \App\Models\Deal) {
                $link = ['type' => 'deal', 'id' => $target->id, 'label' => trim($target->number.' · '.($target->company_name ?? ''), ' ·')];
            } elseif ($target instanceof \App\Models\Project) {
                $link = ['type' => 'project', 'id' => $target->id, 'label' => trim($target->number.' · '.($target->name ?? ''), ' ·')];
            } elseif ($i->invoiceable_type === 'deal' && $i->invoiceable_id) {
                $trashed = \App\Models\Deal::withTrashed()->find($i->invoiceable_id);
                if ($trashed) {
                    $number = preg_replace('/#del\d+$/', '', (string) $trashed->number);
                    $link = ['type' => 'deal', 'id' => null, 'label' => trim($number.' · '.($trashed->company_name ?? ''), ' ·').' (сделка удалена)'];
                }
            }

            return [
                'id' => $i->id,
                'number' => $i->number,
                'client' => $i->client,
                'amount' => (float) $i->amount,
                'payments_sum_amount' => (float) ($i->payments_sum_amount ?? 0),
                'status' => $i->status,
                'date' => ($i->issue_date ?? $i->created_at)?->toDateString(),
                'link' => $link,
            ];
        };

        // Счета: на странице — только сегодняшние; прошлые — аккордеоном
        // снизу с поиском по номеру (как Поступления/Расходы).
        $todayD = now()->toDateString();
        $isToday = fn ($q) => $q->where(fn ($w) => $w
            ->whereDate('issue_date', $todayD)
            ->orWhere(fn ($n) => $n->whereNull('issue_date')->whereDate('created_at', $todayD)));
        $invoicesToday = $isToday((clone $invBase))
            ->with(['client:id,name', 'invoiceable'])
            ->withSum('payments as payments_sum_amount', 'amount')
            ->latest()->get()->map($mapInvoice)->values();
        $invPastBase = fn () => (clone $invBase)->whereNot(fn ($w) => $w
            ->whereDate('issue_date', $todayD)
            ->orWhere(fn ($n) => $n->whereNull('issue_date')->whereDate('created_at', $todayD)));
        $invoicesPast = $invPastBase()
            ->with(['client:id,name', 'invoiceable'])
            ->withSum('payments as payments_sum_amount', 'amount')
            ->when($request->string('search')->toString(), fn ($q, $s) => $q->where('number', 'like', "%{$s}%"))
            ->when($request->string('status')->toString(), fn ($q, $st) => $q->where('status', $st))
            ->latest()->limit(100)->get()->map($mapInvoice)->values();
        $invoicesPastStats = [
            'count' => $invPastBase()->count(),
            'sum' => (float) $invPastBase()->sum('amount'),
        ];

        // Дебиторка для финансиста: выставлено / оплачено / остаток к оплате.
        $invoiced = (float) (clone $invBase)->sum('amount');
        $invoicePaid = (float) \App\Models\Payment::whereIn('invoice_id', (clone $invBase)->select('id'))->sum('amount');
        $invoiceTotals = [
            'invoiced' => $invoiced,
            'paid' => $invoicePaid,
            'debt' => max(0, $invoiced - $invoicePaid),
        ];

        // Фильтр сводки «Доход − Расходы = Чистая прибыль» по месяцу (YYYY-MM):
        // админ/финансист смотрит финансы за прошлый (любой) месяц. Остатки
        // касса/банк и задолженности — всегда «на сейчас» (накопительные).
        $finMonth = preg_match('/^\d{4}-\d{2}$/', $request->string('fin_month')->toString())
            ? $request->string('fin_month')->toString() : '';
        $mStart = $finMonth ? $finMonth.'-01' : null;
        $mEnd = $finMonth ? \Illuminate\Support\Carbon::parse($finMonth.'-01')->endOfMonth()->toDateString() : null;
        $monthly = fn ($q, $col = 'date') => $finMonth
            ? $q->whereDate($col, '>=', $mStart)->whereDate($col, '<=', $mEnd) : $q;

        // ---- Раздел «Расходы»: материальные/прочие, нал/банк, статус ----
        $companyId = \App\Support\CurrentCompany::id();
        // Скоуп расходов текущей фирмы: по сделке/заказу + расходы КОМПАНИИ
        // (аренда/интернет/бензин… — без сделки, по company_id).
        $expScope = fn ($q) => $q->when($companyId, fn ($qq, $c) => $qq->where(fn ($w) => $w
            ->where(fn ($d) => $d->where('expenseable_type', 'deal')
                ->whereIn('expenseable_id', \App\Models\Deal::where('company_id', $c)->select('id')))
            ->orWhere(fn ($p) => $p->where('expenseable_type', 'project')
                ->whereIn('expenseable_id', \App\Models\Project::whereHas('deal', fn ($d) => $d->where('company_id', $c))->select('id')))
            ->orWhere('company_id', $c)));

        // Без периода — для таблиц «сегодня / прошлые» ниже.
        $expScopeBase = \App\Models\Expense::query()->tap($expScope);
        $expBase = (clone $expScopeBase)
            // Период применяется к сводке-плиткам — «сколько нал/банк
            // за месяц» видно сразу, без ручного суммирования.
            ->when($request->string('exp_from')->toString(), fn ($q, $d) => $q->whereDate('date', '>=', $d))
            ->when($request->string('exp_to')->toString(), fn ($q, $d) => $q->whereDate('date', '<=', $d));

        $confirmed = fn () => (clone $expBase)->where('status', 'confirmed');
        $expenseTotals = [
            'all' => (float) $confirmed()->sum('amount'),
            'all_count' => $confirmed()->count(),
            'material' => (float) $confirmed()->whereNotNull('material_id')->sum('amount'),
            'other' => (float) $confirmed()->whereNull('material_id')->sum('amount'),
            // Нал/банк — разбивка ПРОЧИХ расходов (материальные исключены явно:
            // с недавних пор у них тоже есть способ оплаты).
            'cash' => (float) $confirmed()->whereNull('material_id')->where('payment_method', 'cash')->sum('amount'),
            'bank' => (float) $confirmed()->whereNull('material_id')->where('payment_method', 'bank')->sum('amount'),
            'pending_sum' => (float) (clone $expBase)->where('status', 'pending')->sum('amount'),
            'pending_count' => (clone $expBase)->where('status', 'pending')->count(),
        ];

        // Плитки-фильтры (вид/оплата/статус) действуют на обе таблицы расходов.
        $expToday = now()->toDateString();
        $expFiltered = fn () => (clone $expScopeBase)
            ->with(['expenseable', 'category:id,name', 'material:id,name,unit', 'responsible:id,name', 'confirmedBy:id,name'])
            ->when($request->string('exp_status')->toString(), fn ($q, $s) => $q->where('status', $s))
            ->when($request->string('exp_method')->toString(), fn ($q, $m) => $q->where('payment_method', $m))
            ->when($request->string('exp_kind')->toString(), fn ($q, $k) => $k === 'material' ? $q->whereNotNull('material_id') : $q->whereNull('material_id'));

        // В таблице — только сегодняшние; прошлые — аккордеон с поиском
        // (описание/категория) и периодом.
        $expensesToday = $expFiltered()->whereDate('date', $expToday)->latest()->get();
        $xpSearch = $request->string('xp_search')->toString();
        $expensesPast = $expFiltered()
            ->whereDate('date', '<', $expToday)
            ->when($xpSearch, fn ($q, $s) => $q->where(fn ($w) => $w->where('description', 'like', "%{$s}%")
                ->orWhereHas('category', fn ($c) => $c->where('name', 'like', "%{$s}%"))))
            ->when($request->string('xp_from')->toString(), fn ($q, $d) => $q->whereDate('date', '>=', $d))
            ->when($request->string('xp_to')->toString(), fn ($q, $d) => $q->whereDate('date', '<=', $d))
            ->latest()->limit(100)->get();
        $expensesPastStats = [
            'count' => (clone $expScopeBase)->whereDate('date', '<', $expToday)->count(),
            'sum' => (float) (clone $expScopeBase)->whereDate('date', '<', $expToday)->sum('amount'),
        ];

        // Canonical company finance — identical to Dashboard & Analytics (via PayrollService).
        $payroll = app(PayrollService::class);
        $fin = $payroll->companyTotals();
        $payrollRows = $payroll->perUser();

        // ---- Сводка компании (эскиз бухгалтерии): Доход − ВСЕ расходы = Чистая прибыль ----
        // Доход = все фактические поступления по счетам компании. Сводка — за всё
        // время (без exp_from/exp_to, они только для таблицы/плиток раздела).
        $confirmedNoPeriod = fn () => \App\Models\Expense::query()->tap($expScope)->where('status', 'confirmed');
        $byCategory = $monthly($confirmedNoPeriod())->whereNotNull('category_id')
            ->groupBy('category_id')->selectRaw('category_id, sum(amount) s')->pluck('s', 'category_id');
        // Для селекта формы — только активные; разбивка же строится по ФАКТУ
        // расходов (иначе деактивация категории «теряла» бы её суммы из итога).
        $categories = \App\Models\ExpenseCategory::where('is_active', true)->orderBy('name')->get(['id', 'name']);
        $catNames = \App\Models\ExpenseCategory::whereIn('id', $byCategory->keys())->pluck('name', 'id');
        $categoryRows = $byCategory
            ->map(fn ($sum, $id) => ['name' => $catNames[$id] ?? '—', 'sum' => (float) $sum])
            ->sortByDesc('sum')->values();
        // Расходы по сделкам/цеху (закуп + прочие без категории).
        $dealExpenses = (float) $monthly($confirmedNoPeriod())->whereNull('category_id')->sum('amount');
        $payrollTotal = round((float) $payrollRows->sum('payout'), 2); // оклады + бонусы
        // ЗП и налог считаются по сделкам (без даты) — в месячном режиме их
        // не размазать по месяцам, показываем только «за всё время».
        $taxRow = (float) $fin['tax'];
        if ($finMonth) {
            $payrollTotal = 0.0;
            $taxRow = 0.0;
        }
        $expensesTotal = round($categoryRows->sum('sum') + $dealExpenses + $payrollTotal + $taxRow, 2);

        // Остатки касса/банк считает FinanceService::companyBalances (ниже):
        // касса — общая на холдинг, банк — по своей фирме.

        // Задолженности: дебиторка вручную (плюс автоматическая по счетам) и кредиторка.
        $debtBase = \App\Models\Debt::query()
            ->when($companyId, fn ($q, $c) => $q->where('company_id', $c))
            ->with('creator:id,name')->latest('date')->latest('id');
        $receivableDebts = (clone $debtBase)->where('type', 'receivable')->get();
        $payableDebts = (clone $debtBase)->where('type', 'payable')->get();

        // «Доход» — итог Сводного отчёта (остаток − бонус по каждой сделке).
        $dealsIncome = app(\App\Services\FinanceService::class)->dealsIncome($companyId ?: null, $mStart, $mEnd);
        // Остатки касса/банк — из единого FinanceService (касса общая на холдинг).
        $balances = app(\App\Services\FinanceService::class)->companyBalances($companyId ?: null);

        // Поступления денег (вводит финансист): нал/банк, откуда, дата, комментарий.
        $receiptBase = \App\Models\CashReceipt::query()
            ->when($companyId, fn ($q, $c) => $q->where('company_id', $c));
        $receiptCash = (float) (clone $receiptBase)->where('method', 'cash')->sum('amount');
        $receiptBank = (float) (clone $receiptBase)->where('method', 'bank')->sum('amount');
        // На странице — только сегодняшние поступления; прошлые — аккордеоном
        // снизу, с поиском (источник/комментарий) и периодом.
        $today = now()->toDateString();
        $receiptsToday = (clone $receiptBase)->with('creator:id,name')
            ->whereDate('date', $today)->latest('id')->get();
        $rcSearch = $request->string('rc_search')->toString();
        $receiptsPast = (clone $receiptBase)->with('creator:id,name')
            ->whereDate('date', '<', $today)
            ->when($rcSearch, fn ($q, $s) => $q->where(fn ($w) => $w->where('source', 'like', "%{$s}%")->orWhere('note', 'like', "%{$s}%")))
            ->when($request->string('rc_from')->toString(), fn ($q, $d) => $q->whereDate('date', '>=', $d))
            ->when($request->string('rc_to')->toString(), fn ($q, $d) => $q->whereDate('date', '<=', $d))
            ->latest('date')->latest('id')->limit(100)->get();
        $receiptsPastStats = [
            'count' => (clone $receiptBase)->whereDate('date', '<', $today)->count(),
            'sum' => (float) (clone $receiptBase)->whereDate('date', '<', $today)->sum('amount'),
        ];
        $invoicePaidP = $finMonth
            ? (float) \App\Models\Payment::whereIn('invoice_id', (clone $invBase)->select('id'))
                ->whereDate('payment_date', '>=', $mStart)->whereDate('payment_date', '<=', $mEnd)->sum('amount')
            : $invoicePaid;
        $receiptManualP = $finMonth
            ? (float) $monthly((clone $receiptBase))->sum('amount')
            : round($receiptCash + $receiptBank, 2);
        $incomeTotal = round($invoicePaidP + $receiptManualP, 2);

        return Inertia::render('Finance/Index', [
            'invoicesToday' => $invoicesToday,
            'invoicesPast' => $invoicesPast,
            'invoicesPastStats' => $invoicesPastStats,
            'invoiceTotals' => $invoiceTotals,
            'expensesToday' => $expensesToday,
            'expensesPast' => $expensesPast,
            'expensesPastStats' => $expensesPastStats,
            'expenseTotals' => $expenseTotals,
            'filters' => $request->only('search', 'status', 'exp_status', 'exp_method', 'exp_kind', 'exp_from', 'exp_to', 'xp_search', 'xp_from', 'xp_to', 'rc_search', 'rc_from', 'rc_to', 'fin_month'),
            'categories' => $categories,
            'canManage' => $request->user()->hasAnyRole(['admin', 'financist']),
            // Корректировка кассы (✎ на плитке) — только админ.
            'isAdmin' => $request->user()->hasRole('admin'),
            // ДДС — ручная сводка (Excel-стиль): счета компаний и долги.
            // Никаких расчётов из системы — только то, что ввёл финансист.
            'dds' => [
                'accounts' => \App\Models\DdsEntry::where('kind', 'account')->orderBy('sort')->orderBy('id')->get(),
                'debts' => \App\Models\DdsEntry::where('kind', 'debt')->orderBy('sort')->orderBy('id')->get(),
                'date' => (string) \App\Models\Setting::get('dds_date', ''),
            ],
            'receiptsToday' => $receiptsToday,
            'receiptsPast' => $receiptsPast,
            'receiptsPastStats' => $receiptsPastStats,
            'debts' => ['receivables' => $receivableDebts, 'payables' => $payableDebts],
            'summary' => [
                'contracts' => (float) \App\Models\Deal::forCurrentCompany()->where('status', '!=', 'cancelled')->sum('budget'),
                'receivables' => $invoiceTotals['debt'],
                'receivablesManual' => (float) $receivableDebts->sum('amount'),
                'receivablesTotal' => round($invoiceTotals['debt'] + $receivableDebts->sum('amount'), 2),
                'payables' => (float) $payableDebts->sum('amount'),
                'dealsIncome' => $dealsIncome,
                // Единый источник (FinanceService::companyBalances): касса —
                // ОБЩАЯ на холдинг (нал в одной кассе), банк — по своей фирме.
                'cash' => $balances['cash'],
                'bank' => $balances['bank'],
                // Ненулевая корректировка кассы — покажем пометку на плитке.
                'cashCorrection' => (float) \App\Models\Setting::get('cash_correction', 0),
                'income' => $incomeTotal,
                'incomeInvoices' => $invoicePaidP,
                'incomeManual' => $receiptManualP,
                'categories' => $categoryRows,
                'dealExpenses' => $dealExpenses,
                'payroll' => $payrollTotal,
                'tax' => $taxRow,
                'expensesTotal' => $expensesTotal,
                'net' => round($incomeTotal - $expensesTotal, 2),
            ],
        ]);
    }

    /**
     * Корректировка кассы (инвентаризация): финансист вводит ФАКТИЧЕСКИЙ
     * остаток наличных — система сохраняет разницу (Setting cash_correction)
     * и прибавляет её к расчётной кассе. Старые сделки/платежи не трогаются,
     * доходы/расходы/отчёты не искажаются.
     */
    public function cashCorrection(Request $request): RedirectResponse
    {
        // Только админ (СЕО): корректировка меняет остаток всей кассы холдинга.
        abort_unless($request->user()->hasRole('admin'), 403, 'Кассу корректирует только администратор.');
        $data = $request->validate(['actual' => ['required', 'numeric']]);

        $service = app(\App\Services\FinanceService::class);
        $oldCash = $service->companyBalances(null)['cash'];
        // Расчётная касса БЕЗ текущей корректировки.
        $raw = $oldCash - (float) \App\Models\Setting::get('cash_correction', 0);
        \App\Models\Setting::set('cash_correction', round((float) $data['actual'] - $raw, 2));

        // История: кто и когда изменил остаток кассы (страница Аудит, admin).
        \App\Models\AuditLog::create([
            'user_id' => $request->user()->id,
            'ip' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
            'table_name' => 'settings',
            'record_id' => 0,
            'action' => 'updated',
            'field_name' => 'Корректировка кассы (фактический остаток)',
            'old_value' => (string) $oldCash,
            'new_value' => (string) round((float) $data['actual'], 2),
            'created_at' => now(),
        ]);

        return back()->with('success', 'Остаток в кассе установлен: '.number_format((float) $data['actual'], 0, ',', ' ').' ₸.');
    }

    public function store(InvoiceRequest $request, InvoiceNumberService $numbers): RedirectResponse
    {
        $this->authorize('create', Invoice::class);
        $this->assertOwnership($request->user(), $this->resolve($request->input('invoiceable_type', 'deal'), (int) $request->input('invoiceable_id')));

        $data = $request->validated();
        $data['number'] = $numbers->generate();
        $data['status'] ??= 'draft';
        $data['issue_date'] ??= now()->toDateString();

        Invoice::create($data);

        return back()->with('success', 'Счёт создан.');
    }

    public function update(InvoiceRequest $request, Invoice $invoice): RedirectResponse
    {
        $this->authorize('update', $invoice);
        $this->assertOwnership($request->user(), $invoice->invoiceable);

        $data = $request->validated();
        // Полиморфную привязку не меняем (иначе счёт увели бы на чужую сделку).
        unset($data['invoiceable_type'], $data['invoiceable_id']);
        // paid/partially_paid/overdue — производные от платежей (FinanceService::
        // recalcInvoiceStatus). Вручную допустимы только draft/sent/cancelled,
        // иначе можно выставить «оплачено» без единого платежа.
        if (isset($data['status']) && ! in_array($data['status'], ['draft', 'sent', 'cancelled'], true)) {
            unset($data['status']);
        }
        $invoice->update($data);

        return back()->with('success', 'Счёт обновлён.');
    }

    public function destroy(Invoice $invoice): RedirectResponse
    {
        $this->authorize('delete', $invoice);
        $this->assertOwnership(request()->user(), $invoice->invoiceable);
        $invoice->delete();
        \App\Support\FinanceAudit::notifyDeleted('Счёт '.$invoice->number.' на '.number_format((float) $invoice->amount, 0, '.', ' ').' ₸');

        return back()->with('success', 'Счёт удалён.');
    }
}
