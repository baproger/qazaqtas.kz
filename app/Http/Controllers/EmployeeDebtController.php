<?php

namespace App\Http\Controllers;

use App\Models\EmployeeDebt;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Долги сотрудников: выдача из кассы и отмена выдачи.
 *
 * Долг отличается от аванса тем, что переходит из месяца в месяц и гасится
 * только из бонуса (см. EmployeeDebtService). Деньги при выдаче уходят
 * по-настоящему, поэтому выдача — это ещё и подтверждённый расход компании:
 * касса/банк уменьшаются сразу, как при авансе.
 */
class EmployeeDebtController extends Controller
{
    private function assertAccountant(Request $request): void
    {
        abort_unless($request->user()->hasAnyRole(['admin', 'financist']), 403, 'Долги выдаёт бухгалтер или админ.');
    }

    public function store(Request $request): RedirectResponse
    {
        $this->assertAccountant($request);

        $data = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'amount' => ['required', 'numeric', 'min:1'],
            'monthly_payment' => ['required', 'numeric', 'min:1'],
            'payment_method' => ['required', Rule::in(['cash', 'bank'])],
            'note' => ['nullable', 'string', 'max:255'],
        ], [
            'monthly_payment.required' => 'Укажите, сколько удерживать в месяц.',
            'payment_method.required' => 'Выберите, откуда выдаются деньги: касса или банк.',
        ]);

        if ((float) $data['monthly_payment'] > (float) $data['amount']) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'monthly_payment' => 'Платёж в месяц не может быть больше самого долга.',
            ]);
        }

        $employee = User::findOrFail($data['user_id']);
        $companyId = CurrentCompany::id() ?: $employee->companies()->value('companies.id');

        // Долг и его расход создаются вместе: долг без расхода — это деньги,
        // выданные мимо кассы.
        DB::transaction(function () use ($data, $employee, $companyId, $request) {
            $category = ExpenseCategory::firstOrCreate(
                ['code' => ExpenseCategory::EMPLOYEE],
                ['name' => 'Расходы по сотрудникам', 'is_active' => true]
            );

            $expense = Expense::create([
                'company_id' => $companyId,
                'category_id' => $category->id,
                'type' => 'direct',
                'amount' => $data['amount'],
                'date' => now()->toDateString(),
                'description' => 'Долг сотруднику: '.$employee->name
                    .(! empty($data['note']) ? ' — '.$data['note'] : ''),
                'responsible_user_id' => $employee->id,
                'employee_id' => $employee->id,
                'employee_payout' => 'debt',
                'status' => 'confirmed',
                'payment_method' => $data['payment_method'],
                'confirmed_by' => $request->user()->id,
                'confirmed_at' => now(),
            ]);

            EmployeeDebt::create([
                'user_id' => $employee->id,
                'company_id' => $companyId,
                'amount' => $data['amount'],
                'monthly_payment' => $data['monthly_payment'],
                'payment_method' => $data['payment_method'],
                'expense_id' => $expense->id,
                'note' => $data['note'] ?? null,
            ]);
        });

        return back()->with('success', 'Долг выдан — деньги списаны из '
            .($data['payment_method'] === 'cash' ? 'кассы' : 'банка').'.');
    }

    /**
     * Отмена выдачи: долг и его расход уходят вместе — деньги вернулись.
     * Погашенный (частично) долг так не отменить: списания из бонуса уже
     * прошли по ведомости.
     */
    public function destroy(Request $request, EmployeeDebt $debt): RedirectResponse
    {
        $this->assertAccountant($request);

        if ($debt->payments()->exists()) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'debt' => 'По долгу уже есть удержания — отменить выдачу нельзя.',
            ]);
        }

        DB::transaction(function () use ($debt) {
            Expense::find($debt->expense_id)?->delete();
            $debt->delete();
        });

        \App\Support\FinanceAudit::notifyDeleted('Долг сотрудника на '
            .number_format((float) $debt->amount, 0, '.', ' ').' ₸');

        return back()->with('success', 'Выдача долга отменена — деньги вернулись в кассу.');
    }
}
