<?php

namespace App\Http\Controllers;

use App\Models\CashReceipt;
use App\Support\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Поступления денег на Финансах: вводит/удаляет только бухгалтер или админ. */
class CashReceiptController extends Controller
{
    private function canManage(Request $request): bool
    {
        return $request->user()->hasAnyRole(['admin', 'financist']);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($this->canManage($request), 403, 'Поступления вводит бухгалтер или админ.');

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['required', Rule::in(['cash', 'bank'])],
            'source' => ['required', 'string', 'max:255'],
            'date' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $data['company_id'] = CurrentCompany::id() ?: null;
        $data['created_by'] = $request->user()->id;

        // Анти-дубль: та же сумма, дата и источник от того же человека младше
        // минуты — это повторная отправка формы (двойной клик, «назад» в
        // браузере), а не второе поступление.
        $duplicate = CashReceipt::where('created_by', $data['created_by'])
            ->where('amount', $data['amount'])
            ->whereDate('date', $data['date'])
            ->where('source', $data['source'])
            ->where('created_at', '>=', now()->subMinute())
            ->exists();
        if ($duplicate) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'amount' => 'Похоже на повторную отправку — такое поступление уже создано минуту назад.',
            ]);
        }

        CashReceipt::create($data);

        return back()->with('success', 'Поступление добавлено.');
    }

    public function destroy(Request $request, CashReceipt $receipt): RedirectResponse
    {
        abort_unless($this->canManage($request), 403);
        // Изоляция фирм: поступление чужой компании не удалить по прямой ссылке.
        // Изоляция фирм: спрашиваем не «какая фирма выбрана» (в режиме «Все
        // компании» она пуста, и проверка отключалась), а работает ли человек
        // в фирме поступления.
        abort_unless(
            $request->user()->worksInCompany($receipt->company_id ? (int) $receipt->company_id : null),
            403,
            'Поступление другой фирмы.'
        );

        $receipt->delete();
        \App\Support\FinanceAudit::notifyDeleted('Поступление на '.number_format((float) $receipt->amount, 0, '.', ' ').' ₸ ('.($receipt->method === 'cash' ? 'касса' : 'банк').', '.$receipt->source.')');

        return back()->with('success', 'Поступление удалено.');
    }
}
