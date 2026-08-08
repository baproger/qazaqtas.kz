<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\CatalogService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Каталог витрины: список с фильтрами и карточка товара. */
class CatalogController extends Controller
{
    public function __construct(private CatalogService $catalog) {}

    public function index(Request $request): Response
    {
        $filters = $request->only(['category', 'search', 'sort', 'min', 'max']);
        $category = $filters['category'] ?? null;
        $categories = $this->catalog->categories();
        $current = $category ? $categories->firstWhere('slug', $category) : null;

        return Inertia::render('Site/Catalog', [
            'categories' => $categories,
            'products' => $this->catalog->products($filters, 12),
            'filters' => $filters + ['sort' => $filters['sort'] ?? 'popular'],
            'bounds' => $this->catalog->priceBounds(),
            'currentCategory' => $current,
            'seo' => [
                'title' => $current
                    ? $current['name'].' — каталог QAZAQ TAS'
                    : 'Каталог изделий из мраморного композита — QAZAQ TAS',
                'description' => $current['description']
                    ?? 'Тротуарная плитка, бордюры, вазоны, скамьи, урны и облицовка из мраморного композита с ценами и характеристиками.',
            ],
        ]);
    }

    public function show(Product $product): Response
    {
        abort_unless($product->is_active, 404);
        $product->load('category:id,name,slug,accent');

        return Inertia::render('Site/Product', [
            'product' => $product,
            'related' => $this->catalog->related($product),
            'seo' => [
                'title' => $product->name.' — купить в QAZAQ TAS',
                'description' => $product->short_description
                    ?: 'Характеристики, цена и расчёт количества для изделия '.$product->name.' из мраморного композита.',
            ],
        ]);
    }

    /** Недавно просмотренные: id приходят из localStorage витрины. */
    public function recent(Request $request)
    {
        $ids = array_filter(explode(',', (string) $request->query('ids')));

        return response()->json($this->catalog->byIds($ids, 8));
    }
}
