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

        $web = $this->resize($file, self::WEB_WIDTH);
        $thumb = $this->resize($file, self::THUMB_WIDTH);

        $webPath = "{$dir}/{$name}.jpg";
        $thumbPath = "{$dir}/{$name}-thumb.jpg";

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
    private function resize(UploadedFile $file, int $maxWidth): string
    {
        $source = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));
        if (! $source) {
            return (string) file_get_contents($file->getRealPath());
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1, $maxWidth / max(1, $width));
        $targetW = (int) max(1, round($width * $scale));
        $targetH = (int) max(1, round($height * $scale));

        $canvas = imagecreatetruecolor($targetW, $targetH);
        // Белая подложка: PNG с прозрачностью не превращается в чёрный квадрат.
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetW, $targetH, $width, $height);

        ob_start();
        imagejpeg($canvas, null, 82);
        $data = (string) ob_get_clean();

        imagedestroy($canvas);
        imagedestroy($source);

        return $data;
    }
}
