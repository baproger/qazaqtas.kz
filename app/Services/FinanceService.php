<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class FinanceService
{
    /**
     * Остатки денег (касса/банк) = платежи по счетам + поступления
     * (cash_receipts) − подтверждённые расходы, всё по способу оплаты.
     *
     * КАССА (наличные) — ЕДИНАЯ на весь холдинг: физически деньги в одной
     * кассе, расход налом ЛЮБОЙ фирмы уменьшает общий остаток.
     * БАНК — раздельно по компаниям (у каждой фирмы свои счета).
     * Показывается на Финансах и бухгалтеру в форме расхода («доступно N»).
     */
    public function companyBalances(?int $companyId): array
    {
        return [
            'cash' => $this->methodBalance(null, 'cash'),       // весь холдинг
            'bank' => $this->methodBalance($companyId, 'bank'), // своя фирма
        ];
    }

    /**
     * Скоуп расходов фирмы: по сделке, по заказу цеха и расход КОМПАНИИ
     * (аренда/интернет/бензин — без сделки, по company_id).
     *
     * Живёт здесь, а не копией в каждом контроллере: остатки, Финансы и
     * рабочее место бухгалтера обязаны отбирать ОДНИ И ТЕ ЖЕ записи —
     * разошедшийся скоуп означает страницы с разными цифрами.
     *
     * @param  Builder<\App\Models\Expense>  $query
     * @return Builder<\App\Models\Expense>
     */
    public function scopeCompanyExpenses(Builder $query, ?int $companyId): Builder
    {
        return $query->when($companyId, fn ($q, $c) => $q->where(fn ($w) => $w
            ->where(fn ($d) => $d->where('expenseable_type', 'deal')
                ->whereIn('expenseable_id', \App\Models\Deal::where('company_id', $c)->select('id')))
            ->orWhere(fn ($p) => $p->where('expenseable_type', 'project')
                ->whereIn('expenseable_id', \App\Models\Project::whereHas('deal', fn ($d) => $d->where('company_id', $c))->select('id')))
            ->orWhere('company_id', $c)));
    }

    /**
     * Скоуп счетов фирмы (счета заказов цеха идут через сделку заказа).
     *
     * @param  Builder<Invoice>  $query
     * @return Builder<Invoice>
     */
    public function scopeCompanyInvoices(Builder $query, ?int $companyId): Builder
    {
        return $query->when($companyId, fn ($q, $c) => $q->where(fn ($w) => $w
            ->where(fn ($d) => $d->where('invoiceable_type', 'deal')
                ->whereIn('invoiceable_id', \App\Models\Deal::where('company_id', $c)->select('id')))
            ->orWhere(fn ($p) => $p->where('invoiceable_type', 'project')
                ->whereIn('invoiceable_id', \App\Models\Project::whereHas('deal', fn ($d) => $d->where('company_id', $c))->select('id')))));
    }

    /**
     * Остаток на НАЧАЛО дня — тот же расчёт, что и текущий остаток, только
     * по операциям СТРОГО ДО даты. Кассовая книга берёт его отсюда, а не
     * считает по-своему: иначе «конец дня» книги разошёлся бы с плиткой
     * Финансов на первой же правке формулы.
     */
    public function balanceBefore(?int $companyId, string $kind, string $date): float
    {
        return $this->methodBalance($companyId, $kind, $date);
    }

    /**
     * Остаток по способу оплаты (cash|bank); $companyId null = без фильтра фирмы.
     * $before (YYYY-MM-DD) — считать только операции ДО этой даты.
     */
    private function methodBalance(?int $companyId, string $kind, ?string $before = null): float
    {
        $invIds = $this->scopeCompanyInvoices(Invoice::query(), $companyId)->select('id');

        $pay = Payment::whereIn('invoice_id', $invIds)
            ->when($before, fn ($q, $d) => $q->whereDate('payment_date', '<', $d));
        $pay = $kind === 'cash'
            ? $pay->where('payment_method', 'cash')
            // Банк = всё, что не нал (включая платежи без указанного способа — как раньше).
            : $pay->where(fn ($q) => $q->where('payment_method', '!=', 'cash')->orWhereNull('payment_method'));
        $paySum = (float) $pay->sum('amount');

        $recSum = (float) \App\Models\CashReceipt::query()
            ->when($companyId, fn ($q, $c) => $q->where('company_id', $c))
            ->when($before, fn ($q, $d) => $q->whereDate('date', '<', $d))
            ->where('method', $kind === 'cash' ? 'cash' : 'bank')->sum('amount');

        $exp = $this->scopeCompanyExpenses(
            \App\Models\Expense::query()->where('status', 'confirmed'),
            $companyId,
        )->when($before, fn ($q, $d) => $q->whereDate('date', '<', $d));
        $expSum = (float) ($kind === 'cash'
            ? (clone $exp)->where('payment_method', 'cash')->sum('amount')
            : (clone $exp)->where('payment_method', '!=', 'cash')->whereNotNull('payment_method')->sum('amount'));

        // Корректировка кассы (инвентаризация): финансист задал фактический
        // остаток — разница хранится в Setting и прибавляется к расчёту.
        // История платежей/расходов не трогается, отчёты не искажаются.
        $correction = $kind === 'cash' ? (float) \App\Models\Setting::get('cash_correction', 0) : 0.0;

        return round($paySum + $recSum - $expSum + $correction, 2);
    }

    /**
     * «Доход» как в итогах Сводного отчёта: по каждой сделке компании (кроме
     * отменённых) остаток − бонус менеджера, суммой. Та же формула, что в
     * ReportController — цифры на Финансах и в отчёте совпадают.
     */
    public function dealsIncome(?int $companyId, ?string $from = null, ?string $to = null): float
    {
        $taxRate = ((float) \App\Models\Setting::get('tax_percent', 3)) / 100;
        $deals = \App\Models\Deal::query()
            ->when($companyId, fn ($q, $c) => $q->where('company_id', $c))
            ->where('status', '!=', 'cancelled')
            // Период (фильтр «Месяц» на Финансах): сделки по дате договора,
            // без даты договора — по дате создания.
            ->when($from && $to, fn ($q) => $q->where(fn ($w) => $w
                // whereDate, не whereBetween: дата хранится с временем 00:00:00,
                // и строгий whereBetween терял сделки последнего дня месяца.
                ->where(fn ($c) => $c->whereDate('contract_date', '>=', $from)->whereDate('contract_date', '<=', $to))
                ->orWhere(fn ($n) => $n->whereNull('contract_date')->whereDate('created_at', '>=', $from)->whereDate('created_at', '<=', $to))))
            ->get(['id', 'budget', 'partner_pct', 'bonus_rate_override', 'responsible_user_id']);

        $expByDeal = \App\Models\Expense::where('status', 'confirmed')
            ->where('expenseable_type', 'deal')
            ->whereIn('expenseable_id', $deals->pluck('id'))
            ->groupBy('expenseable_id')->selectRaw('expenseable_id d, sum(amount) s')->pluck('s', 'd');

        $materials = \App\Services\PayrollService::materialsByDeal($deals->pluck('id'));

        return round($deals->sum(function ($d) use ($expByDeal, $materials, $taxRate) {
            $budget = (float) $d->budget;
            $tax = round($budget * $taxRate, 2);
            $expense = (float) ($expByDeal[$d->id] ?? 0);
            $remainder = round($budget - $tax - $expense
                - \App\Services\PayrollService::partnerSum($budget, $d->partner_pct), 2);

            return $remainder - \App\Services\PayrollService::dealBonus($budget, $remainder, $tax, $expense,
                $d->bonus_rate_override !== null ? (float) $d->bonus_rate_override : null,
                \App\Services\PayrollService::userBonusPercent($d->responsible_user_id),
                (float) ($materials['sale'][$d->id] ?? 0), (float) ($materials['cost'][$d->id] ?? 0))['total'];
        }), 2);
    }

    /**
     * Recalculate an invoice's status from its payments. Atomic and lock-safe.
     */
    public function recalcInvoiceStatus(Invoice $invoice): Invoice
    {
        return DB::transaction(function () use ($invoice) {
            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if (in_array($invoice->status, ['cancelled', 'draft'], true)) {
                return $invoice;
            }

            $paid = (float) $invoice->payments()->sum('amount');
            $amount = (float) $invoice->amount;

            if ($paid <= 0) {
                $invoice->status = 'sent';
            } elseif ($paid + 0.001 >= $amount) {
                $invoice->status = 'paid';
            } else {
                $invoice->status = 'partially_paid';
            }

            $invoice->save();

            return $invoice;
        });
    }

    /**
     * Compute financial summary for a deal/project.
     * income  = sum of all payments across the entity's invoices
     * expense = sum of confirmed expenses
     * margin  = (income - expense) / income * 100
     *
     * @return array{income: float, expense: float, profit: float, margin: float, invoiced: float}
     */
    public function summaryFor(Model $entity): array
    {
        $invoiceIds = $entity->invoices()->pluck('id');

        $income = (float) Payment::whereIn('invoice_id', $invoiceIds)->sum('amount');
        $invoiced = (float) $entity->invoices()->whereNotIn('status', ['cancelled'])->sum('amount');
        $expense = (float) $entity->expenses()->where('status', 'confirmed')->sum('amount');
        $profit = $income - $expense;
        $margin = $income > 0 ? round($profit / $income * 100, 2) : 0.0;

        // Planned figures use the deal/project budget (won sum) as expected revenue.
        $budget = (float) ($entity->budget ?? 0);
        $plannedProfit = $budget - $expense;
        $plannedMargin = $budget > 0 ? round($plannedProfit / $budget * 100, 2) : 0.0;
        // Markup = profit relative to costs; expenseRatio = costs share of income.
        $markup = $expense > 0 ? round($profit / $expense * 100, 2) : 0.0;
        $expenseRatio = $income > 0 ? round($expense / $income * 100, 2) : 0.0;

        return compact('income', 'invoiced', 'expense', 'profit', 'margin', 'budget', 'plannedProfit', 'plannedMargin', 'markup', 'expenseRatio');
    }
}
