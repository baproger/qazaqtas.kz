import { i18n, t, tc, e, siteRoute, isCurrentRoute } from '@/i18n';

/**
 * Хелперы перевода для script setup — то же самое, что глобальные $t/$r
 * в шаблонах, но доступное в коде компонента.
 *
 *   const t = useT();
 *   t('site.nav.catalog')            → «Каталог» / «Каталог»
 *   t('x.missing', 'Запас')          → запасной текст, затем сам ключ
 *   t('cart.items', null, { n: 3 })  → подстановка в `:n`
 */
export function useT() {
    return t;
}

/** Форма слова при числе: tc('site.catalog.found', 12). */
export function useTc() {
    return tc;
}

/** Текст интерфейса ERP по русскому оригиналу: e('Сохранить'). */
export function useE() {
    return e;
}

/** Язык страницы, список языков и адреса на других языках. */
export function useLocale() {
    return i18n;
}

/** Ссылки витрины с сохранением языка. */
export function useSiteRoute() {
    return { siteRoute, isCurrentRoute };
}
