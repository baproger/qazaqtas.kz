/**
 * Разбор ответа помощника для показа БЕЗ v-html.
 *
 * Текст режется на строки, строки — на куски **жирного**; шаблон рисует их
 * обычными узлами. Разметка получается живой, а вставить в ERP чужой скрипт
 * через ответ модели физически нельзя. Используют и полная страница
 * помощника, и мини-чат в углу экрана.
 */
export const parseAnswer = (text) => String(text ?? '').split('\n').map((line) => {
    const trimmed = line.trim();
    const bullet = /^[-•*]\s+/.test(trimmed);
    const body = bullet ? trimmed.replace(/^[-•*]\s+/, '') : trimmed;

    return {
        bullet,
        empty: body === '',
        parts: body.split(/\*\*(.+?)\*\*/g).map((chunk, i) => ({ text: chunk, bold: i % 2 === 1 })),
    };
});
