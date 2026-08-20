<?php

namespace App\Services;

use App\Models\Deal;
use App\Models\PreDeal;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Позиции сделки и заявки: один товар — одна строка со своим количеством,
 * единицей и ценой.
 *
 * Правила здесь общие для заявки и сделки намеренно: заявка превращается в
 * сделку кнопкой «В работу ✓», и если бы суммы считались двумя разными
 * кусками кода, маржа заявки перестала бы сходиться с суммой сделки.
 *
 * Единица и название берутся ИЗ КАТАЛОГА (менеджер их не вводит: брусчатка —
 * м², урна — штук), а цена подставляется оттуда же и остаётся правимой —
 * скидку и опт дают в строке, не переоценивая товар в каталоге.
 */
class DealItemService
{
    /**
     * Привести пришедшие из формы строки к чистому виду.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    public function normalize(array $rows, bool $withPurchasePrice = false): array
    {
        $products = Product::whereIn('id', collect($rows)->pluck('product_id')->filter()->all())
            ->get(['id', 'name', 'unit', 'price'])->keyBy('id');

        $items = [];
        foreach (array_values($rows) as $sort => $row) {
            $product = isset($row['product_id']) ? $products->get((int) $row['product_id']) : null;

            // Название и единица — из каталога; вручную их не присылают, но
            // если товар удалили, остаётся то, что пришло в строке.
            $name = trim((string) ($product->name ?? $row['name'] ?? ''));
            if ($name === '') {
                continue;   // строка без товара и без названия — пустая, пропускаем
            }

            $quantity = round((float) ($row['quantity'] ?? 0), 2);
            $price = array_key_exists('price', $row) && $row['price'] !== null && $row['price'] !== ''
                ? round((float) $row['price'], 2)
                : round((float) ($product->price ?? 0), 2);

            $item = [
                'product_id' => $product?->id,
                'name' => $name,
                'unit' => $product->unit ?? ($row['unit'] ?? null),
                'quantity' => max($quantity, 0),
                'price' => max($price, 0),
                'amount' => round(max($quantity, 0) * max($price, 0), 2),
                'sort' => $sort,
            ];

            if ($withPurchasePrice) {
                $item['purchase_price'] = ($row['purchase_price'] ?? null) !== null && $row['purchase_price'] !== ''
                    ? round((float) $row['purchase_price'], 2)
                    : null;
            }

            $items[] = $item;
        }

        return $items;
    }

    /** Сумма продажи по строкам. */
    public function total(array $items): float
    {
        return round(array_sum(array_column($items, 'amount')), 2);
    }

    /** Закуп по строкам (количество × закупочная цена) — для маржи заявки. */
    public function purchaseTotal(array $items): float
    {
        return round(array_sum(array_map(
            fn ($i) => (float) ($i['purchase_price'] ?? 0) * (float) $i['quantity'],
            $items,
        )), 2);
    }

    /**
     * Переписать позиции сделки и пересчитать её сумму.
     *
     * Сумма сделки СЧИТАЕТСЯ по строкам, а не вводится: иначе счета, маржа и
     * бонус считались бы от числа, которого нет в составе заказа.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function syncDeal(Deal $deal, array $rows): void
    {
        $items = $this->normalize($rows);

        DB::transaction(function () use ($deal, $items) {
            $deal->items()->delete();
            $deal->items()->createMany($items);

            if ($items !== []) {
                $deal->forceFill(['budget' => $this->total($items)])->save();
            }
        });
    }

    /**
     * Переписать позиции заявки. Сумму и закуп заявки считает
     * PreDeal::calculate — здесь только строки.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function syncPreDeal(PreDeal $preDeal, array $rows): void
    {
        $items = $this->normalize($rows, withPurchasePrice: true);

        DB::transaction(function () use ($preDeal, $items) {
            $preDeal->items()->delete();
            $preDeal->items()->createMany($items);
        });
    }

    /**
     * Перенести позиции заявки в созданную сделку («В работу ✓»).
     *
     * Закупочная цена остаётся в заявке: в сделке себестоимость живёт
     * расходами, а не строкой заказа.
     */
    public function copyToDeal(PreDeal $preDeal, Deal $deal): void
    {
        $items = $preDeal->items->map(fn ($i) => [
            'product_id' => $i->product_id,
            'name' => $i->name,
            'unit' => $i->unit,
            'quantity' => (float) $i->quantity,
            'price' => (float) $i->price,
            'amount' => (float) $i->amount,
            'sort' => $i->sort,
        ])->all();

        if ($items === []) {
            return;
        }

        $deal->items()->createMany($items);
    }
}
