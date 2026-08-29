<?php

namespace App\Http\Controllers;

use App\Models\Deal;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Material;
use App\Models\MaterialReceipt;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Setting;
use App\Models\StockMovement;
use App\Support\CurrentCompany;
use App\Support\FinanceAudit;
use App\Support\StickyFilters;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Склад: у каждой фирмы свой. Приход оформляет бухгалтер /
 * директор / админ; менеджеры видят остатки (для расходов по материалам).
 */
class WarehouseController extends Controller
{
    /** Сколько последних списаний показывать в раскрытии по позиции. */
    private const WRITEOFF_DETAILS = 300;

    /** Управление складом (приход, правка, удаление) — только бухгалтер и админ. */
    private function canManage(Request $request): bool
    {
        return $request->user()->hasAnyRole(['admin', 'financist']);
    }

    public function index(Request $request): Response
    {
        // Фильтр переживает уход со страницы: пришли без параметров —
        // подставляем сохранённый набор (App\Support\StickyFilters).
        StickyFilters::apply($request, 'warehouse', ['from', 'to']);

        abort_unless($request->user()->hasAnyRole(['admin', 'director', 'financist', 'manager']) && $request->user()->can('expense.viewAny'), 403);

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
        $writeoffQuery = fn () => Expense::whereIn('material_id', $ids)
            ->where('status', 'confirmed')
            ->when($from, fn ($q, $d) => $q->whereDate('date', '>=', $d))
            ->when($to, fn ($q, $d) => $q->whereDate('date', '<=', $d));

        // Сколько списано — считает БД. Раньше ради этих сумм в память
        // поднималась вся история списаний со всеми сделками в придачу.
        $writtenOff = $writeoffQuery()
            ->groupBy('material_id')->selectRaw('material_id, SUM(qty) as qty')
            ->pluck('qty', 'material_id')->map(fn ($q) => (float) $q);

        // Детали (какая сделка/заказ) нужны для клика по колонке «Списание» —
        // берём последние: раскрытый список за все годы никто не читает, а
        // грузить его приходилось бы при каждом открытии страницы.
        $writeoffExpenses = $writeoffQuery()->with('expenseable')
            ->latest('date')->limit(self::WRITEOFF_DETAILS)->get();
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
            // Цена продажи считается сервером: наценка позиции или общая.
            $m->markup_effective = $m->markup();
            $m->sale_price = $m->salePrice();
        });

        $receipts = MaterialReceipt::whereIn('material_id', $ids)
            ->with(['material:id,name,unit', 'user:id,name'])
            ->latest()->limit(30)->get();

        return Inertia::render('Warehouse/Index', [
            'products' => $this->finishedGoods(),
            'materials' => $materials,
            'writeoffs' => $writeoffs,
            'receipts' => $receipts,
            'units' => Deal::UNITS,
            'canManage' => $this->canManage($request),
            // Общая наценка из настроек — подсказка в форме («как у всех»).
            'defaultMarkup' => (float) Setting::get('material_markup_percent', 0),
            'allMode' => $allMode,
            'companyName' => $allMode ? 'Все компании' : (CurrentCompany::get()?->name ?? ''),
            'filters' => ['from' => $from, 'to' => $to],
        ]);
    }

    /**
     * Склад ГОТОВОЙ ПРОДУКЦИИ: что произвели и что осталось.
     *
     * Отдельно от сырья: крошку и цемент закупают, а вазоны делает цех.
     * Остаток берётся из движений (`stock_movements`) — числом его никто не
     * правит, поэтому по каждой строке видно, откуда она взялась.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function finishedGoods()
    {
        $companyId = CurrentCompany::id() ?: null;

        $stocks = ProductStock::query()
            ->where('company_id', $companyId)
            ->with('product:id,name,unit,min_stock')
            ->get()
            ->filter(fn ($row) => $row->product !== null);

        // Движения этого месяца — «произведено» и «ушло в сделки» колонками.
        $since = now()->startOfMonth();
        $moves = StockMovement::query()
            ->where('company_id', $companyId)
            ->whereIn('product_id', $stocks->pluck('product_id'))
            ->where('created_at', '>=', $since)
            ->groupBy('product_id', 'type')
            ->selectRaw('product_id, type, sum(qty) as total')
            ->get();

        return $stocks->map(function ($row) use ($moves) {
            $mine = $moves->where('product_id', $row->product_id);
            $min = $row->product->min_stock !== null ? (float) $row->product->min_stock : null;
            $qty = round((float) $row->qty, 2);

            return [
                'id' => $row->product_id,
                'name' => $row->product->name,
                'unit' => $row->product->unit,
                'qty' => $qty,
                'min_stock' => $min,
                // Серый — пусто, жёлтый — ниже минимума, зелёный — есть.
                'level' => $qty <= 0 ? 'empty' : ($min !== null && $qty <= $min ? 'low' : 'ok'),
                'produced' => round((float) $mine->where('type', StockMovement::PRODUCTION_IN)->sum('total'), 2),
                'shipped' => round(abs((float) $mine->where('type', StockMovement::DEAL_OUT)->sum('total')), 2),
            ];
        })->sortBy('name')->values();
    }

    /**
     * Лента движений одного товара: откуда взялся и куда ушёл каждый метр.
     *
     * Ради этой ленты остаток и хранится движениями: «почему 800, а не 1000»
     * должно отвечаться построчно, а не догадками.
     */
    public function productMovements(Request $request, Product $product): JsonResponse
    {
        abort_unless($request->user()->hasAnyRole(['admin', 'director', 'financist', 'manager']) && $request->user()->can('expense.viewAny'), 403);

        $rows = StockMovement::query()
            ->where('product_id', $product->id)
            ->where('company_id', CurrentCompany::id() ?: null)
            ->with('author:id,name')
            ->latest('id')->limit(100)->get();

        return response()->json($rows->map(fn ($m) => [
            'id' => $m->id,
            'date' => $m->created_at?->format('d.m.Y H:i'),
            'qty' => round((float) $m->qty, 2),
            'type' => $m->type,
            'label' => $m->label(),
            'note' => $m->note,
            'author' => $m->author?->name,
        ]));
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
            // Наценка позиции: по ней считается цена продажи и бонус менеджера
            // за проданный товар. Пусто — общая наценка из настроек.
            'markup_pct' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'date' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
            // Откуда заплатили поставщику: нал / банк / «не списывать»
            // (товар пришёл, деньги ушли раньше или ещё не ушли).
            'payment' => ['nullable', Rule::in(['cash', 'bank', 'none'])],
        ]);

        $companyId = CurrentCompany::id() ?: null;
        if (! $companyId && empty($data['material_id'])) {
            return back()->with('error', 'Переключитесь на конкретную компанию, чтобы оформить приход.');
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

            $receipt = $material->receipts()->create([
                'user_id' => $request->user()->id,
                'quantity' => $data['quantity'],
                'price' => $data['price'] ?? null,
                'date' => $data['date'] ?? now()->toDateString(),
                'note' => $data['note'] ?? null,
            ]);
            $material->increment('quantity', $data['quantity']);

            // Оплата закупа — момент, когда деньги реально уходят. Расход
            // подтверждённый: бухгалтер сам его и оформляет приходом.
            if (($data['payment'] ?? 'none') !== 'none') {
                $expense = $this->purchaseExpense($receipt, $material, $request->user()->id, $data['payment']);
                $receipt->update(['expense_id' => $expense->id]);
            }
            // На материале храним последнюю закупочную цену — по ней считается
            // расход по материалам в сделке (количество × цена).
            if (isset($data['price'])) {
                $material->update(['price' => $data['price']]);
            }
            if (array_key_exists('markup_pct', $data) && $data['markup_pct'] !== null) {
                $material->update(['markup_pct' => $data['markup_pct']]);
            }
        });

        return back()->with('success', 'Приход оформлен — остаток обновлён.');
    }

    /**
     * Расход-оплата закупа: сумма = количество × закупочная цена, дата — дата
     * прихода. Категория служебная (`materials_purchase`) и ищется по коду:
     * имя владелец правит из админки.
     */
    private function purchaseExpense(MaterialReceipt $receipt, Material $material, int $userId, string $method): Expense
    {
        return Expense::create([
            'company_id' => $material->company_id,
            'category_id' => ExpenseCategory::firstOrCreate(
                ['code' => ExpenseCategory::MATERIALS_PURCHASE],
                ['name' => 'Закуп материалов', 'is_active' => true]
            )->id,
            'type' => 'purchase',
            'amount' => round((float) $receipt->quantity * (float) ($receipt->price ?? 0), 2),
            'date' => $receipt->date,
            'description' => 'Закуп: '.$material->name.' × '
                .rtrim(rtrim(number_format((float) $receipt->quantity, 2, '.', ''), '0'), '.').' '.$material->unit,
            'responsible_user_id' => $userId,
            'status' => 'confirmed',
            'payment_method' => $method,
            'confirmed_by' => $userId,
            'confirmed_at' => now(),
        ]);
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
                throw ValidationException::withMessages([
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
            // Оплаченный закуп поправился — сумма расхода едет следом, иначе
            // касса перестанет сходиться с накладной.
            $receipt->expense?->update([
                'amount' => round((float) $receipt->quantity * (float) ($receipt->price ?? 0), 2),
                'date' => $receipt->date,
            ]);
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
                // Приход отменён — оплата закупа вместе с ним: деньги вернулись.
                // Возврат денег в кассу — событие для СЕО и директора.
                if ($receipt->expense) {
                    $paid = (float) $receipt->expense->amount;
                    $receipt->expense->delete();
                    FinanceAudit::notifyDeleted(
                        'Оплата закупа на '.number_format($paid, 0, '.', ' ').' ₸ ('.$material->name.')'
                    );
                }
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

    /**
     * Правка позиции склада: название, единица, закупочная цена и заметка.
     * Остаток здесь не трогаем — он считается приходами и списаниями, иначе
     * склад разойдётся с расходами сделок.
     */
    public function updateMaterial(Request $request, Material $material): RedirectResponse
    {
        abort_unless($this->canManage($request), 403);
        abort_unless($request->user()->worksInCompany($material->company_id ? (int) $material->company_id : null), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255',
                Rule::unique('materials', 'name')->where('company_id', $material->company_id)->ignore($material->id)],
            'unit' => ['nullable', Rule::in(Deal::UNITS)],
            'price' => ['nullable', 'numeric', 'min:0'],
            'markup_pct' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'note' => ['nullable', 'string', 'max:255'],
        ], [
            'name.unique' => 'Позиция с таким названием на складе уже есть.',
        ]);

        $material->update([
            'name' => trim($data['name']),
            'unit' => $data['unit'] ?? $material->unit,
            'price' => $data['price'] ?? $material->price,
            // Пустая наценка = «как у всех»: позиция возвращается к общей.
            'markup_pct' => $data['markup_pct'] ?? null,
            'note' => $data['note'] ?? null,
        ]);

        return back()->with('success', 'Позиция склада обновлена.');
    }

    public function destroyMaterial(Request $request, Material $material): RedirectResponse
    {
        abort_unless($this->canManage($request), 403);
        abort_unless($request->user()->worksInCompany($material->company_id ? (int) $material->company_id : null), 403);

        $material->delete();

        return back()->with('success', 'Позиция склада удалена.');
    }
}
