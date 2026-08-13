<?php

namespace App\Http\Controllers;

use App\Models\ProductCategory;
use App\Services\CatalogService;
use App\Services\MediaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Разделы каталога сайта: список, правка и снимок категории.
 *
 * Вынесены из CatalogController — тот отвечает за позиции, и держать в нём
 * ещё и категории с их медиа значило смешивать две сущности в одном файле.
 */
class CategoryController extends Controller
{
    /** Отдельная страница: категории со снимками и порядком. */
    public function categories(): Response
    {
        $this->authorize('viewAny', ProductCategory::class);

        return Inertia::render('Catalog/Categories', [
            'locales' => \App\Support\Locales::forForm(),
            'categories' => ProductCategory::withCount('products')->with('translations')
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
                    // Форма правит базовые значения; переводы едут отдельно.
                    'translations_map' => $c->translationsPayload(),
                    'products_count' => $c->products_count,
                ]),
        ]);
    }

    public function storeCategory(Request $request): RedirectResponse
    {
        $this->authorize('create', ProductCategory::class);
        $category = ProductCategory::create($this->validatedCategory($request));
        $category->saveTranslations($request->input('translations'));
        CatalogService::flushCache();

        return back()->with('success', 'Категория добавлена.');
    }

    public function updateCategory(Request $request, ProductCategory $category): RedirectResponse
    {
        $this->authorize('update', $category);
        $category->update($this->validatedCategory($request, $category));
        $category->saveTranslations($request->input('translations'));
        CatalogService::flushCache();

        return back()->with('success', 'Категория обновлена.');
    }

    public function destroyCategory(ProductCategory $category): RedirectResponse
    {
        $this->authorize('delete', $category);
        if ($category->products()->exists()) {
            return back()->with('error', 'В категории есть товары — сначала перенесите или удалите их.');
        }
        $category->delete();
        CatalogService::flushCache();

        return back()->with('success', 'Категория удалена.');
    }

    /** Снимок категории: вырезанный предмет на прозрачном фоне. */
    public function storeCategoryImage(Request $request, ProductCategory $category, MediaService $media): RedirectResponse
    {
        $this->authorize('update', $category);

        $request->validate([
            'image' => ['required', 'image', 'mimes:png,webp', 'max:8192'],
        ], [
            'image.mimes' => 'Нужен PNG или WebP с прозрачным фоном: JPG не умеет альфа-канал.',
        ]);

        $this->dropOwnedImage($category, $media);
        $stored = $media->storeImage($request->file('image'), 'categories/'.$category->id, $category->name);

        $category->update([
            'image' => $stored['path'],
            'thumb' => $stored['thumb'],
            'webp' => $stored['webp'] ?? null,
        ]);
        CatalogService::flushCache();

        return back()->with('success', 'Снимок категории обновлён.');
    }

    public function destroyCategoryImage(ProductCategory $category, MediaService $media): RedirectResponse
    {
        $this->authorize('update', $category);

        $this->dropOwnedImage($category, $media);
        $category->update(['image' => null, 'thumb' => null, 'webp' => null]);
        CatalogService::flushCache();

        return back()->with('success', 'Снимок категории удалён.');
    }

    /**
     * Удаляем только те файлы, что лежат в собственной папке категории.
     *
     * Путь в поле image мог быть проставлен вручную и указывать на чужой
     * файл — например, на снимок товара. Тогда замена картинки категории
     * стёрла бы фотографию из каталога. Владение проверяем по папке.
     */
    private function dropOwnedImage(ProductCategory $category, MediaService $media): void
    {
        $own = '/storage/categories/'.$category->id.'/';

        $media->delete(
            str_starts_with((string) $category->image, $own) ? $category->image : null,
            str_starts_with((string) $category->thumb, $own) ? $category->thumb : null,
            str_starts_with((string) $category->webp, $own) ? $category->webp : null,
        );
    }

    /** @return array<string, mixed> */
    private function validatedCategory(Request $request, ?ProductCategory $category = null): array
    {
        $rules = ['translations' => ['nullable', 'array']];

        // Перевод не обязателен ни в одном поле: пустое откатывается
        // к базовому значению категории.
        foreach (\App\Support\Locales::ALL as $locale) {
            $rules["translations.$locale.name"] = ['nullable', 'string', 'max:255'];
            $rules["translations.$locale.tagline"] = ['nullable', 'string', 'max:255'];
            $rules["translations.$locale.description"] = ['nullable', 'string', 'max:2000'];
            $rules["translations.$locale.specs"] = ['nullable', 'array', 'max:4'];
            $rules["translations.$locale.specs.*.label"] = ['nullable', 'string', 'max:40'];
            $rules["translations.$locale.specs.*.value"] = ['nullable', 'string', 'max:60'];
        }

        $data = $request->validate([
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
            ...$rules,
        ]);

        unset($data['translations']);

        return $data;
    }
}
