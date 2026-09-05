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
                    ? __('site.seo.category_title', ['name' => $current['name']])
                    : __('site.seo.catalog_title'),
                'description' => $current['description'] ?? __('site.seo.catalog_description'),
            ],
        ]);
    }

    public function show(Product $product): Response
    {
        abort_unless($product->is_active, 404);
        $product->load(['seoMeta', 'translations', 'category:id,name,slug,accent', 'category.translations']);

        $name = (string) $product->tr('name');

        return Inertia::render('Site/Product', [
            'product' => $product->localized(),
            'related' => $this->catalog->related($product),
            // Ручные метаданные карточки (вкладка SEO в ERP) сильнее
            // автогенерации; canonical — адрес этой страницы без параметров.
            'seo' => \App\Support\Seo::for(
                $product,
                __('site.seo.product_title', ['name' => $name]),
                $product->tr('short_description') ?: __('site.seo.product_description', ['name' => $name]),
                $product->images[0]['path'] ?? null,
                url()->current(),
            ),
        ]);
    }

    /** Недавно просмотренные: id приходят из localStorage витрины. */
    public function recent(Request $request)
    {
        $ids = array_filter(explode(',', (string) $request->query('ids')));

        return response()->json($this->catalog->byIds($ids, 8));
    }
}
