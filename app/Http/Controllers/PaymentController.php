<?php

namespace App\Http\Controllers;

use App\Http\Requests\PaymentRequest;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\FinanceService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class PaymentController extends Controller
{
    /** A plain manager may only touch finance tied to their own deal/project. */
    private function assertOwnership(User $user, ?Model $entity): void
    {
        // Изоляция фирм: финансы чужой компании недоступны никому,
        // кто к этой компании не привязан, — включая финансиста и директора.
        $companyId = $entity instanceof \App\Models\Project ? $entity->deal?->company_id : $entity?->company_id;
        abort_unless($entity === null || $user->worksInCompany($companyId ? (int) $companyId : null), 403);

        if ($user->hasRole('manager') && ! $user->hasAnyRole(['admin', 'director', 'financist'])) {
            abort_unless($entity && $entity->responsible_user_id === $user->id, 403);
        }
    }

    public function store(PaymentRequest $request, FinanceService $finance): RedirectResponse
    {
        $this->authorize('create', Invoice::class);
        $invoice = Invoice::findOrFail($request->integer('invoice_id'));
        $this->assertOwnership($request->user(), $invoice->invoiceable);

        DB::transaction(function () use ($request, $finance, $invoice) {
            // Счёт блокируем и остаток перечитываем ВНУТРИ транзакции: двойной
            // клик присылает два одинаковых платежа, и без блокировки оба
            // видели бы «не оплачено» — счёт оплачивался дважды, а дебиторка
            // уходила в минус.
            $locked = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $paid = (float) $locked->payments()->sum('amount');
            $left = round((float) $locked->amount - $paid, 2);
            $amount = (float) $request->validated()['amount'];

            if ($left <= 0) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'amount' => 'Счёт '.$locked->number.' уже оплачен полностью.',
                ]);
            }
            if ($amount - $left > 0.005) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'amount' => 'Платёж больше остатка по счёту: осталось '
                        .number_format($left, 2, '.', ' ').' ₸.',
                ]);
            }

            $payment = Payment::create($request->validated());
            $finance->recalcInvoiceStatus($payment->invoice);
        });

        return back()->with('success', 'Платёж добавлен.');
    }

    public function destroy(Payment $payment, FinanceService $finance): RedirectResponse
    {
        $this->authorize('delete', $payment->invoice);
        $this->assertOwnership(request()->user(), $payment->invoice->invoiceable);

        DB::transaction(function () use ($payment, $finance) {
            $invoice = $payment->invoice;
            $payment->delete();
            $finance->recalcInvoiceStatus($invoice);
        });
        \App\Support\FinanceAudit::notifyDeleted(
            'Платёж на '.number_format((float) $payment->amount, 0, '.', ' ').' ₸ по счёту '.$payment->invoice->number,
            $payment->invoice->invoiceable_type,
            $payment->invoice->invoiceable_id,
        );

        return back()->with('success', 'Платёж удалён.');
    }
}
