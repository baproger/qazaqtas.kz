<?php

namespace App\Services;

use App\Models\Deal;
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
    public function normalize(array $rows): array
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
            $this->reconcile($deal, $items);

            // Всегда, включая пустой список: «удалить все позиции» обнуляет
            // сумму. Иначе бюджет оставался бы тем, что прислал браузер, —
            // а от него считается бонус менеджера.
            $deal->forceFill(['budget' => $this->total($items)])->save();
        });
    }

    /**
     * Обновить позиции сделки НА МЕСТЕ, а не переписать заново.
     *
     * Раньше здесь стояло delete + createMany, и каждое сохранение сделки
     * выдавало строкам новые id. К позиции привязаны фото — «вот эта плитка
     * выглядит так», — и правка количества молча осиротила бы все снимки.
     * Позиция обязана пережить правку сделки.
     *
     * Сопоставляем по товару каталога, а строки без него — по названию:
     * это то, чем позиция отличается от соседней. Совпадений может быть
     * несколько (две строки одного товара), поэтому подбираем из пула по
     * одной: первой строке — первый кандидат.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    private function reconcile(Deal $deal, array $items): void
    {
        $existing = $deal->items()->get();

        $keep = [];
        foreach ($items as $item) {
            $key = $item['product_id'] ?? null;
            $match = $existing->first(fn ($row) => ! in_array($row->id, $keep, true)
                && ($key !== null
                    ? (int) $row->product_id === (int) $key
                    : $row->product_id === null && $row->name === $item['name']));

            if ($match) {
                $match->update($item);
                $keep[] = $match->id;

                continue;
            }

            $keep[] = $deal->items()->create($item)->id;
        }

        // Позиции, которых в заказе больше нет. По одной, а не массово:
        // массовое удаление не поднимает событий модели, а на них висит
        // уборка фото этой позиции (DealItem::booted).
        foreach ($deal->items()->whereNotIn('id', $keep ?: [0])->get() as $stale) {
            $stale->delete();
        }
    }
}
