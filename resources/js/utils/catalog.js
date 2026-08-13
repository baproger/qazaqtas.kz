/**
 * Разбор полей каталога, которые в ERP редактируются построчно.
 *
 * Живут отдельно от страницы, потому что те же поля заполняются дважды:
 * в основной карточке и на вкладке перевода. Разойдись эти разборы —
 * характеристики на одном языке молча получили бы другой формат.
 */

/** «ключ: значение» построчно ⇄ объект характеристик товара. */
export function parseMap(text) {
    return Object.fromEntries(
        String(text ?? '')
            .split('\n')
            .map((line) => line.split(':'))
            .filter((parts) => parts.length >= 2)
            .map(([key, ...rest]) => [key.trim(), rest.join(':').trim()])
            .filter(([key]) => key !== ''),
    );
}

export function formatMap(map) {
    return Object.entries(map ?? {})
        .map(([key, value]) => `${key}: ${value}`)
        .join('\n');
}

/** «Название #HEX» построчно ⇄ палитра изделия. */
export function parseColors(text) {
    return String(text ?? '')
        .split('\n')
        .map((line) => line.trim().match(/^(.+?)\s+(#[0-9a-fA-F]{3,8})$/))
        .filter(Boolean)
        .map((m) => ({ name: m[1].trim(), hex: m[2] }));
}

export function formatColors(colors) {
    return (colors ?? []).map((c) => `${c.name} ${c.hex}`).join('\n');
}

/** «подпись: значение» построчно ⇄ выноски категории на витрине. */
export function parsePairs(text) {
    return String(text ?? '')
        .split('\n')
        .map((line) => line.split(':'))
        .filter((parts) => parts.length >= 2)
        .map(([label, ...rest]) => ({ label: label.trim(), value: rest.join(':').trim() }))
        .filter((row) => row.label !== '' && row.value !== '');
}

export function formatPairs(pairs) {
    return (pairs ?? []).map((p) => `${p.label}: ${p.value}`).join('\n');
}

/** Тип поля → пара «разобрать / показать». */
export const FIELD_CODECS = {
    map: { parse: parseMap, format: formatMap },
    colors: { parse: parseColors, format: formatColors },
    pairs: { parse: parsePairs, format: formatPairs },
};
