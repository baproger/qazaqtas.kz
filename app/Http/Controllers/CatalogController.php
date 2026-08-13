<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Services\CatalogService;
use App\Services\MediaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ERP → Каталог: единственное место, где заводится продукция. Витрина сайта
 * читает эти же таблицы, поэтому «второй базы товаров» не существует.
 *
 * Права — существующий модуль product.* из RolePermissionSeeder.
 */
class CatalogController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Product::class);

        $products = Product::with('category:id,name,slug')
            ->when($request->string('search')->toString(), fn ($q, $s) => $q
                ->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%")))
            ->when($request->integer('category'), fn ($q, $c) => $q->where('category_id', $c))
            ->orderBy('category_id')->orderBy('order')->orderBy('name')
            ->paginate(30)->withQueryString();

        return Inertia::render('Catalog/Index', [
            'products' => $products,
            'categories' => ProductCategory::orderBy('order')->orderBy('name')
                ->withCount('products')->get(),
            'filters' => $request->only('search', 'category'),
            'units' => \App\Models\Deal::UNITS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Product::class);
        Product::create($this->validated($request));
        CatalogService::flushCache();

        return back()->with('success', 'Позиция добавлена в каталог.');
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        $this->authorize('update', $product);
        $product->update($this->validated($request, $product));
        CatalogService::flushCache();

        return back()->with('success', 'Позиция обновлена.');
    }

    public function destroy(Product $product): RedirectResponse
    {
        $this->authorize('delete', $product);
        $product->delete();
        CatalogService::flushCache();

        return back()->with('success', 'Позиция удалена из каталога.');
    }

    // ---- Категории ----

    public function storeCategory(Request $request): RedirectResponse
    {
        $this->authorize('create', Product::class);
        ProductCategory::create($this->validatedCategory($request));
        CatalogService::flushCache();

        return back()->with('success', 'Категория добавлена.');
    }

    public function updateCategory(Request $request, ProductCategory $category): RedirectResponse
    {
        $this->authorize('create', Product::class);
        $category->update($this->validatedCategory($request, $category));
        CatalogService::flushCache();

        return back()->with('success', 'Категория обновлена.');
    }

    public function destroyCategory(ProductCategory $category): RedirectResponse
    {
        $this->authorize('delete', Product::class);
        if ($category->products()->exists()) {
            return back()->with('error', 'В категории есть товары — сначала перенесите или удалите их.');
        }
        $category->delete();
        CatalogService::flushCache();

        return back()->with('success', 'Категория удалена.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Product $product = null): array
    {
        $data = $request->validate([
            'category_id' => ['nullable', 'exists:product_categories,id'],
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', Rule::unique('products', 'slug')->ignore($product?->id)],
            'code' => ['nullable', 'string', 'max:100'],
            'unit' => ['required', 'string', 'max:50'],
            'price' => ['required', 'numeric', 'min:0'],
            'old_price' => ['nullable', 'numeric', 'min:0'],
            'min_order' => ['nullable', 'numeric', 'min:0'],
            'short_description' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'specs' => ['nullable', 'array'],
            'colors' => ['nullable', 'array'],
            'colors.*.name' => ['required_with:colors', 'string', 'max:60'],
            'colors.*.hex' => ['required_with:colors', 'string', 'max:9'],
            'images' => ['nullable', 'array'],
            'documents' => ['nullable', 'array'],
            'is_active' => ['boolean'],
            'is_featured' => ['boolean'],
            'in_stock' => ['boolean'],
            'order' => ['nullable', 'integer', 'min:0'],
        ]);

        $data['order'] ??= 0;
        $data['min_order'] ??= 0;

        return $data;
    }

    /** Отдельная страница: категории со снимками и порядком. */
    public function categories(): Response
    {
        $this->authorize('viewAny', Product::class);

        return Inertia::render('Catalog/Categories', [
            'categories' => ProductCategory::withCount('products')
                ->orderBy('order')->orderBy('name')->get()
                ->map(fn (ProductCategory $c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'slug' => $c->slug,
                    'tagline' => $c->tagline,
                    'description' => $c->description,
                    'accent' => $c->accent,
                    'order' => $c->order,
                    'is_active' => $c->is_active,
                    'image' => $c->image,
                    'thumb' => $c->thumb,
                    'specs' => $c->specs ?: [],
                    'products_count' => $c->products_count,
                ]),
        ]);
    }

    /** Снимок категории: вырезанный предмет на прозрачном фоне. */
    public function storeCategoryImage(Request $request, ProductCategory $category, MediaService $media): RedirectResponse
    {
        $this->authorize('create', Product::class);

        $request->validate([
            'image' => ['required', 'image', 'mimes:png,webp', 'max:8192'],
        ], [
            'image.mimes' => 'Нужен PNG или WebP с прозрачным фоном: JPG не умеет альфа-канал.',
        ]);

        $media->delete($category->image, $category->thumb);
        $stored = $media->storeImage($request->file('image'), 'categories/'.$category->id, $category->name);

        $category->update(['image' => $stored['path'], 'thumb' => $stored['thumb']]);
        CatalogService::flushCache();

        return back()->with('success', 'Снимок категории обновлён.');
    }

    public function destroyCategoryImage(ProductCategory $category, MediaService $media): RedirectResponse
    {
        $this->authorize('create', Product::class);

        $media->delete($category->image, $category->thumb);
        $category->update(['image' => null, 'thumb' => null]);
        CatalogService::flushCache();

        return back()->with('success', 'Снимок категории удалён.');
    }

    /** @return array<string, mixed> */
    private function validatedCategory(Request $request, ?ProductCategory $category = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', Rule::unique('product_categories', 'slug')->ignore($category?->id)],
            'tagline' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'accent' => ['nullable', 'string', 'max:20'],
            // До четырёх подписей к снимку категории: больше мест на макете нет.
            'specs' => ['nullable', 'array', 'max:4'],
            'specs.*.label' => ['nullable', 'string', 'max:40'],
            'specs.*.value' => ['nullable', 'string', 'max:60'],
            'order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['boolean'],
        ]);
    }
}
