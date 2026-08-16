<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «Мои расходы» — личная страница сотрудника.
 *
 * Здесь только СВОИ деньги: заявки компании, которые сотрудник подал сам, и
 * выплаты, сделанные лично ему (аванс, долг). Сумм сделок на странице нет
 * намеренно — её открывают цех, повар и юрист, которым коммерческие цифры
 * не показывают.
 *
 * Чужие записи сюда не попадают ни при каком параметре адреса: выборка
 * ограничена id текущего пользователя, а не тем, что пришло в запросе.
 */
class MyExpensesController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        // Право то же, что и на подачу заявки: страница — её продолжение.
        abort_unless($user->can('expense.create'), 403, 'Страница доступна тем, кто может подавать заявки на расход.');

        $month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $request->string('month')->toString())
            ? $request->string('month')->toString()
            : now()->format('Y-m');
        $monthStart = $month.'-01';
        $monthEnd = Carbon::parse($monthStart)->endOfMonth()->toDateString();

        // Заявка компании: расход без сделки и заказа (морф пустой).
        $mine = fn (): Builder => Expense::query()
            ->whereNull('expenseable_id')
            ->where('responsible_user_id', $user->id)
            ->with('category:id,name');

        // Ждущие бухгалтера — БЕЗ фильтра месяца: заявка, поданная в прошлом
        // месяце и до сих пор не оплаченная, не должна пропадать со страницы,
        // иначе сотрудник забудет о своих деньгах. Очередь: старые сверху.
        $pending = $mine()->where('status', '!=', 'confirmed')
            ->orderBy('date')->orderBy('id')->get()
            ->map(fn (Expense $e) => $this->row($e));

        $paid = $mine()->where('status', 'confirmed')
            ->whereDate('date', '>=', $monthStart)->whereDate('date', '<=', $monthEnd)
            ->orderByDesc('date')->orderByDesc('id')->get()
            ->map(fn (Expense $e) => $this->row($e));

        // «Мне выдано»: аванс и долг — расход, где сотрудник получатель, а не
        // автор (заводит его бухгалтер).
        $payouts = Expense::query()
            ->where('employee_id', $user->id)
            ->whereDate('date', '>=', $monthStart)->whereDate('date', '<=', $monthEnd)
            ->with('category:id,name')
            ->orderByDesc('date')->orderByDesc('id')->get()
            ->map(fn (Expense $e) => $this->row($e));

        return Inertia::render('Finance/MyExpenses', [
            'pending' => $pending,
            'paid' => $paid,
            'payouts' => $payouts,
            'totals' => [
                // Плитки считает сервер: клиент только показывает (§C.5).
                'pending' => round((float) $pending->sum('amount'), 2),
                'paid' => round((float) $paid->sum('amount'), 2),
                'payouts' => round((float) $payouts->sum('amount'), 2),
            ],
            'month' => $month,
            'categories' => ExpenseCategory::where('is_active', true)
                ->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * Строка страницы.
     *
     * Отдаём только то, что показываем: путь к файлу чека наружу не уходит —
     * чек открывается через guard-маршрут, который сам проверяет права.
     *
     * @return array<string, mixed>
     */
    private function row(Expense $expense): array
    {
        return [
            'id' => $expense->id,
            'amount' => (float) $expense->amount,
            'date' => $expense->date?->toDateString(),
            'description' => $expense->description,
            'status' => $expense->status,
            'payment_method' => $expense->payment_method,
            'payout' => $expense->employee_payout,
            'category' => $expense->category?->name,
            'has_receipt' => $expense->file_path !== null,
        ];
    }
}
