<?php

namespace App\Http\Controllers;

use App\Models\CashReceipt;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Setting;
use App\Services\FinanceService;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Касса — кассовая книга за день: остаток на начало → операции → остаток на
 * конец. Заменяет бумажный «Отчёт кассира», поэтому и печатается как он.
 *
 * Цифры берутся ТЕМИ ЖЕ скоупами, что плитки Финансов (FinanceService):
 * книга, у которой конец дня не сходится с остатком на плитке, бесполезна.
 * Касса — ЕДИНАЯ на холдинг, банк — по своей фирме; в режиме «Общее» каждый
 * поток считается по своему правилу, а не сваливается в один скоуп.
 *
 * Выборки ограничены ОДНИМ днём (§C.3): остаток на начало — три агрегата,
 * а не загрузка истории в память.
 */
class CashBookController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->hasAnyRole(['admin', 'director', 'financist']) && $user->can('payment.viewAny'), 403, 'Касса — для бухгалтерии и руководства.');

        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $request->string('date')->toString())
            ? $request->string('date')->toString()
            : now()->toDateString();
        $mode = in_array($request->string('mode')->toString(), ['cash', 'bank', 'all'], true)
            ? $request->string('mode')->toString()
            : 'cash';

        $finance = app(FinanceService::class);
        $companyId = CurrentCompany::id() ?: null;
        $kinds = $mode === 'all' ? ['cash', 'bank'] : [$mode];

        $opening = 0.0;
        $rows = collect();
        foreach ($kinds as $kind) {
            // Касса общая на холдинг (деньги физически в одной кассе), банк —
            // по своей фирме. Правило то же, что в FinanceService.
            $scope = $kind === 'cash' ? null : $companyId;
            $opening += $finance->balanceBefore($scope, $kind, $date);
            $rows = $rows->concat($this->flows($finance, $kind, $scope, $date));
        }

        // Лента дня — по времени записи: у даты операции времени нет. При
        // совпадении времени сначала приход, потом расход — так читается
        // бумажный отчёт кассира; порядок обязан быть определённым, иначе
        // промежуточный остаток скакал бы от запроса к запросу.
        $rows = $rows->sortBy([['at', 'asc'], ['order', 'asc'], ['seq', 'asc']])
            ->map(fn (array $row) => Arr::except($row, ['order', 'seq']))
            ->values();

        $income = round((float) $rows->where('sign', 1)->sum('amount'), 2);
        $outcome = round((float) $rows->where('sign', -1)->sum('amount'), 2);
        $opening = round($opening, 2);

        // Промежуточный баланс считает СЕРВЕР — клиент только показывает.
        $running = $opening;
        $rows = $rows->map(function (array $row) use (&$running) {
            $running = round($running + $row['sign'] * $row['amount'], 2);

            return $row + ['balance' => $running];
        });

        return Inertia::render('Finance/CashBook', [
            'date' => $date,
            'mode' => $mode,
            'rows' => $rows,
            'totals' => [
                'opening' => $opening,
                'income' => $income,
                'outcome' => $outcome,
                'closing' => round($opening + $income - $outcome, 2),
            ],
            // Плитка кассы на Финансах помечена так же — книга не должна
            // выглядеть «другой» цифрой без объяснения.
            'cashCorrection' => in_array('cash', $kinds, true) ? (float) Setting::get('cash_correction', 0) : 0.0,
        ]);
    }

    /**
     * Движение денег за день по одному способу оплаты.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function flows(FinanceService $finance, string $kind, ?int $companyId, string $date): Collection
    {
        $invoiceIds = $finance->scopeCompanyInvoices(Invoice::query(), $companyId)->select('id');

        $payments = Payment::whereIn('invoice_id', $invoiceIds)
            ->whereDate('payment_date', $date)
            ->when($kind === 'cash',
                fn ($q) => $q->where('payment_method', 'cash'),
                // Банк = всё, что не нал (включая платежи без способа) — как в остатках.
                fn ($q) => $q->where(fn ($w) => $w->where('payment_method', '!=', 'cash')->orWhereNull('payment_method')))
            ->with('invoice.invoiceable')
            ->get()
            ->map(fn (Payment $p) => [
                'id' => 'pay-'.$p->id,
                'seq' => $p->id,
                'order' => 0,
                'at' => $p->created_at?->toDateTimeString(),
                'kind' => $kind,
                'type' => 'invoice',
                'title' => 'Оплата по счёту '.($p->invoice?->number ?: '—'),
                'party' => $p->invoice?->invoiceable?->company_name ?: $p->invoice?->invoiceable?->name,
                'link' => $this->entityLink($p->invoice?->invoiceable_type, $p->invoice?->invoiceable_id),
                'amount' => (float) $p->amount,
                'sign' => 1,
                'payout' => null,
                'employee' => null,
            ]);

        $receipts = CashReceipt::query()
            ->when($companyId, fn ($q, $c) => $q->where('company_id', $c))
            ->where('method', $kind)
            ->whereDate('date', $date)
            ->with('creator:id,name')
            ->get()
            ->map(fn (CashReceipt $r) => [
                'id' => 'rec-'.$r->id,
                'seq' => $r->id,
                'order' => 1,
                'at' => $r->created_at?->toDateTimeString(),
                'kind' => $kind,
                'type' => 'receipt',
                'title' => $r->note ?: 'Поступление',
                'party' => $r->source,
                'link' => null,
                'amount' => (float) $r->amount,
                'sign' => 1,
                'payout' => null,
                'employee' => null,
            ]);

        // Только ПОДТВЕРЖДЁННЫЕ: заявка, ждущая бухгалтера, денег ещё не двигала.
        $expenses = $finance->scopeCompanyExpenses(Expense::query()->where('status', 'confirmed'), $companyId)
            ->when($kind === 'cash',
                fn ($q) => $q->where('payment_method', 'cash'),
                fn ($q) => $q->where('payment_method', '!=', 'cash')->whereNotNull('payment_method'))
            ->whereDate('date', $date)
            ->with(['category:id,name', 'employee:id,name', 'expenseable'])
            ->get()
            ->map(fn (Expense $e) => [
                'id' => 'exp-'.$e->id,
                'seq' => $e->id,
                'order' => 2,
                'at' => $e->created_at?->toDateTimeString(),
                'kind' => $kind,
                'type' => 'expense',
                'title' => $e->description ?: ($e->category?->name ?: 'Расход'),
                'party' => $e->category?->name,
                'link' => $this->entityLink($e->expenseable_type, $e->expenseable_id),
                'amount' => (float) $e->amount,
                'sign' => -1,
                // Выплата сотруднику: видно, кому и по какому поводу.
                'payout' => $e->employee_payout,
                'employee' => $e->employee?->only('id', 'name'),
            ]);

        return $payments->concat($receipts)->concat($expenses);
    }

    private function entityLink(?string $type, ?int $id): ?string
    {
        if (! $id) {
            return null;
        }

        return $type === 'project' ? route('projects.show', $id) : route('deals.show', $id);
    }
}
