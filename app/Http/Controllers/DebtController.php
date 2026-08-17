<?php

namespace App\Http\Controllers;

use App\Models\Debt;
use App\Support\CurrentCompany;
use App\Support\FinanceAudit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Задолженности (дебиторка/кредиторка) — ведёт бухгалтер или админ. */
class DebtController extends Controller
{
    private function canManage(Request $request): bool
    {
        return $request->user()->hasAnyRole(['admin', 'financist']);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'type' => ['required', Rule::in(Debt::TYPES)],
            'counterparty' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'date' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($this->canManage($request), 403, 'Задолженности ведёт бухгалтер или админ.');

        $data = $this->validated($request);
        $data['company_id'] = CurrentCompany::id() ?: null;
        $data['created_by'] = $request->user()->id;
        Debt::create($data);

        return back()->with('success', 'Задолженность добавлена.');
    }

    public function update(Request $request, Debt $debt): RedirectResponse
    {
        abort_unless($this->canManage($request), 403);
        $this->assertCompany($debt);

        $debt->update($this->validated($request));

        return back()->with('success', 'Задолженность обновлена.');
    }

    public function destroy(Request $request, Debt $debt): RedirectResponse
    {
        abort_unless($this->canManage($request), 403);
        $this->assertCompany($debt);

        $label = ($debt->type === 'payable' ? 'Кредиторская' : 'Дебиторская').' задолженность «'
            .$debt->counterparty.'» на '.number_format((float) $debt->amount, 0, '.', ' ').' ₸';
        $debt->delete();
        FinanceAudit::notifyDeleted($label);

        return back()->with('success', 'Задолженность удалена.');
    }

    /** Изоляция фирм: запись чужой компании недоступна по прямой ссылке. */
    /**
     * Изоляция фирм.
     *
     * Раньше проверка отключалась в режиме «Все компании» (CurrentCompany::id()
     * там пустой) — и запись чужой фирмы удалялась по прямой ссылке. Теперь
     * спрашиваем не «какая фирма выбрана», а «работает ли человек в фирме
     * записи»: у админа доступ ко всем, у остальных — только к своим.
     */
    private function assertCompany(Debt $debt): void
    {
        abort_unless(
            request()->user()->worksInCompany($debt->company_id ? (int) $debt->company_id : null),
            403,
            'Запись другой фирмы.'
        );
    }
}
