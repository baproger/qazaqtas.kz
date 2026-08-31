/**
 * Силуэт вырезанного PNG: до какой точки дотягивать выноску витрины.
 *
 * Выноски первого экрана стояли на фиксированных процентах кадра, но
 * силуэты у снимков разные: низкий бордюр кончается на 78% высоты, урна
 * тянется во весь кадр — и точка «цвета» у бордюра висела в пустоте.
 * Здесь снимок один раз читается в canvas, по альфа-каналу строится карта
 * строк силуэта, и выноска садится на кромку предмета на нужной высоте.
 */

/** Долгая сторона холста анализа: точность ~1% кадра, чтение мгновенное. */
const SAMPLE = 140;
/** Пиксель считается предметом от этой непрозрачности (из 255): мягкие тени мимо. */
const ALPHA = 90;

const cache = new Map();

/** Промис силуэта; null — посчитать нельзя (пустой кадр, ошибка, нет canvas). */
export function loadSilhouette(src) {
    if (!src || typeof document === 'undefined') return Promise.resolve(null);
    if (!cache.has(src)) cache.set(src, analyze(src).catch(() => null));
    return cache.get(src);
}

async function analyze(src) {
    const img = await load(src);
    const scale = SAMPLE / Math.max(img.naturalWidth, img.naturalHeight);
    const w = Math.max(1, Math.round(img.naturalWidth * scale));
    const h = Math.max(1, Math.round(img.naturalHeight * scale));

    const canvas = document.createElement('canvas');
    canvas.width = w;
    canvas.height = h;
    const ctx = canvas.getContext('2d', { willReadFrequently: true });
    ctx.drawImage(img, 0, 0, w, h);
    const data = ctx.getImageData(0, 0, w, h).data;

    // Для каждой строки — левый и правый край предмета в долях кадра.
    const rows = new Array(h).fill(null);
    let top = -1;
    let bottom = -1;
    for (let y = 0; y < h; y++) {
        let left = -1;
        let right = -1;
        for (let x = 0; x < w; x++) {
            if (data[(y * w + x) * 4 + 3] < ALPHA) continue;
            if (left === -1) left = x;
            right = x;
        }
        if (left === -1) continue;
        rows[y] = [left / w, right / w];
        if (top === -1) top = y;
        bottom = y;
    }
    if (top === -1) return null; // пустой кадр — выноскам не на что садиться

    return {
        width: img.naturalWidth,
        height: img.naturalHeight,

        /**
         * Срез предмета на высоте heightFrac (0 — макушка, 1 — низ).
         * Возвращает { left, right, y } в долях кадра — обе кромки нужны,
         * чтобы точка, отодвинутая ради ширины плашки, не вышла с другой
         * стороны предмета. Null — строк рядом нет.
         */
        edgeAt(heightFrac) {
            const wanted = Math.round(top + heightFrac * (bottom - top));
            let row = null;
            let y = wanted;
            // Строка могла выпасть по порогу — берём ближайшую заполненную.
            for (let d = 0; d < h && !row; d++) {
                if (rows[wanted + d]) { y = wanted + d; row = rows[y]; }
                else if (rows[wanted - d]) { y = wanted - d; row = rows[y]; }
            }
            if (!row) return null;
            return { left: row[0], right: row[1], y: y / h };
        },
    };
}

function load(src) {
    return new Promise((resolve, reject) => {
        const img = new Image();
        img.onload = () => resolve(img);
        img.onerror = reject;
        img.src = src;
    });
}
