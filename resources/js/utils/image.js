/**
 * Сжатие фотографии в браузере, до отправки на сервер.
 *
 * Снимок с телефона — 4–8 МБ. В цехе его грузят с мобильного интернета, и
 * ждать минуту загрузки никто не будет. Ужимаем прямо на устройстве: длинная
 * сторона 1600 px, JPEG 0.8 — 200–400 КБ вместо мегабайтов.
 *
 * На сервере стоит тот же предел (App\Services\ImageCompressor): здесь мы
 * экономим канал, там — страхуемся от файла, приехавшего мимо этой формы.
 */
const MAX_SIDE = 1600;
const QUALITY = 0.8;

/** Форматы, которые умеет пережать canvas. PNG оставляем как есть — прозрачность. */
const COMPRESSIBLE = ['image/jpeg', 'image/webp'];

/**
 * Вернуть сжатый File или исходный, если жать нечего либо не вышло.
 *
 * Не бросает: сорвавшееся сжатие не должно мешать загрузить файл.
 */
export async function compressImage(file) {
    if (!file || !COMPRESSIBLE.includes(file.type)) return file;

    try {
        // imageOrientation: телефон пишет поворот меткой EXIF, а canvas её не
        // читает — без этого вертикальный снимок уезжает в цех лежащим на боку.
        const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
        const side = Math.max(bitmap.width, bitmap.height);
        const scale = side > MAX_SIDE ? MAX_SIDE / side : 1;

        const canvas = document.createElement('canvas');
        canvas.width = Math.round(bitmap.width * scale);
        canvas.height = Math.round(bitmap.height * scale);
        canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height);
        bitmap.close?.();

        const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', QUALITY));
        // Пережали в плюс (бывает на маленьких картинках) — отдаём оригинал.
        if (!blob || blob.size >= file.size) return file;

        return new File([blob], file.name.replace(/\.(webp|jpeg)$/i, '.jpg'), {
            type: 'image/jpeg',
            lastModified: file.lastModified,
        });
    } catch {
        return file;
    }
}

export const isImage = (mime) => typeof mime === 'string' && mime.startsWith('image/');
