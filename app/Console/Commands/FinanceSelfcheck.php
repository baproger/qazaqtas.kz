<?php

namespace App\Console\Commands;

use App\Models\CashReceipt;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Setting;
use App\Services\FinanceService;
use App\Services\PayrollService;
use Illuminate\Console\Command;

/**
 * Сквозная проверка денежных инвариантов на ЖИВЫХ данных.
 *
 * ERP заменяет тетради бухгалтерии, и доверие владельца держится на том, что
 * цифры сходятся. Баг в расчёте или ручная правка в базе должны находиться
 * командой за минуту, а не спором «какая страница врёт».
 *
 * Проверяются вещи, которые обязаны совпадать по определению:
 *   1. Касса и банк из FinanceService == пересчёт с нуля по платежам,
 *      поступлениям и подтверждённым расходам.
 *   2. Дебиторка == выставлено − оплачено по НЕотменённым счетам.
 *   3. Сумма «К выплате» по сотрудникам == итогу ведомости ЗП.
 *   4. Оплаты по счёту не превышают сам счёт (двойной клик, ручная правка).
 *   5. Выплаты сотрудникам не попадают в итог расходов дважды.
 */
class FinanceSelfcheck extends Command
{
    protected $signature = 'finance:selfcheck {--company= : id фирмы, по умолчанию все}';

    protected $description = 'Проверить сходимость денег: касса, банк, дебиторка, ЗП';

    /** @var array<int, array{0: string, 1: float|string, 2: float|string, 3: float}> */
    private array $rows = [];

    public function handle(FinanceService $finance, PayrollService $payroll): int
    {
        $companyId = $this->option('company') ? (int) $this->option('company') : null;

        $this->checkBalances($finance, $companyId);
        $this->checkReceivables($companyId);
        $this->checkPayroll($payroll);
        $this->checkOverpaidInvoices();
        $this->checkCashBook($finance, $companyId);
        $this->reportEmployeePayouts($companyId);

        $this->table(['Инвариант', 'Ожидание', 'Факт', 'Расхождение'], array_map(
            fn (array $row) => [
                $row[0],
                is_float($row[1]) ? number_format($row[1], 2, '.', ' ') : $row[1],
                is_float($row[2]) ? number_format($row[2], 2, '.', ' ') : $row[2],
                abs($row[3]) < 0.01 ? '—' : number_format($row[3], 2, '.', ' '),
            ],
            $this->rows,
        ));

        $broken = array_filter($this->rows, fn (array $row) => abs($row[3]) >= 0.01);
        if ($broken) {
            $this->error('Расхождений: '.count($broken).'. Деньги не сходятся — разбираться нужно до отчётов.');

            return self::FAILURE;
        }

        $this->info('Все инварианты сошлись.');

        return self::SUCCESS;
    }

    private function add(string $name, float|string $expected, float|string $actual, float $diff): void
    {
        $this->rows[] = [$name, $expected, $actual, $diff];
    }

    /** Касса и банк: сервис против прямого пересчёта по первичным записям. */
    private function checkBalances(FinanceService $finance, ?int $companyId): void
    {
        $balances = $finance->companyBalances($companyId);

        // Касса — ЕДИНАЯ на холдинг (деньги физически в одной кассе), поэтому
        // считается без фильтра фирмы; банк — по своей.
        foreach (['cash' => null, 'bank' => $companyId] as $kind => $scope) {
            $invoiceIds = $finance->scopeCompanyInvoices(Invoice::query(), $scope)->select('id');
            $payments = Payment::whereIn('invoice_id', $invoiceIds);
            $payments = $kind === 'cash'
                ? $payments->where('payment_method', 'cash')
                : $payments->where(fn ($q) => $q->where('payment_method', '!=', 'cash')->orWhereNull('payment_method'));

            $receipts = CashReceipt::query()
                ->when($scope, fn ($q, $c) => $q->where('company_id', $c))
                ->where('method', $kind);

            $expenses = $finance->scopeCompanyExpenses(Expense::query()->where('status', 'confirmed'), $scope);
            $expenses = $kind === 'cash'
                ? $expenses->where('payment_method', 'cash')
                : $expenses->where('payment_method', '!=', 'cash')->whereNotNull('payment_method');

            $correction = $kind === 'cash' ? (float) Setting::get('cash_correction', 0) : 0.0;
            $expected = round((float) $payments->sum('amount') + (float) $receipts->sum('amount')
                - (float) $expenses->sum('amount') + $correction, 2);

            $this->add(
                $kind === 'cash' ? 'Касса == приход − расход' : 'Банк == приход − расход',
                $expected,
                $balances[$kind],
                round($balances[$kind] - $expected, 2),
            );
        }
    }

    /**
     * Дебиторка: общий остаток должен совпадать с суммой остатков по каждому
     * счёту. Разойдутся они ровно тогда, когда какой-то счёт переплачен —
     * «минус» одного счёта гасил бы долг другого и прятал ошибку.
     */
    private function checkReceivables(?int $companyId): void
    {
        $invoices = app(FinanceService::class)->scopeCompanyInvoices(Invoice::query(), $companyId)
            ->where('status', '!=', 'cancelled')->get(['id', 'number', 'amount']);

        $paidById = Payment::whereIn('invoice_id', $invoices->pluck('id'))
            ->groupBy('invoice_id')->selectRaw('invoice_id, sum(amount) s')->pluck('s', 'invoice_id');

        $totalDebt = round(max(0, (float) $invoices->sum('amount')
            - (float) $paidById->sum()), 2);
        $perInvoiceDebt = round($invoices->sum(
            fn ($i) => max(0, (float) $i->amount - (float) ($paidById[$i->id] ?? 0)),
        ), 2);

        $this->add('Дебиторка == Σ остатков по счетам', $perInvoiceDebt, $totalDebt, round($totalDebt - $perInvoiceDebt, 2));

        $cancelled = round((float) app(FinanceService::class)
            ->scopeCompanyInvoices(Invoice::query(), $companyId)
            ->where('status', 'cancelled')->sum('amount'), 2);
        if ($cancelled > 0) {
            $this->line('  <comment>Отменённых счетов на '.number_format($cancelled, 2, '.', ' ').' ₸ — в дебиторку они не идут.</comment>');
        }
    }

    /** Ведомость ЗП: сумма строк == итогу. */
    private function checkPayroll(PayrollService $payroll): void
    {
        $rows = $payroll->perUser(true);
        $sum = round((float) $rows->sum('payout'), 2);
        // Бонус человека = сделки + выработка цеха. Проверять только по
        // сделкам значило бы каждый месяц ловить ложное расхождение на
        // зарплате бригад.
        $bySalaryAndBonus = round((float) $rows->sum('salary')
            + (float) $rows->sum('bonus') + (float) $rows->sum('bonus_production'), 2);

        $this->add('ЗП: Σ «К выплате» == оклады + бонусы', $bySalaryAndBonus, $sum, round($sum - $bySalaryAndBonus, 2));
    }

    /** Переплата по счёту: платежей больше, чем сумма счёта. */
    private function checkOverpaidInvoices(): void
    {
        $overpaid = Invoice::query()
            ->whereRaw('(select coalesce(sum(amount), 0) from payments where payments.invoice_id = invoices.id) > invoices.amount + 0.01')
            ->get(['id', 'number', 'amount']);

        $this->add('Счетов с переплатой', 0, $overpaid->count(), (float) $overpaid->count());

        foreach ($overpaid as $invoice) {
            $paid = (float) Payment::where('invoice_id', $invoice->id)->sum('amount');
            $this->line('  <fg=red>Счёт '.$invoice->number.': оплачено '.number_format($paid, 2, '.', ' ')
                .' при сумме '.number_format((float) $invoice->amount, 2, '.', ' ').'</>');
        }
    }

    /**
     * Кассовая книга стыкуется с плитками: остаток «на начало завтрашнего дня»
     * — это и есть текущий остаток. Разойдутся они, если книга и плитка
     * считают разными скоупами.
     */
    private function checkCashBook(FinanceService $finance, ?int $companyId): void
    {
        $tomorrow = now()->addDay()->toDateString();
        $balances = $finance->companyBalances($companyId);

        foreach (['cash' => null, 'bank' => $companyId] as $kind => $scope) {
            $book = round($finance->balanceBefore($scope, $kind, $tomorrow), 2);
            $this->add(
                $kind === 'cash' ? 'Книга: касса на конец дня == плитке' : 'Книга: банк на конец дня == плитке',
                $balances[$kind],
                $book,
                round($book - $balances[$kind], 2),
            );
        }
    }

    /** Сколько денег ушло сотрудникам — справкой: в итог расходов они не входят. */
    private function reportEmployeePayouts(?int $companyId): void
    {
        $category = ExpenseCategory::findByCode(ExpenseCategory::EMPLOYEE);
        if (! $category) {
            return;
        }

        $sum = round((float) app(FinanceService::class)
            ->scopeCompanyExpenses(Expense::query()->where('status', 'confirmed'), $companyId)
            ->where('category_id', $category->id)->sum('amount'), 2);

        if ($sum > 0) {
            $this->line('  <comment>Выплат сотрудникам на '.number_format($sum, 2, '.', ' ')
                .' ₸ — кассу они уменьшают, но в итог «Расходы» не входят (там строка «Зарплата»).</comment>');
        }
    }
}
