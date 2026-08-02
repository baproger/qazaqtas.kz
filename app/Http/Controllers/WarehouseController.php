<?php

namespace App\Http\Controllers;

use App\Models\Deal;
use App\Models\Material;
use App\Models\MaterialReceipt;
use App\Support\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Склад: у каждой компании (BAIA/ASU) свой. Приход оформляет бухгалтер /
 * директор / админ; менеджеры видят остатки (для расходов по материалам).
 */
class WarehouseController extends Controller
{
    /** Управление складом (приход, правка, удаление) — только бухгалтер и админ. */
    private function canManage(Request $request): bool
    {
        return $request->user()->hasAnyRole(['admin', 'financist']);
    }

    public function index(Request $request): Response
    {
        abort_unless($request->user()->hasAnyRole(['admin', 'director', 'financist', 'manager']), 403);

        $allMode = CurrentCompany::id() === 0;
        $materials = Material::forCurrentCompany()
            ->when($allMode, fn ($q) => $q->with('company:id,name'))
            ->orderBy('name')->get();

        // Период поступления/списания (необязательный) — влияет на колонки
        // «Поступление», «Сумма», «Списание»; остаток всегда текущий.
        $from = $request->string('from')->toString();
        $to = $request->string('to')->toString();
        $ids = $materials->pluck('id');

        $received = MaterialReceipt::whereIn('material_id', $ids)
            ->when($from, fn ($q, $d) => $q->whereDate('date', '>=', $d))
            ->when($to, fn ($q, $d) => $q->whereDate('date', '<=', $d))
            ->groupBy('material_id')
            ->selectRaw('material_id, sum(quantity) as qty, sum(quantity * coalesce(price, 0)) as total')
            ->get()->keyBy('material_id');

        // Списание = материальные расходы со склада (qty), только confirmed.
        // Детали (какая сделка/заказ) — для клика по колонке «Списание».
        $writeoffExpenses = \App\Models\Expense::whereIn('material_id', $ids)
            ->where('status', 'confirmed')
            ->when($from, fn ($q, $d) => $q->whereDate('date', '>=', $d))
            ->when($to, fn ($q, $d) => $q->whereDate('date', '<=', $d))
            ->with('expenseable')
            ->latest('date')->get();
        $writtenOff = $writeoffExpenses->groupBy('material_id')->map(fn ($g) => (float) $g->sum('qty'));
        $writeoffs = $writeoffExpenses->map(fn ($e) => [
            'material_id' => $e->material_id,
            'qty' => (float) ($e->qty ?? 0),
            'amount' => (float) $e->amount,
            'date' => optional($e->date)->toDateString(),
            'created_at' => optional($e->created_at)->toIso8601String(),
            'type' => $e->expenseable_type, // deal | project
            // Сделка/заказ удалены (морф вернул null) — ссылку не даём (иначе 404).
            'target_id' => $e->expenseable ? $e->expenseable_id : null,
            'number' => $e->expenseable?->number,
            'label' => $e->expenseable
                ? ($e->expenseable_type === 'deal'
                    ? ($e->expenseable->company_name ?: $e->expenseable->number)
                    : ($e->expenseable->name ?: $e->expenseable->number))
                : ($e->expenseable_type === 'deal' ? 'сделка удалена' : 'заказ удалён'),
        ])->groupBy('material_id');

        $materials->each(function ($m) use ($received, $writtenOff) {
            $m->received_qty = (float) ($received[$m->id]->qty ?? 0);
            // У легаси-приходов цена могла быть не указана — тогда сумма по последней закупочной.
            $sum = (float) ($received[$m->id]->total ?? 0);
            $m->received_sum = $sum > 0 ? $sum : round($m->received_qty * (float) ($m->price ?? 0), 2);
            $m->written_off_qty = (float) ($writtenOff[$m->id] ?? 0);
        });

        $receipts = MaterialReceipt::whereIn('material_id', $ids)
            ->with(['material:id,name,unit', 'user:id,name'])
            ->latest()->limit(30)->get();

        return Inertia::render('Warehouse/Index', [
            'materials' => $materials,
            'writeoffs' => $writeoffs,
            'receipts' => $receipts,
            'units' => Deal::UNITS,
            'canManage' => $this->canManage($request),
            'allMode' => $allMode,
            'companyName' => $allMode ? 'Все компании' : (CurrentCompany::get()?->name ?? ''),
            'filters' => ['from' => $from, 'to' => $to],
        ]);
    }

    /** Приход товара: существующий материал или новая позиция. */
    public function receipt(Request $request): RedirectResponse
    {
        abort_unless($this->canManage($request), 403, 'Приход оформляет бухгалтер или админ.');

        $data = $request->validate([
            'material_id' => ['nullable', 'exists:materials,id'],
            'name' => ['required_without:material_id', 'nullable', 'string', 'max:255'],
            'unit' => ['nullable', Rule::in(Deal::UNITS)],
            'quantity' => ['required', 'numeric', 'min:0.01'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'date' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $companyId = CurrentCompany::id() ?: null;
        if (! $companyId && empty($data['material_id'])) {
            return back()->with('error', 'Переключитесь на конкретную компанию (BAIA или ASU), чтобы оформить приход.');
        }

        DB::transaction(function () use ($data, $request, $companyId) {
            $material = ! empty($data['material_id'])
                ? Material::findOrFail($data['material_id'])
                : Material::firstOrCreate(
                    ['company_id' => $companyId, 'name' => trim($data['name'])],
                    ['unit' => $data['unit'] ?? 'штук', 'quantity' => 0]
                );

            // Изоляция фирм: приход только на склад своей компании.
            abort_unless($request->user()->worksInCompany($material->company_id ? (int) $material->company_id : null), 403);

            $material->receipts()->create([
                'user_id' => $request->user()->id,
                'quantity' => $data['quantity'],
                'price' => $data['price'] ?? null,
                'date' => $data['date'] ?? now()->toDateString(),
                'note' => $data['note'] ?? null,
            ]);
            $material->increment('quantity', $data['quantity']);
            // На материале храним последнюю закупочную цену — по ней считается
            // расход по материалам в сделке (количество × цена).
            if (isset($data['price'])) {
                $material->update(['price' => $data['price']]);
            }
        });

        return back()->with('success', 'Приход оформлен — остаток обновлён.');
    }

    /**
     * Правка прихода: разница количества корректирует остаток материала
     * (в минус остаток уйти не может).
     */
    public function updateReceipt(Request $request, MaterialReceipt $receipt): RedirectResponse
    {
        abort_unless($this->canManage($request), 403, 'Приходы редактирует бухгалтер или админ.');
        abort_unless($request->user()->worksInCompany($receipt->material?->company_id ? (int) $receipt->material->company_id : null), 403);

        $data = $request->validate([
            'quantity' => ['required', 'numeric', 'min:0.01'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'date' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $delta = (float) $data['quantity'] - (float) $receipt->quantity;

        DB::transaction(function () use ($receipt, $data, $delta) {
            // Блокируем материал и проверяем уход в минус под блокировкой —
            // защита от гонки с параллельным списанием расхода по материалу.
            $material = Material::whereKey($receipt->material_id)->lockForUpdate()->first();
            if ((float) $material->quantity + $delta < 0) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'quantity' => 'Так остаток уйдёт в минус: на складе '.number_format((float) $material->quantity, 2, '.', ' ').' '.$material->unit.' (часть уже списана в расходы).',
                ]);
            }
            $receipt->update([
                'quantity' => $data['quantity'],
                'price' => array_key_exists('price', $data) ? $data['price'] : $receipt->price,
                'date' => $data['date'] ?? $receipt->date,
                'note' => $data['note'] ?? null,
            ]);
            if ($delta > 0) {
                $material->increment('quantity', $delta);
            } elseif ($delta < 0) {
                $material->decrement('quantity', abs($delta));
            }
            $this->syncLastPurchasePrice($material);
        });

        return back()->with('success', 'Приход обновлён — остаток пересчитан.');
    }

    /** Удаление прихода: количество снимается с остатка (в минус нельзя). */
    public function destroyReceipt(Request $request, MaterialReceipt $receipt): RedirectResponse
    {
        abort_unless($this->canManage($request), 403, 'Приходы удаляет бухгалтер или админ.');
        abort_unless($request->user()->worksInCompany($receipt->material?->company_id ? (int) $receipt->material->company_id : null), 403);

        if ((float) $receipt->material->quantity - (float) $receipt->quantity < 0) {
            return back()->with('error', 'Нельзя удалить приход: остаток уйдёт в минус (часть уже списана в расходы).');
        }

        try {
            DB::transaction(function () use ($receipt) {
                // Блокировка + перепроверка под ней: гонка с параллельным списанием.
                $material = Material::whereKey($receipt->material_id)->lockForUpdate()->first();
                if ((float) $material->quantity - (float) $receipt->quantity < 0) {
                    throw new \RuntimeException('negative');
                }
                $material->decrement('quantity', $receipt->quantity);
                $receipt->delete();
                $this->syncLastPurchasePrice($material);
            });
        } catch (\RuntimeException $e) {
            return back()->with('error', 'Нельзя удалить приход: остаток уйдёт в минус (часть уже списана в расходы).');
        }

        return back()->with('success', 'Приход удалён — остаток пересчитан.');
    }

    /**
     * Цена на материале = последняя закупочная (самый свежий приход с ценой).
     * Если приходов с ценой не осталось (удалили/очистили) — цена сбрасывается
     * в 0, иначе расходы продолжали бы считаться по «фантомной» цене.
     */
    private function syncLastPurchasePrice(Material $material): void
    {
        $last = $material->receipts()->whereNotNull('price')
            ->orderByDesc('date')->orderByDesc('id')->first();
        $material->update(['price' => $last?->price ?? 0]);
    }

    public function destroyMaterial(Request $request, Material $material): RedirectResponse
    {
        abort_unless($this->canManage($request), 403);
        abort_unless($request->user()->worksInCompany($material->company_id ? (int) $material->company_id : null), 403);

        $material->delete();

        return back()->with('success', 'Позиция склада удалена.');
    }
}
