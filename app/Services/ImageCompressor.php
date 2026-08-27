<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;

/**
 * Сжатие фотографий при загрузке.
 *
 * Фото в цех приходят с телефона — 4–8 МБ на снимок, и через месяц работы
 * диск занят фотографиями, а бригадир на мобильном интернете ждёт открытия
 * карточки. Для того, ради чего фото делают (видно, что отлили и как
 * упаковали), хватает длинной стороны 1600 px.
 *
 * Формат сохраняем: JPEG → JPEG, PNG → PNG, WebP → WebP. Перегонять PNG в
 * JPEG нельзя — потеряется прозрачность у схем и скриншотов.
 *
 * Браузер жмёт фото ещё до отправки (resources/js/utils/image.js) — здесь
 * страховка: файл мог приехать из другого клиента или мимо той формы.
 */
class ImageCompressor
{
    /** Длинная сторона после сжатия, px. */
    public const MAX_SIDE = 1600;

    /** Качество JPEG/WebP. 78 — граница, за которой глазом уже не отличить. */
    public const QUALITY = 78;

    private const HANDLERS = [
        'image/jpeg' => 'jpeg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    /**
     * Сжать загруженный файл НА МЕСТЕ (во временном каталоге, до store()).
     *
     * Возвращает true, если файл переписан. Всё, что не картинка, картинка
     * неизвестного формата или уже маленькая, — отдаём как есть: молча
     * испортить чужой файл хуже, чем не сжать его.
     */
    public function compress(UploadedFile $file): bool
    {
        $format = self::HANDLERS[$file->getMimeType()] ?? null;
        if ($format === null || ! extension_loaded('gd')) {
            return false;
        }

        $path = $file->getRealPath();
        $image = @imagecreatefromstring((string) file_get_contents($path));
        if ($image === false) {
            return false;
        }

        $image = $this->applyExifOrientation($image, $path, $format);

        $width = imagesx($image);
        $height = imagesy($image);
        $side = max($width, $height);

        // Уже маленькая: пережимать нечего — только потеряли бы качество.
        if ($side <= self::MAX_SIDE && $format !== 'jpeg') {
            imagedestroy($image);

            return false;
        }

        if ($side > self::MAX_SIDE) {
            $scaled = imagescale($image, (int) round($width * self::MAX_SIDE / $side));
            if ($scaled !== false) {
                imagedestroy($image);
                $image = $scaled;
            }
        }

        $written = $this->write($image, $path, $format);
        imagedestroy($image);

        return $written;
    }

    /**
     * Развернуть фото по метке EXIF.
     *
     * Телефон пишет снимок как есть, а поворот кладёт в тег ориентации.
     * Сжатие тег теряет, и фото, снятое вертикально, приезжало бы в цех
     * лежащим на боку.
     *
     * @param  \GdImage  $image
     * @return \GdImage
     */
    private function applyExifOrientation($image, string $path, string $format)
    {
        if ($format !== 'jpeg' || ! function_exists('exif_read_data')) {
            return $image;
        }

        $orientation = @exif_read_data($path)['Orientation'] ?? null;
        $angle = match ($orientation) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        if ($angle === 0) {
            return $image;
        }

        $rotated = imagerotate($image, $angle, 0);
        if ($rotated === false) {
            return $image;
        }
        imagedestroy($image);

        return $rotated;
    }

    /** @param  \GdImage  $image */
    private function write($image, string $path, string $format): bool
    {
        return match ($format) {
            'jpeg' => imagejpeg($image, $path, self::QUALITY),
            // PNG: 6 — обычный компромисс zlib, альфа-канал сохраняем.
            'png' => imagealphablending($image, false) && imagesavealpha($image, true)
                && imagepng($image, $path, 6),
            'webp' => imagewebp($image, $path, self::QUALITY),
        };
    }
}
