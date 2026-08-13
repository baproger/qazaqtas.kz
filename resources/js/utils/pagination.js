import { computed } from 'vue';

/**
 * Разбор ссылок пагинатора Laravel.
 *
 * Подписи приходят с HTML-сущностями («&laquo; Previous»), поэтому раньше их
 * выводили через v-html. Здесь они разбираются на данные: крайние ссылки —
 * стрелки, остальные — номера. Шаблон выводит номер обычной интерполяцией,
 * и обходить автоэкранировку Vue не приходится.
 *
 * @param {import('vue').Ref<Array>|Function} source ссылки из пагинатора
 */
export function usePageLinks(source) {
    return computed(() => {
        const links = (typeof source === 'function' ? source() : source?.value) ?? [];

        return links.map((link, i) => {
            const previous = i === 0;
            const next = i === links.length - 1;

            return {
                key: `${i}-${link.label}`,
                url: link.url,
                active: link.active,
                arrow: previous ? 'prev' : next ? 'next' : null,
                label: link.label,
                aria: previous
                    ? 'Предыдущая страница'
                    : next ? 'Следующая страница' : `Страница ${link.label}`,
            };
        });
    });
}
