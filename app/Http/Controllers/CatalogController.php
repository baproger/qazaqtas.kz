<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Services\CatalogService;
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

}
