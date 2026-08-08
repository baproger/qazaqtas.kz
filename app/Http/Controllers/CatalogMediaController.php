<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\CatalogService;
use App\Services\MediaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Медиа карточки каталога: фотогалерея, текстура для 3D, GLB-модель и
 * документы. Права те же, что и у самой карточки (ProductPolicy).
 */
class CatalogMediaController extends Controller
{
    public function __construct(private MediaService $media) {}

    /** Загрузка фотографий товара (можно несколько за раз). */
    public function storeImages(Request $request, Product $product): RedirectResponse
    {
        $this->authorize('update', $product);

        $request->validate([
            'images' => ['required', 'array', 'max:10'],
            'images.*' => MediaService::IMAGE_RULES,
        ]);

        $images = $product->images ?? [];
        foreach ($request->file('images') as $file) {
            $images[] = $this->media->storeImage($file, 'catalog/'.$product->id, $product->name);
        }

        $product->update(['images' => array_values($images)]);
        CatalogService::flushCache();

        return back()->with('success', 'Фотографии загружены.');
    }

    /** Удалить фото по индексу; заодно чистим файлы и текстуру 3D. */
    public function destroyImage(Request $request, Product $product): RedirectResponse
    {
        $this->authorize('update', $product);
        $data = $request->validate(['index' => ['required', 'integer', 'min:0']]);

        $images = $product->images ?? [];
        $removed = $images[$data['index']] ?? null;
        if (! $removed) {
            return back()->with('error', 'Фотография не найдена.');
        }

        unset($images[$data['index']]);
        $this->media->delete($removed['path'] ?? null, $removed['thumb'] ?? null);

        $updates = ['images' => array_values($images)];
        if ($product->texture_path === ($removed['path'] ?? null)) {
            $updates['texture_path'] = null;
        }

        $product->update($updates);
        CatalogService::flushCache();

        return back()->with('success', 'Фотография удалена.');
    }

    /** Сделать фото главным — оно уходит в карточку и в списки витрины. */
    public function makeMainImage(Request $request, Product $product): RedirectResponse
    {
        $this->authorize('update', $product);
        $data = $request->validate(['index' => ['required', 'integer', 'min:0']]);

        $images = array_values($product->images ?? []);
        if (! isset($images[$data['index']])) {
            return back()->with('error', 'Фотография не найдена.');
        }

        $main = array_splice($images, $data['index'], 1);
        $product->update(['images' => array_merge($main, $images)]);
        CatalogService::flushCache();

        return back()->with('success', 'Главное фото обновлено.');
    }

    /**
     * Отметить фото как ТЕКСТУРУ для 3D: этим снимком сцена красит плитку,
     * бордюр и малые формы. Лучше всего работает фрагмент поверхности сверху.
     */
    public function setTexture(Request $request, Product $product): RedirectResponse
    {
        $this->authorize('update', $product);
        $data = $request->validate(['index' => ['nullable', 'integer', 'min:0']]);

        $images = array_values($product->images ?? []);
        $path = $data['index'] !== null ? ($images[$data['index']]['path'] ?? null) : null;

        $product->update(['texture_path' => $path]);
        CatalogService::flushCache();

        return back()->with('success', $path ? 'Текстура для 3D выбрана.' : 'Текстура снята — сцена вернётся к цвету.');
    }

    /** GLB-модель: если загружена, конфигуратор показывает её вместо схемы. */
    public function storeModel(Request $request, Product $product): RedirectResponse
    {
        $this->authorize('update', $product);
        $request->validate([
            // mimes для .glb ненадёжен, проверяем расширение и размер.
            'model' => ['required', 'file', 'max:24576', 'extensions:glb,gltf'],
        ]);

        $this->media->delete($product->model_path);
        $product->update(['model_path' => $this->media->storeFile($request->file('model'), 'catalog/'.$product->id.'/models')]);
        CatalogService::flushCache();

        return back()->with('success', '3D-модель загружена.');
    }

    public function destroyModel(Product $product): RedirectResponse
    {
        $this->authorize('update', $product);
        $this->media->delete($product->model_path);
        $product->update(['model_path' => null]);
        CatalogService::flushCache();

        return back()->with('success', '3D-модель удалена.');
    }

    /** Документы карточки: паспорт изделия, сертификат, схема укладки. */
    public function storeDocument(Request $request, Product $product): RedirectResponse
    {
        $this->authorize('update', $product);
        $data = $request->validate([
            'document' => ['required', 'file', 'mimes:pdf,doc,docx,xls,xlsx', 'max:15360'],
            'name' => ['required', 'string', 'max:120'],
        ]);

        $documents = $product->documents ?? [];
        $documents[] = [
            'name' => $data['name'],
            'path' => $this->media->storeFile($request->file('document'), 'catalog/'.$product->id.'/docs'),
        ];

        $product->update(['documents' => $documents]);
        CatalogService::flushCache();

        return back()->with('success', 'Документ добавлен.');
    }

    public function destroyDocument(Request $request, Product $product): RedirectResponse
    {
        $this->authorize('update', $product);
        $data = $request->validate(['index' => ['required', 'integer', 'min:0']]);

        $documents = $product->documents ?? [];
        $removed = $documents[$data['index']] ?? null;
        if ($removed) {
            $this->media->delete($removed['path'] ?? null);
            unset($documents[$data['index']]);
            $product->update(['documents' => array_values($documents)]);
            CatalogService::flushCache();
        }

        return back()->with('success', 'Документ удалён.');
    }
}
