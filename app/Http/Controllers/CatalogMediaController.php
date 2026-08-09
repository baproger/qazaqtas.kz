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

    /**
     * 3D-модель изделия. Принимаем и GLB/GLTF, и комплект OBJ:
     *  - GLB — один самодостаточный файл (материалы и текстуры внутри);
     *  - OBJ — .obj + .mtl + файлы текстур, всё одной загрузкой: .mtl
     *    ссылается на текстуры по именам, поэтому комплект нужен целиком.
     */
    public function storeModel(Request $request, Product $product): RedirectResponse
    {
        $this->authorize('update', $product);

        $request->validate([
            'models' => ['required', 'array', 'min:1', 'max:12'],
            // mimes для 3D-форматов ненадёжен — проверяем расширение и размер.
            'models.*' => ['file', 'max:24576', 'extensions:glb,gltf,obj,mtl,bin,jpg,jpeg,png,webp'],
        ], [
            'models.*.extensions' => 'Допустимы: .glb, .gltf, .obj, .mtl и файлы текстур (jpg, png, webp).',
        ]);

        $files = $request->file('models');
        $main = collect($files)->filter(
            fn ($f) => in_array(strtolower($f->getClientOriginalExtension()), ['glb', 'gltf', 'obj'], true)
        );

        if ($main->count() !== 1) {
            return back()->with('error', $main->isEmpty()
                ? 'В комплекте нет самой модели — добавьте файл .glb или .obj.'
                : 'В одной загрузке должна быть одна модель: либо .glb, либо .obj с сопровождением.');
        }

        // Старый комплект убираем целиком: файлы .mtl и текстур тоже.
        $this->media->deleteDirectory($this->modelFolder($product));

        $set = $this->media->storeModelSet($files, $this->modelFolder($product));
        $product->update(['model_path' => $set['model']]);
        CatalogService::flushCache();

        $isObj = str_ends_with(strtolower((string) $set['model']), '.obj');
        $hasMtl = collect($set['files'])->contains(fn ($f) => str_ends_with(strtolower($f), '.mtl'));

        return back()->with(
            'success',
            $isObj && ! $hasMtl
                ? 'OBJ загружен, но без файла .mtl — в сцене модель будет серой, без материалов.'
                : '3D-модель загружена ('.count($set['files']).' файл(ов)).'
        );
    }

    public function destroyModel(Product $product): RedirectResponse
    {
        $this->authorize('update', $product);
        $this->media->deleteDirectory($this->modelFolder($product));
        $product->update(['model_path' => null]);
        CatalogService::flushCache();

        return back()->with('success', '3D-модель удалена.');
    }

    /** Комплект модели живёт в отдельной папке — так ссылки .mtl сходятся. */
    private function modelFolder(Product $product): string
    {
        return 'catalog/'.$product->id.'/models';
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
