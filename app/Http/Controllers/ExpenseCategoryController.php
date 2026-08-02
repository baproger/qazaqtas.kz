<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Справочник категорий «Расход компании» (аренда, комуслуги, бензин…):
 * добавление / переименование / удаление — бухгалтер или админ.
 */
class ExpenseCategoryController extends Controller
{
    private function authorizeManage(Request $request): void
    {
        abort_unless($request->user()->hasAnyRole(['admin', 'financist']), 403, 'Категории ведёт бухгалтер или админ.');
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeManage($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('expense_categories', 'name')->where('is_active', true)],
        ]);
        ExpenseCategory::create(['name' => trim($data['name']), 'is_active' => true]);

        return back()->with('success', 'Категория добавлена.');
    }

    public function update(Request $request, ExpenseCategory $category): RedirectResponse
    {
        $this->authorizeManage($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('expense_categories', 'name')->ignore($category->id)->where('is_active', true)],
        ]);
        $category->update(['name' => trim($data['name'])]);

        return back()->with('success', 'Категория переименована.');
    }

    /**
     * Если по категории уже есть расходы — только скрываем из списка
     * (is_active=false): суммы в разбивках/отчётах сохраняются.
     */
    public function destroy(Request $request, ExpenseCategory $category): RedirectResponse
    {
        $this->authorizeManage($request);
        if (Expense::where('category_id', $category->id)->exists()) {
            $category->update(['is_active' => false]);

            return back()->with('success', 'Категория скрыта из списка — её расходы сохранены в отчётах.');
        }
        $category->delete();

        return back()->with('success', 'Категория удалена.');
    }
}
