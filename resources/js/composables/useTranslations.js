import { usePage } from '@inertiajs/vue3';

/**
 * UI translation helper. Reads the shared `translations` map (current locale)
 * and returns t(key, fallback). Reactive — updates when the locale switches.
 *
 * Usage:
 *   const t = useT();
 *   t('nav.deals')            → "Сделки" / "Мәмілелер"
 *   t('x.missing', 'Запас')   → falls back to "Запас", then to the key itself
 */
export function useT() {
    const page = usePage();
    return (key, fallback = null) => page.props.translations?.[key] ?? fallback ?? key;
}
