<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Services\FinanceService;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «Расходы» — рабочее место бухгалтера.
 *
 * Очередь всего, что ждёт проверки: заявки сотрудников и расходы по сделкам.
 * Подтверждает их ПРЕЖНИЙ ExpenseController::confirm — второго пути к деньгам
 * не заводим, иначе правила подтверждения разъехались бы по двум местам.
 *
 * Директор смотрит, но не подтверждает: кнопок ему не показываем, а сам
 * confirm его и не пустит.
 */
class ExpensesBoardController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->hasAnyRole(['admin', 'director', 'financist']), 403, 'Страница расходов — для бухгалтерии и руководства.');

        $companyId = CurrentCompany::id() ?: null;
        $scoped = fn (): Builder => app(FinanceService::class)
            ->scopeCompanyExpenses(Expense::query(), $companyId)
            ->with(['category:id,name', 'responsible:id,name', 'employee:id,name', 'expenseable']);

        $month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $request->string('month')->toString())
            ? $request->string('month')->toString()
            : now()->format('Y-m');
        $monthStart = $month.'-01';
        $monthEnd = Carbon::parse($monthStart)->endOfMonth()->toDateString();

        // Очередь — БЕЗ фильтра месяца: заявка прошлого месяца, которую не
        // оплатили, обязана остаться на глазах. Старые сверху — кто ждёт
        // дольше, тот и первый.
        $pending = $scoped()->where('status', 'pending')
            ->orderBy('date')->orderBy('id')->get()
            ->map(fn (Expense $e) => $this->row($e));

        // Оплаченных за месяц бывают сотни — здесь пагинация обязательна.
        $paid = $scoped()->where('status', 'confirmed')
            ->with('confirmedBy:id,name')
            ->whereDate('date', '>=', $monthStart)->whereDate('date', '<=', $monthEnd)
            ->orderByDesc('date')->orderByDesc('id')
            ->paginate(30)->withQueryString()
            ->through(fn (Expense $e) => $this->row($e));

        return Inertia::render('Finance/ExpensesBoard', [
            'pending' => $pending,
            'pendingTotal' => round((float) $pending->sum('amount'), 2),
            'paid' => $paid,
            'paidTotal' => round((float) $scoped()->where('status', 'confirmed')
                ->whereDate('date', '>=', $monthStart)->whereDate('date', '<=', $monthEnd)
                ->sum('amount'), 2),
            'month' => $month,
            // Директор — наблюдатель: деньгами распоряжается бухгалтерия.
            'canConfirm' => $user->hasAnyRole(['admin', 'financist']),
            // Форма «Расход компании» и список категорий — прямо здесь:
            // бухгалтер работает с расходами на этой странице, и уходить за
            // ними на Финансы незачем.
            'categories' => \App\Models\ExpenseCategory::where('is_active', true)
                ->orderBy('name')->get(['id', 'code', 'name']),
            'balances' => app(FinanceService::class)->companyBalances($companyId),
        ]);
    }

    /**
     * Карточка очереди. Путь к файлу наружу не отдаём — чек открывается
     * guard-маршрутом, который сам проверяет права.
     *
     * @return array<string, mixed>
     */
    private function row(Expense $expense): array
    {
        $entity = $expense->expenseable;

        return [
            'id' => $expense->id,
            'amount' => (float) $expense->amount,
            'date' => $expense->date?->toDateString(),
            'description' => $expense->description,
            'category' => $expense->category?->name,
            'payment_method' => $expense->payment_method,
            'payout' => $expense->employee_payout,
            'author' => $expense->responsible?->only('id', 'name'),
            'employee' => $expense->employee?->only('id', 'name'),
            'confirmed_by' => $expense->confirmedBy?->name,
            // Откуда расход: сделка/заказ цеха или заявка компании (пусто).
            'source' => $entity ? [
                'type' => $expense->expenseable_type,
                'id' => $expense->expenseable_id,
                'number' => $entity->number ?? null,
                // Имя заказчика: по номеру сделку помнят не все.
                'title' => $entity->company_name ?? $entity->name ?? null,
            ] : null,
            'receipt' => $expense->file_path === null ? null : [
                // PDF показываем ссылкой, картинку — сразу в карточке.
                'kind' => strtolower(pathinfo($expense->file_path, PATHINFO_EXTENSION)) === 'pdf' ? 'pdf' : 'image',
            ],
        ];
    }
}
