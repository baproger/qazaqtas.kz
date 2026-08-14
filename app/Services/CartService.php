<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Facades\Session;

/**
 * Корзина витрины: живёт в сессии, цены всегда берутся из ERP на лету —
 * в сессии хранятся только id, количество и выбранный цвет.
 */
class CartService
{
    private const KEY = 'cart';

    /** @return array<string, array{product_id:int, quantity:float, color:?string}> */
    public function raw(): array
    {
        return Session::get(self::KEY, []);
    }

    public function add(Product $product, float $quantity = 1, ?string $color = null): void
    {
        $items = $this->raw();
        $key = $this->key($product->id, $color);
        $current = (float) ($items[$key]['quantity'] ?? 0);

        $items[$key] = [
            'product_id' => $product->id,
            'quantity' => $this->normalize($product, $current + $quantity),
            'color' => $color,
        ];

        Session::put(self::KEY, $items);
    }

    public function update(string $key, float $quantity): void
    {
        $items = $this->raw();
        if (! isset($items[$key])) {
            return;
        }

        if ($quantity <= 0) {
            unset($items[$key]);
        } else {
            $product = Product::find($items[$key]['product_id']);
            $items[$key]['quantity'] = $product ? $this->normalize($product, $quantity) : $quantity;
        }

        Session::put(self::KEY, $items);
    }

    public function remove(string $key): void
    {
        $items = $this->raw();
        unset($items[$key]);
        Session::put(self::KEY, $items);
    }

    public function clear(): void
    {
        Session::forget(self::KEY);
    }

    /**
     * Позиции корзины с актуальными ценами и итогами.
     *
     * @return array{items: array<int, array<string, mixed>>, total: float, count: int}
     */
    public function contents(): array
    {
        $raw = $this->raw();
        if (! $raw) {
            return ['items' => [], 'total' => 0.0, 'count' => 0];
        }

        // Названия берём из карточки при каждом показе, а не из сессии:
        // корзина, собранная по-казахски, должна читаться по-русски сразу
        // после переключения языка.
        $products = Product::active()->whereIn('id', array_column($raw, 'product_id'))
            ->with(['translations', 'category:id,name,slug,accent', 'category.translations'])
            ->get()->keyBy('id');

        $items = [];
        $total = 0.0;

        foreach ($raw as $key => $row) {
            $product = $products->get($row['product_id']);
            if (! $product) {
                continue; // позицию убрали из каталога в ERP — молча пропускаем
            }

            $quantity = (float) $row['quantity'];
            $sum = round((float) $product->price * $quantity, 2);
            $total += $sum;

            $colors = $product->tr('colors') ?: [];

            $items[] = [
                'key' => $key,
                'product_id' => $product->id,
                'name' => $product->tr('name'),
                'slug' => $product->slug,
                'code' => $product->code,
                'unit' => $product->unit,
                'price' => (float) $product->price,
                'quantity' => $quantity,
                'sum' => $sum,
                'color' => $row['color'],
                // Снимок и палитра — чтобы корзина показывала товар так же,
                // как каталог: есть фото — фото, нет — векторная схема по типу
                // изделия и цвету. Позиция без картинки читается как ошибка.
                'image' => $product->images[0] ?? null,
                'colors' => $colors,
                // Оттенок выбранного цвета: в корзине хранится его НАЗВАНИЕ,
                // а схеме нужен код. Не нашли — превью возьмёт первый цвет
                // изделия, как и в каталоге.
                'color_hex' => collect($colors)->firstWhere('name', $row['color'])['hex'] ?? null,
                'category' => $product->category?->tr('name'),
                'category_slug' => $product->category?->slug,
                'accent' => $product->category?->accent,
                'min_order' => (float) $product->min_order,
            ];
        }

        return ['items' => $items, 'total' => round($total, 2), 'count' => count($items)];
    }

    /** Количество позиций — для бейджа в шапке сайта. */
    public function count(): int
    {
        return count($this->raw());
    }

    private function key(int $productId, ?string $color): string
    {
        return $productId.':'.($color ?: '-');
    }

    /** Не даём уйти ниже минимального заказа, заданного в ERP. */
    private function normalize(Product $product, float $quantity): float
    {
        $min = (float) $product->min_order;

        return round(max($quantity, $min > 0 ? $min : 0.01), 2);
    }
}
