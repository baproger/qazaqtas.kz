<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Загрузка медиа каталога. Фото сразу приводятся к вебу: большая версия
 * (до 1600 px) и превью (до 600 px) — витрина отдаёт нужный размер через
 * srcset, поэтому мобильный не тянет мегабайтные снимки с телефона.
 *
 * Всё лежит в storage/app/public (симлинк public/storage), файлы веб-сервер
 * отдаёт напрямую — Laravel в раздаче не участвует.
 */
class MediaService
{
    public const IMAGE_RULES = ['image', 'mimes:jpg,jpeg,png,webp', 'max:8192'];

    private const WEB_WIDTH = 1600;

    private const THUMB_WIDTH = 600;

    /**
     * Сохранить фото товара: оригинал ужимается до веб-размера, отдельно
     * кладётся превью.
     *
     * @return array{path: string, thumb: string, alt: string}
     */
    public function storeImage(UploadedFile $file, string $folder, string $alt = ''): array
    {
        $name = Str::random(20);
        $dir = trim($folder, '/');

        [$web, $ext] = $this->resize($file, self::WEB_WIDTH);
        [$thumb] = $this->resize($file, self::THUMB_WIDTH);

        $webPath = "{$dir}/{$name}.{$ext}";
        $thumbPath = "{$dir}/{$name}-thumb.{$ext}";

        Storage::disk('public')->put($webPath, $web);
        Storage::disk('public')->put($thumbPath, $thumb);

        return [
            'path' => Storage::url($webPath),
            'thumb' => Storage::url($thumbPath),
            'alt' => $alt,
        ];
    }

    /** Файл как есть (GLB-модель, PDF-документ) — без обработки. */
    public function storeFile(UploadedFile $file, string $folder): string
    {
        $path = $file->store(trim($folder, '/'), 'public');

        return Storage::url($path);
    }

    /**
     * Комплект 3D-модели. GLB самодостаточен, а OBJ ссылается на соседний
     * .mtl, который в свою очередь ссылается на файлы текстур ПО ИМЕНИ —
     * поэтому здесь имена файлов сохраняются как есть (только чистятся),
     * и весь комплект кладётся в одну папку, чтобы ссылки разрешились.
     *
     * @param  array<int, UploadedFile>  $files
     * @return array{model: string|null, files: array<int, string>}
     */
    public function storeModelSet(array $files, string $folder): array
    {
        $dir = trim($folder, '/');
        $stored = [];
        $model = null;

        foreach ($files as $file) {
            $name = $this->safeName($file->getClientOriginalName());
            Storage::disk('public')->putFileAs($dir, $file, $name);
            $url = Storage::url("{$dir}/{$name}");
            $stored[] = $url;

            // Главный файл — то, что открывает сцена: GLB/GLTF или OBJ.
            if (in_array(strtolower($file->getClientOriginalExtension()), ['glb', 'gltf', 'obj'], true)) {
                $model = $url;
            }
        }

        return ['model' => $model, 'files' => $stored];
    }

    /** Удалить папку целиком — комплект модели вместе с .mtl и текстурами. */
    public function deleteDirectory(string $folder): void
    {
        Storage::disk('public')->deleteDirectory(trim($folder, '/'));
    }

    /** Имя без путей и опасных символов — ссылки внутри .mtl должны сойтись. */
    private function safeName(string $original): string
    {
        $name = basename(str_replace('\\', '/', $original));
        $extension = Str::afterLast($name, '.');
        $base = Str::of(Str::beforeLast($name, '.'))->slug('_')->limit(60, '')->value();

        return ($base ?: Str::random(10)).'.'.Str::lower($extension);
    }

    /** Удалить по публичному URL (/storage/...) — и картинку, и её превью. */
    public function delete(?string ...$urls): void
    {
        foreach (array_filter($urls) as $url) {
            $path = Str::after($url, '/storage/');
            if ($path && $path !== $url) {
                Storage::disk('public')->delete($path);
            }
        }
    }

    /**
     * Пропорциональное уменьшение средствами GD: сторонних пакетов не тянем,
     * расширение gd есть и локально, и на Plesk.
     */
    /**
     * Ресайз с сохранением прозрачности.
     *
     * Раньше всё сводилось к JPEG на белой подложке — для фотографий это
     * правильно (файл легче), но вырезанный предмет с альфа-каналом
     * превращался в белый квадрат. Теперь формат выбирается по исходнику:
     * PNG остаётся PNG и сохраняет альфу, остальное сжимается в JPEG.
     *
     * @return array{0: string, 1: string} данные и расширение файла
     */
    private function resize(UploadedFile $file, int $maxWidth): array
    {
        $raw = (string) file_get_contents($file->getRealPath());
        $keepAlpha = $this->hasAlpha($file);

        $source = @imagecreatefromstring($raw);
        if (! $source) {
            return [$raw, $keepAlpha ? 'png' : 'jpg'];
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1, $maxWidth / max(1, $width));
        $targetW = (int) max(1, round($width * $scale));
        $targetH = (int) max(1, round($height * $scale));

        $canvas = imagecreatetruecolor($targetW, $targetH);

        if ($keepAlpha) {
            // Прозрачный холст: без этого GD зальёт фон чёрным.
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
        } else {
            // Белая подложка: PNG без альфы не превращается в чёрный квадрат.
            imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        }

        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetW, $targetH, $width, $height);

        ob_start();
        if ($keepAlpha) {
            imagepng($canvas, null, 6);
        } else {
            imagejpeg($canvas, null, 82);
        }
        $data = (string) ob_get_clean();

        imagedestroy($canvas);
        imagedestroy($source);

        return [$data, $keepAlpha ? 'png' : 'jpg'];
    }

    /**
     * Держать ли файл в PNG.
     *
     * Отличить PNG с реальной прозрачностью от полностью непрозрачного можно
     * только пройдя все пиксели — на снимке 1600×1600 это лишние миллионы
     * операций на каждую загрузку. Считаем проще: раз загрузили PNG, значит
     * альфа могла понадобиться, и формат сохраняем. Фотографии приходят
     * в JPEG и по-прежнему сжимаются.
     */
    private function hasAlpha(UploadedFile $file): bool
    {
        $info = @getimagesize($file->getRealPath());

        return ($info[2] ?? null) === IMAGETYPE_PNG;
    }
}
