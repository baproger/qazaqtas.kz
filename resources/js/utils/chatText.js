/**
 * Разбор ответа помощника для показа БЕЗ v-html.
 *
 * Текст режется на строки, строки — на куски: обычный текст, **жирный** и
 * ссылки вида [Номер](/deals/12). Шаблон рисует их обычными узлами, поэтому
 * вставить в ERP чужой скрипт через ответ модели физически нельзя.
 *
 * Ссылки отдаются полем link: компонент сам решает, открыть их переходом
 * внутри приложения (без перезагрузки) или показать простым текстом.
 */
const TOKEN = /\*\*(.+?)\*\*|\[([^\]]+)\]\(([^)\s]+)\)/g;

/** Разбор одной строки на куски текста, жирного и ссылок. */
const parseLine = (body) => {
    const parts = [];
    let last = 0;
    let m;

    TOKEN.lastIndex = 0;
    while ((m = TOKEN.exec(body)) !== null) {
        if (m.index > last) parts.push({ text: body.slice(last, m.index) });

        if (m[1] !== undefined) {
            parts.push({ text: m[1], bold: true });
        } else {
            // Наружу выпускаем только внутренние адреса: «/deals/12».
            const href = m[3].startsWith('/') ? m[3] : null;
            parts.push({ text: m[2], link: href, bold: !href });
        }
        last = m.index + m[0].length;
    }

    if (last < body.length) parts.push({ text: body.slice(last) });

    return parts.length ? parts : [{ text: body }];
};

export const parseAnswer = (text) => String(text ?? '').split('\n').map((line) => {
    const trimmed = line.trim();
    const bullet = /^[-•*]\s+/.test(trimmed);
    const body = bullet ? trimmed.replace(/^[-•*]\s+/, '') : trimmed;

    return { bullet, empty: body === '', parts: parseLine(body) };
});
