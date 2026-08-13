import { reactive } from 'vue';
import { route } from '../../vendor/tightenco/ziggy';

/**
 * Язык интерфейса на клиенте.
 *
 * Состояние живёт в модуле, а не в компоненте: его читают и глобальные
 * хелперы шаблонов ($t, $r), и обычные .vue-файлы через импорт. app.js
 * обновляет его на каждом переходе Inertia, поэтому смена языка
 * перерисовывает всё приложение без перезагрузки страницы.
 */
export const i18n = reactive({
    /** Язык текущей страницы. */
    locale: 'kk',
    /** Основной язык: его адреса на витрине идут без префикса. */
    default: 'kk',
    available: ['kk', 'ru'],
    names: { kk: 'Қазақша', ru: 'Русский' },
    short: { kk: 'KZ', ru: 'RU' },
    /** Адреса текущей страницы на других языках (пусто вне витрины). */
    alternates: {},
    /** Плоский словарь [ключ => текст] для текущего языка. */
    map: {},
});

/** Забирает язык и словарь из props страницы Inertia. */
export function syncI18n(props = {}) {
    i18n.map = props.translations ?? {};

    const meta = props.i18n;
    if (!meta) return;

    i18n.locale = meta.locale ?? i18n.locale;
    i18n.default = meta.default ?? i18n.default;
    i18n.available = meta.available ?? i18n.available;
    i18n.names = meta.names ?? i18n.names;
    i18n.short = meta.short ?? i18n.short;
    i18n.alternates = meta.alternates ?? {};
}

/**
 * Текст по ключу: t('site.nav.catalog').
 *
 * Второй аргумент — запасной текст, если ключа нет ещё нигде; третий
 * подставляет значения в плейсхолдеры вида `:count`.
 */
export function t(key, fallback = null, replace = null) {
    const line = i18n.map[key] ?? fallback ?? key;

    return replace ? applyReplacements(line, replace) : line;
}

/**
 * Текст интерфейса ERP: e('Сохранить').
 *
 * Ключом служит сам русский текст — как в gettext. Так у каждой строки уже
 * есть осмысленный запасной вариант: пока казахского перевода нет, сотрудник
 * видит русскую фразу, а не `erp.deals.save`. Витрина устроена иначе (ключи
 * там короткие и осмысленные), потому что её тексты пишутся сразу на двух
 * языках, а интерфейс ERP переводится с уже написанного русского.
 */
export function e(text, replace = null) {
    return t(`erp.${text}`, text, replace);
}

/**
 * Форма слова при числе: tc('site.catalog.found', 12).
 *
 * Формы разделены `|`. В русском их три (1 позиция | 2 позиции | 5 позиций),
 * в казахском счётное слово не меняется, поэтому форма одна — и правило
 * выбирается по числу форм в самой строке, а не по языку. Так перевод
 * остаётся в файле словаря целиком, без ветвлений в коде.
 */
export function tc(key, count, replace = null) {
    const forms = t(key, null, null).split('|');
    const line = forms.length === 1 ? forms[0] : forms[pluralIndex(count, forms.length)];

    return applyReplacements(line, { count, ...(replace ?? {}) });
}

function pluralIndex(count, total) {
    const n = Math.abs(Number(count) || 0);

    if (total === 2) {
        return n === 1 ? 0 : 1;
    }

    // Русское правило: 1, 21, 31… → первая форма; 2–4, 22–24 → вторая;
    // 11–14 и всё остальное → третья.
    const mod10 = n % 10;
    const mod100 = n % 100;

    if (mod10 === 1 && mod100 !== 11) return 0;
    if (mod10 >= 2 && mod10 <= 4 && (mod100 < 12 || mod100 > 14)) return 1;

    return 2;
}

function applyReplacements(line, replace) {
    for (const [name, value] of Object.entries(replace)) {
        line = line.replaceAll(`:${name}`, String(value));
    }

    return line;
}

/**
 * Имя маршрута витрины на текущем языке: у основного языка адрес без
 * префикса (`site.catalog`), у остальных — с ним (`ru.site.catalog`).
 * Маршруты ERP языком не помечены и проходят как есть.
 */
export function localeRouteName(name) {
    return name.startsWith('site.') && i18n.locale !== i18n.default
        ? `${i18n.locale}.${name}`
        : name;
}

/** Ссылка на страницу витрины с сохранением текущего языка. */
export function siteRoute(name, params, absolute) {
    return route(localeRouteName(name), params, absolute);
}

/** Открыта ли сейчас эта страница — с учётом языкового префикса. */
export function isCurrentRoute(name) {
    return route().current(localeRouteName(name));
}
