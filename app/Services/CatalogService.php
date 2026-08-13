<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Чтение каталога для витрины: фильтры, поиск, сортировка, похожие товары.
 * Источник данных — те же products/product_categories, что ведёт ERP.
 */
class CatalogService
{
    public const SORTS = ['popular', 'price_asc', 'price_desc', 'name'];

    /**
     * Активные категории. В кэш кладём массивы, а не модели: сериализованные
     * Eloquent-объекты ломаются при любом изменении схемы.
     */
    public function categories(): Collection
    {
        $rows = Cache::remember('catalog.categories', 3600, fn () => ProductCategory::active()
            ->withCount(['products' => fn ($q) => $q->active()])
            ->orderBy('order')->orderBy('name')
            ->get(['id', 'name', 'slug', 'tagline', 'description', 'image', 'accent'])
            ->toArray());

        return collect($rows);
    }

    /**
     * Отфильтрованный список товаров.
     *
     * @param  array{category?:string|null,search?:string|null,sort?:string|null,min?:float|null,max?:float|null,colors?:array|null}  $filters
     */
    public function products(array $filters = [], int $perPage = 12): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $query = Product::active()->with('category:id,name,slug,accent');

        if ($slug = $filters['category'] ?? null) {
            $query->whereHas('category', fn ($q) => $q->where('slug', $slug));
        }

        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $query->where(fn ($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('code', 'like', "%{$search}%")
                ->orWhere('short_description', 'like', "%{$search}%"));
        }

        if (($min = $filters['min'] ?? null) !== null && $min !== '') {
            $query->where('price', '>=', (float) $min);
        }
        if (($max = $filters['max'] ?? null) !== null && $max !== '') {
            $query->where('price', '<=', (float) $max);
        }

        match ($filters['sort'] ?? 'popular') {
            'price_asc' => $query->orderBy('price'),
            'price_desc' => $query->orderByDesc('price'),
            'name' => $query->orderBy('name'),
            default => $query->orderByDesc('is_featured')->orderBy('order')->orderBy('id'),
        };

        return $query->paginate($perPage)->withQueryString();
    }

    /** Витрина главной: по одному-двум хитам из каждой категории. */
    public function featured(int $limit = 8): Collection
    {
        return Product::active()->where('is_featured', true)
            ->with('category:id,name,slug,accent')
            ->orderBy('order')->limit($limit)->get();
    }

    /** Похожие: та же категория, кроме самого товара; добираем по цене. */
    public function related(Product $product, int $limit = 4): Collection
    {
        return Product::active()
            ->where('id', '!=', $product->id)
            ->when($product->category_id, fn ($q, $id) => $q->where('category_id', $id))
            ->with('category:id,name,slug,accent')
            ->orderByDesc('is_featured')->orderBy('order')
            ->limit($limit)->get();
    }

    /** Товары по id — для «недавно просмотренных» и сравнения (localStorage). */
    public function byIds(array $ids, int $limit = 12): Collection
    {
        $ids = array_slice(array_filter(array_map('intval', $ids)), 0, $limit);
        if (! $ids) {
            return collect();
        }

        return Product::active()->whereIn('id', $ids)
            ->with('category:id,name,slug,accent')->get()
            ->sortBy(fn ($p) => array_search($p->id, $ids, true))->values();
    }

    /** Минимум/максимум цены — границы ползунка фильтра. */
    public function priceBounds(): array
    {
        return Cache::remember('catalog.price_bounds', 3600, function () {
            $row = Product::active()->selectRaw('min(price) as min_price, max(price) as max_price')->first();

            return [
                'min' => (float) ($row->min_price ?? 0),
                'max' => (float) ($row->max_price ?? 0),
            ];
        });
    }

    /** Данные конфигуратора: коллекции плитки с шт/м², палитрой и ценой. */
    /**
     * Слайды витрины первого экрана — по одному на категорию.
     *
     * Показываем только категории с загруженным снимком: без вырезанного PNG
     * слайд выглядел бы дырой. Цена берётся минимальная по разделу — это
     * честное «от», а не цена случайной позиции.
     *
     * @return array<int, array<string, mixed>>
     */
    public function heroSlides(): array
    {
        return ProductCategory::query()
            ->where('is_active', true)
            ->whereNotNull('image')
            ->withCount(['products' => fn ($q) => $q->where('is_active', true)])
            ->orderBy('order')->orderBy('name')
            ->get()
            ->map(function (ProductCategory $category) {
                $cheapest = Product::active()
                    ->where('category_id', $category->id)
                    ->orderBy('price')
                    ->first();

                return [
                    'id' => $category->slug,
                    'name' => $category->name,
                    'category' => mb_strtoupper($category->name),
                    'title' => $category->tagline ?: $category->name,
                    'price' => $cheapest ? (float) $cheapest->price : null,
                    'unit' => $cheapest?->unit,
                    'minOrder' => $cheapest ? (float) ($cheapest->min_order ?: 1) : 1,
                    'lead' => (string) ($category->description ?: $category->tagline),
                    'href' => route('site.catalog', ['category' => $category->slug]),
                    'count' => $category->products_count,
                    'buyId' => $cheapest?->slug,
                    'image' => [
                        'path' => $category->image,
                        'thumb' => $category->thumb ?: $category->image,
                        'alt' => $category->name.' — изделия из мраморного композита',
                    ],
                    'specs' => $this->heroSpecs($cheapest),
                    'thumbSpec' => $category->products_count.' позиций',
                ];
            })
            ->all();
    }

    /**
     * Выноски вокруг предмета. Позиция задаётся порядком: ERP хранит
     * характеристики без координат, а на макете их ровно четыре места.
     *
     * @return array<int, array{label: string, value: string, pos: string}>
     */
    private function heroSpecs(?Product $p): array
    {
        if (! $p) {
            return [];
        }

        $specs = $p->specs ?: [];
        $candidates = [
            ['label' => 'Морозостойкость', 'value' => $specs['frost'] ?? null],
            ['label' => 'Толщина', 'value' => isset($specs['thickness_mm']) ? $specs['thickness_mm'].' мм' : null],
            ['label' => 'Штук в м²', 'value' => isset($specs['pieces_per_m2']) ? str_replace('.', ',', (string) $specs['pieces_per_m2']) : null],
            ['label' => 'Прочность', 'value' => $specs['strength'] ?? null],
            ['label' => 'Размер', 'value' => $specs['size'] ?? null],
            ['label' => 'Цвет', 'value' => count($p->colors ?: []) ? 'сквозной, '.count($p->colors).' оттенков' : null],
        ];

        $positions = ['top-right', 'left', 'right', 'bottom'];

        return collect($candidates)
            ->filter(fn (array $c) => filled($c['value']))
            ->take(count($positions))
            ->values()
            ->map(fn (array $c, int $i) => [
                'label' => $c['label'],
                'value' => (string) $c['value'],
                'pos' => $positions[$i],
            ])
            ->all();
    }

    public function pavingCollections(): Collection
    {
        return Product::active()
            ->whereHas('category', fn ($q) => $q->where('slug', 'trotuarnaya-plitka'))
            ->orderBy('order')->get()
            ->map(fn (Product $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'slug' => $p->slug,
                'unit' => $p->unit,
                'price' => (float) $p->price,
                'size' => $p->specs['size'] ?? null,
                'pieces_per_m2' => $p->piecesPerM2(),
                'colors' => $p->colors ?: [],
                'texture' => $p->texture(),
                'image' => $p->images[0]['thumb'] ?? $p->images[0]['path'] ?? null,
                // Все снимки коллекции: из них собирается слой глубины на
                // главной. Пусто, пока фото не загружены в ERP.
                'images' => collect($p->images ?: [])
                    ->map(fn (array $img) => [
                        'path' => $img['path'] ?? null,
                        'thumb' => $img['thumb'] ?? $img['path'] ?? null,
                        'alt' => $img['alt'] ?? $p->name,
                    ])
                    ->filter(fn (array $img) => (bool) $img['path'])
                    ->values()
                    ->all(),
            ])->values();
    }

    /**
     * Материалы 3D-сцены главной из каталога ERP.
     *
     * Текстуры берём ТОЛЬКО у плитки и бордюра: это плоские повторяющиеся
     * поверхности, фото ложится на них естественно. Натянуть снимок урны или
     * вазона на цилиндр нельзя — изображение размажется, поэтому для малых
     * форм используются GLB-модели, а без модели — цвет из палитры карточки.
     *
     * @return array{textures: array<string, string>, models: array<string, string>, colors: array<string, string>}
     */
    public function sceneAssets(): array
    {
        $group = fn (string $categorySlug) => Product::active()
            ->whereHas('category', fn ($q) => $q->where('slug', $categorySlug))
            ->orderByDesc('is_featured')->orderBy('order')
            ->get(['id', 'texture_path', 'model_path', 'colors', 'images']);

        $groups = [
            'paving' => $group('trotuarnaya-plitka'),
            'curb' => $group('bordyury'),
            'bench' => $group('skami'),
            'vase' => $group('vazony'),
            'urn' => $group('urny'),
        ];
        $items = array_map(fn ($rows) => $rows->first(), $groups);

        // Все загруженные модели категории: если их несколько (например два
        // типа вазона), сцена расставит разные, а не продублирует одну.
        $models = array_filter(array_map(
            fn ($rows) => $rows->pluck('model_path')->filter()->values()->all(),
            array_intersect_key($groups, array_flip(['bench', 'vase', 'urn'])),
        ));

        return [
            // Плоские поверхности — фото работает как материал.
            'textures' => array_filter([
                'paving' => $items['paving']?->texture(),
                'curb' => $items['curb']?->texture(),
            ]),
            // Объёмные изделия — настоящая геометрия со своими материалами.
            'models' => $models,
            // Запасной вариант без модели: изделие красится своим цветом
            // из карточки, а не цветом плитки.
            'colors' => array_filter(array_map(
                fn (?Product $p) => $p?->colors ? $p->sceneColor() : null,
                $items,
            )),
        ];
    }

    public static function flushCache(): void
    {
        Cache::forget('catalog.categories');
        Cache::forget('catalog.price_bounds');
    }
}
