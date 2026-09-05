<?php

namespace App\Http\Controllers;

use App\Models\Deal;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Services\CatalogService;
use App\Support\Locales;
use App\Support\StickyFilters;
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
        // Фильтр переживает уход со страницы: пришли без параметров —
        // подставляем сохранённый набор (App\Support\StickyFilters).
        StickyFilters::apply($request, 'catalog', ['search', 'category']);

        $this->authorize('viewAny', Product::class);

        $products = Product::with(['translations', 'category:id,name,slug'])
            ->when($request->string('search')->toString(), fn ($q, $s) => $q
                ->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%")))
            ->when($request->integer('category'), fn ($q, $c) => $q->where('category_id', $c))
            ->orderBy('category_id')->orderBy('order')->orderBy('name')
            ->paginate(30)->withQueryString();

        return Inertia::render('Catalog/Index', [
            // Форма правит базовые значения, поэтому карточка отдаётся как
            // есть, без подстановки перевода, а переводы едут отдельным полем.
            'products' => $products->through(fn (Product $p) => $p->toArray() + [
                'translations_map' => $p->translationsPayload(),
            ]),
            'categories' => ProductCategory::orderBy('order')->orderBy('name')
                ->withCount('products')->get(),
            'locales' => Locales::forForm(),
            'filters' => $request->only('search', 'category'),
            'units' => Deal::UNITS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Product::class);
        $product = Product::create($this->validated($request));
        $product->saveTranslations($request->input('translations'));
        // SEO заполняется сразу шаблоном (мгновенно, без внешних запросов);
        // «живые» тексты ИИ — кнопкой на вкладке SEO.
        app(\App\Services\SeoAiService::class)->fillIfEmpty($product->load(['translations', 'category']));
        CatalogService::flushCache();

        return back()->with('success', 'Позиция добавлена в каталог.');
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        $this->authorize('update', $product);
        $product->update($this->validated($request, $product));
        $product->saveTranslations($request->input('translations'));
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

    /**
     * Правила для переводов: те же поля, но ни одно не обязательно —
     * незаполненный язык откатывается к базовому значению карточки.
     *
     * @return array<string, mixed>
     */
    private function translationRules(): array
    {
        $rules = ['translations' => ['nullable', 'array']];

        foreach (Locales::ALL as $locale) {
            $rules["translations.$locale.name"] = ['nullable', 'string', 'max:255'];
            $rules["translations.$locale.short_description"] = ['nullable', 'string', 'max:255'];
            $rules["translations.$locale.description"] = ['nullable', 'string', 'max:5000'];
            $rules["translations.$locale.specs"] = ['nullable', 'array'];
            $rules["translations.$locale.colors"] = ['nullable', 'array'];
            $rules["translations.$locale.colors.*.name"] = ['nullable', 'string', 'max:60'];
            $rules["translations.$locale.colors.*.hex"] = ['nullable', 'string', 'max:9'];
        }

        return $rules;
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
            // Ниже этого остатка склад помечает товар «мало» и предупреждает.
            'min_stock' => ['nullable', 'numeric', 'min:0'],
            'order' => ['nullable', 'integer', 'min:0'],
            ...$this->translationRules(),
        ]);

        // Переводы уходят в свою таблицу, а не в колонки товара.
        unset($data['translations']);

        $data['order'] ??= 0;
        $data['min_order'] ??= 0;

        return $data;
    }

    /** Текущие SEO-поля карточки — для вкладки SEO в модалке. */
    public function seo(Product $product): \Illuminate\Http\JsonResponse
    {
        $this->authorize('update', $product);

        return response()->json($product->seoMeta?->only([
            'title', 'description', 'keywords', 'title_kk', 'description_kk', 'keywords_kk',
        ]) ?? []);
    }

    /** Сохранить SEO-поля; пустые строки стираются в null. */
    public function saveSeo(Request $request, Product $product): \Illuminate\Http\JsonResponse
    {
        $this->authorize('update', $product);

        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:300'],
            'keywords' => ['nullable', 'string', 'max:500'],
            'title_kk' => ['nullable', 'string', 'max:120'],
            'description_kk' => ['nullable', 'string', 'max:300'],
            'keywords_kk' => ['nullable', 'string', 'max:500'],
        ]);

        $product->seoMeta()->updateOrCreate([], array_map(fn ($v) => $v === '' ? null : $v, $data));
        CatalogService::flushCache();

        return response()->json(['ok' => true]);
    }

    /** Сгенерировать SEO: Claude при заданном ключе, иначе шаблон. */
    public function generateSeo(Product $product, \App\Services\SeoAiService $ai): \Illuminate\Http\JsonResponse
    {
        $this->authorize('update', $product);
        $product->load(['translations', 'category']);

        return response()->json($ai->generate($product));
    }

    /** ИИ-перевод карточки на kk и ru — из текущих значений формы. */
    public function translateAi(Request $request, Product $product, \App\Services\SeoAiService $ai): \Illuminate\Http\JsonResponse
    {
        $this->authorize('update', $product);

        $base = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'short_description' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:5000'],
            'specs' => ['nullable', 'array'],
            'colors' => ['nullable', 'array'],
        ]);

        try {
            return response()->json($ai->translations($base));
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => 'ИИ недоступен, попробуйте позже.'], 422);
        }
    }
}
