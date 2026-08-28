<?php

namespace App\Http\Controllers;

use App\Http\Requests\InvoiceRequest;
use App\Models\AuditLog;
use App\Models\CashReceipt;
use App\Models\DdsEntry;
use App\Models\Deal;
use App\Models\Debt;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Setting;
use App\Models\User;
use App\Services\FinanceService;
use App\Services\InvoiceNumberService;
use App\Services\PayrollService;
use App\Support\CurrentCompany;
use App\Support\FinanceAudit;
use App\Support\StickyFilters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class InvoiceController extends Controller
{
    // Менеджер работает только со своими сделками — счета чужих сделок недоступны.
    private function assertOwnership(User $user, ?Model $entity): void
    {
        // Изоляция фирм: счета чужой компании недоступны никому,
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

    /** Финансы — только руководство: менеджеры ведут деньги в карточке сделки. */
    private function guardFinance(Request $request): void
    {
        $this->authorize('viewAny', Invoice::class);
        abort_unless($request->user()->hasAnyRole(['admin', 'director', 'financist']), 403);
    }

    /** Счета своей фирмы (у заказов цеха — через сделку заказа). */
    private function invoiceScope(): Builder
    {
        return app(FinanceService::class)
            ->scopeCompanyInvoices(Invoice::query(), CurrentCompany::id() ?: null);
    }

    /**
     * Ссылка на сделку/заказ счёта — «откуда деньги» есть ВСЕГДА: даже у
     * удалённой сделки показываем номер и заказчика (серым).
     */
    private function mapInvoice(): \Closure
    {
        return function ($i) {
            $target = $i->invoiceable;
            $link = null;
            if ($target instanceof Deal) {
                $link = ['type' => 'deal', 'id' => $target->id, 'label' => trim($target->number.' · '.($target->company_name ?? ''), ' ·')];
            } elseif ($target instanceof Project) {
                $link = ['type' => 'project', 'id' => $target->id, 'label' => trim($target->number.' · '.($target->name ?? ''), ' ·')];
            } elseif ($i->invoiceable_type === 'deal' && $i->invoiceable_id) {
                $trashed = Deal::withTrashed()->find($i->invoiceable_id);
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
    }

    /** Дебиторка: выставлено − оплачено по НЕотменённым счетам. */
    private function invoiceTotals(): array
    {
        $live = fn () => $this->invoiceScope()->where('status', '!=', 'cancelled');
        $invoiced = (float) $live()->sum('amount');
        $paid = (float) Payment::whereIn('invoice_id', $live()->select('id'))->sum('amount');

        return ['invoiced' => $invoiced, 'paid' => $paid, 'debt' => max(0, $invoiced - $paid)];
    }

    /**
     * Обзор Финансов: плитки, сводка «Доход − Расходы = Прибыль», ДДС.
     *
     * Разделы (счета, поступления, задолженности, расходы) живут отдельными
     * страницами: одна страница со всем сразу прокручивалась на четыре экрана,
     * и найти на ней нужное было нельзя. Здесь остаётся картина целиком, а
     * работа идёт в разделах.
     */
    public function index(Request $request): Response
    {
        // Фильтр переживает уход со страницы: пришли без параметров —
        // подставляем сохранённый набор (App\Support\StickyFilters).
        StickyFilters::apply($request, 'finance', ['fin_month']);

        $this->guardFinance($request);

        $companyId = CurrentCompany::id();
        $invoiceTotals = $this->invoiceTotals();

        // Фильтр сводки по месяцу (YYYY-MM): остатки касса/банк и
        // задолженности — всегда «на сейчас» (накопительные).
        $finMonth = preg_match('/^\d{4}-\d{2}$/', $request->string('fin_month')->toString())
            ? $request->string('fin_month')->toString() : '';
        $mStart = $finMonth ? $finMonth.'-01' : null;
        $mEnd = $finMonth ? Carbon::parse($finMonth.'-01')->endOfMonth()->toDateString() : null;
        $monthly = fn ($q, $col = 'date') => $finMonth
            ? $q->whereDate($col, '>=', $mStart)->whereDate($col, '<=', $mEnd) : $q;

        $expScope = fn ($q) => app(FinanceService::class)->scopeCompanyExpenses($q, $companyId ?: null);

        $payroll = app(PayrollService::class);
        $fin = $payroll->companyTotals();
        $payrollRows = $payroll->perUser();

        // ---- Сводка: Доход − ВСЕ расходы = Чистая прибыль ----
        $confirmedNoPeriod = fn () => Expense::query()->tap($expScope)->where('status', 'confirmed');
        $byCategory = $monthly($confirmedNoPeriod())->whereNotNull('category_id')
            ->groupBy('category_id')->selectRaw('category_id, sum(amount) s')->pluck('s', 'category_id');
        // Выплаты сотрудникам (аванс, долг, зарплата) — расход категории
        // «Расходы по сотрудникам». В ИТОГ они не входят: зарплата стоит там
        // отдельной строкой payrollTotal, и сложить обе значило бы посчитать
        // ЗП дважды. Кассу/банк эти расходы уменьшают честно — там они деньги.
        $employeeCategoryId = ExpenseCategory::findByCode(ExpenseCategory::EMPLOYEE)?->id;
        $categories = ExpenseCategory::where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']);
        $catNames = ExpenseCategory::whereIn('id', $byCategory->keys())->pluck('name', 'id');
        $categoryRows = $byCategory
            ->map(fn ($sum, $id) => [
                'name' => $catNames[$id] ?? '—',
                'sum' => (float) $sum,
                'in_payroll' => $employeeCategoryId !== null && (int) $id === (int) $employeeCategoryId,
            ])
            ->sortByDesc('sum')->values();
        // Списание материала в сделку — НЕ трата денег: деньги ушли при закупе.
        $dealExpenses = (float) $monthly($confirmedNoPeriod())->whereNull('category_id')
            ->whereNull('material_id')->sum('amount');
        $materialWriteoffs = (float) $monthly($confirmedNoPeriod())->whereNull('category_id')
            ->whereNotNull('material_id')->sum('amount');
        $payrollTotal = round((float) $payrollRows->sum('payout'), 2);
        $taxRow = (float) $fin['tax'];
        if ($finMonth) {
            // ЗП и налог считаются по сделкам (без даты) — по месяцам не размазать.
            $payrollTotal = 0.0;
            $taxRow = 0.0;
        }
        $expensesTotal = round($categoryRows->reject(fn ($r) => $r['in_payroll'])->sum('sum')
            + $dealExpenses + $payrollTotal + $taxRow, 2);

        $debtBase = Debt::query()->when($companyId, fn ($q, $c) => $q->where('company_id', $c));
        $receivableManual = (float) (clone $debtBase)->where('type', 'receivable')->sum('amount');
        $payableManual = (float) (clone $debtBase)->where('type', 'payable')->sum('amount');

        $receiptBase = CashReceipt::query()
            ->when($companyId, fn ($q, $c) => $q->where('company_id', $c));
        $invoicePaidP = $finMonth
            ? (float) Payment::whereIn('invoice_id', $this->invoiceScope()->select('id'))
                ->whereDate('payment_date', '>=', $mStart)->whereDate('payment_date', '<=', $mEnd)->sum('amount')
            : $invoiceTotals['paid'];
        $receiptManualP = $finMonth
            ? (float) $monthly((clone $receiptBase))->sum('amount')
            : round((float) (clone $receiptBase)->sum('amount'), 2);
        $incomeTotal = round($invoicePaidP + $receiptManualP, 2);

        $balances = app(FinanceService::class)->companyBalances($companyId ?: null);
        $dealsIncome = app(FinanceService::class)->dealsIncome($companyId ?: null, $mStart, $mEnd);

        return Inertia::render('Finance/Index', [
            'invoiceTotals' => $invoiceTotals,
            'filters' => $request->only('fin_month'),
            'categories' => $categories,
            'canManage' => $request->user()->hasAnyRole(['admin', 'financist']),
            // Корректировка кассы (✎ на плитке) — только админ.
            'isAdmin' => $request->user()->hasRole('admin'),
            // ДДС — ручная сводка (Excel-стиль): счета компаний и долги.
            'dds' => [
                'accounts' => DdsEntry::where('kind', 'account')->orderBy('sort')->orderBy('id')->get(),
                'debts' => DdsEntry::where('kind', 'debt')->orderBy('sort')->orderBy('id')->get(),
                'date' => (string) Setting::get('dds_date', ''),
            ],
            'summary' => [
                'contracts' => (float) Deal::forCurrentCompany()->where('status', '!=', 'cancelled')->sum('budget'),
                'receivables' => $invoiceTotals['debt'],
                'receivablesManual' => $receivableManual,
                'receivablesTotal' => round($invoiceTotals['debt'] + $receivableManual, 2),
                'payables' => $payableManual,
                'dealsIncome' => $dealsIncome,
                'cash' => $balances['cash'],
                'bank' => $balances['bank'],
                'cashCorrection' => (float) Setting::get('cash_correction', 0),
                'income' => $incomeTotal,
                'incomeInvoices' => $invoicePaidP,
                'incomeManual' => $receiptManualP,
                'categories' => $categoryRows,
                'dealExpenses' => $dealExpenses,
                'materialWriteoffs' => $materialWriteoffs,
                'payroll' => $payrollTotal,
                'tax' => $taxRow,
                'expensesTotal' => $expensesTotal,
                'net' => round($incomeTotal - $expensesTotal, 2),
            ],
        ]);
    }

    /** Счета: сегодняшние + прошлые с поиском по номеру и статусу. */
    public function invoices(Request $request): Response
    {
        // Фильтр переживает уход со страницы: пришли без параметров —
        // подставляем сохранённый набор (App\Support\StickyFilters).
        StickyFilters::apply($request, 'invoices', ['search', 'status']);

        $this->guardFinance($request);

        $map = $this->mapInvoice();
        $today = now()->toDateString();
        $isToday = fn ($q) => $q->where(fn ($w) => $w
            ->whereDate('issue_date', $today)
            ->orWhere(fn ($n) => $n->whereNull('issue_date')->whereDate('created_at', $today)));

        $todayRows = $isToday($this->invoiceScope())
            ->with(['client:id,name', 'invoiceable'])
            ->withSum('payments as payments_sum_amount', 'amount')
            ->latest()->get()->map($map)->values();

        $pastBase = fn () => $this->invoiceScope()->whereNot(fn ($w) => $w
            ->whereDate('issue_date', $today)
            ->orWhere(fn ($n) => $n->whereNull('issue_date')->whereDate('created_at', $today)));
        $past = $pastBase()
            ->with(['client:id,name', 'invoiceable'])
            ->withSum('payments as payments_sum_amount', 'amount')
            ->when($request->string('search')->toString(), fn ($q, $s) => $q->where('number', 'like', "%{$s}%"))
            ->when($request->string('status')->toString(), fn ($q, $st) => $q->where('status', $st))
            ->latest()->limit(100)->get()->map($map)->values();

        return Inertia::render('Finance/Invoices', [
            'invoicesToday' => $todayRows,
            'invoicesPast' => $past,
            'invoicesPastStats' => ['count' => $pastBase()->count(), 'sum' => (float) $pastBase()->sum('amount')],
            'invoiceTotals' => $this->invoiceTotals(),
            'filters' => $request->only('search', 'status'),
            'canManage' => $request->user()->hasAnyRole(['admin', 'financist']),
        ]);
    }

    /** Поступления денег (нал/банк): сегодня + прошлые с поиском и периодом. */
    public function receipts(Request $request): Response
    {
        // Фильтр переживает уход со страницы: пришли без параметров —
        // подставляем сохранённый набор (App\Support\StickyFilters).
        StickyFilters::apply($request, 'receipts', ['rc_search', 'rc_from', 'rc_to']);

        $this->guardFinance($request);

        $companyId = CurrentCompany::id();
        $base = fn () => CashReceipt::query()
            ->when($companyId, fn ($q, $c) => $q->where('company_id', $c));

        $today = now()->toDateString();
        $past = fn () => $base()->whereDate('date', '<', $today);

        return Inertia::render('Finance/Receipts', [
            'receiptsToday' => $base()->with('creator:id,name')->whereDate('date', $today)->latest('id')->get(),
            'receiptsPast' => $past()->with('creator:id,name')
                ->when($request->string('rc_search')->toString(), fn ($q, $s) => $q->where(fn ($w) => $w
                    ->where('source', 'like', "%{$s}%")->orWhere('note', 'like', "%{$s}%")))
                ->when($request->string('rc_from')->toString(), fn ($q, $d) => $q->whereDate('date', '>=', $d))
                ->when($request->string('rc_to')->toString(), fn ($q, $d) => $q->whereDate('date', '<=', $d))
                ->latest('date')->latest('id')->limit(100)->get(),
            'receiptsPastStats' => ['count' => $past()->count(), 'sum' => (float) $past()->sum('amount')],
            'totals' => [
                'cash' => (float) $base()->where('method', 'cash')->sum('amount'),
                'bank' => (float) $base()->where('method', 'bank')->sum('amount'),
            ],
            'filters' => $request->only('rc_search', 'rc_from', 'rc_to'),
            'canManage' => $request->user()->hasAnyRole(['admin', 'financist']),
        ]);
    }

    /** Задолженности: дебиторка (нам должны) и кредиторка (мы должны). */
    public function debts(Request $request): Response
    {
        $this->guardFinance($request);

        $companyId = CurrentCompany::id();
        $base = Debt::query()
            ->when($companyId, fn ($q, $c) => $q->where('company_id', $c))
            ->with('creator:id,name')->latest('date')->latest('id');

        $receivables = (clone $base)->where('type', 'receivable')->get();
        $payables = (clone $base)->where('type', 'payable')->get();
        $invoiceTotals = $this->invoiceTotals();

        return Inertia::render('Finance/Debts', [
            'debts' => ['receivables' => $receivables, 'payables' => $payables],
            'totals' => [
                // Долг по счетам считается системой, ручные строки — сверху него.
                'invoices' => $invoiceTotals['debt'],
                'receivablesManual' => (float) $receivables->sum('amount'),
                'receivablesTotal' => round($invoiceTotals['debt'] + $receivables->sum('amount'), 2),
                'payables' => (float) $payables->sum('amount'),
            ],
            'canManage' => $request->user()->hasAnyRole(['admin', 'financist']),
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

        $service = app(FinanceService::class);
        $oldCash = $service->companyBalances(null)['cash'];
        // Расчётная касса БЕЗ текущей корректировки.
        $raw = $oldCash - (float) Setting::get('cash_correction', 0);
        Setting::set('cash_correction', round((float) $data['actual'] - $raw, 2));

        // История: кто и когда изменил остаток кассы (страница Аудит, admin).
        AuditLog::create([
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
        FinanceAudit::notifyDeleted(
            'Счёт '.$invoice->number.' на '.number_format((float) $invoice->amount, 0, '.', ' ').' ₸',
            $invoice->invoiceable_type,
            $invoice->invoiceable_id,
        );

        return back()->with('success', 'Счёт удалён.');
    }
}
