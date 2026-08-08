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
            ])->values();
    }

    /**
     * Материалы для 3D-сцены главной: фото-текстуры и GLB-модели изделий
     * из каталога. Пусто — сцена рисует процедурную геометрию цветом.
     *
     * @return array<string, string|null>
     */
    public function sceneAssets(): array
    {
        $pick = fn (string $categorySlug) => Product::active()
            ->whereHas('category', fn ($q) => $q->where('slug', $categorySlug))
            ->orderByDesc('is_featured')->orderBy('order')
            ->get(['id', 'texture_path', 'model_path', 'images'])
            ->first(fn (Product $p) => $p->texture() || $p->model_path);

        $paving = $pick('trotuarnaya-plitka');
        $curb = $pick('bordyury');
        $bench = $pick('skami');
        $vase = $pick('vazony');

        return [
            'textures' => array_filter([
                'paving' => $paving?->texture(),
                'curb' => $curb?->texture(),
            ]),
            'models' => array_filter([
                'bench' => $bench?->model_path,
                'vase' => $vase?->model_path,
            ]),
        ];
    }

    public static function flushCache(): void
    {
        Cache::forget('catalog.categories');
        Cache::forget('catalog.price_bounds');
    }
}
