import '../css/app.css';
import './bootstrap';

import { createInertiaApp, router } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createApp, h } from 'vue';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';
import { syncI18n, t, tc, e, siteRoute, isCurrentRoute } from './i18n';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

// Язык обновляется на каждом переходе Inertia — при смене языка всё
// приложение перерисовывается без перезагрузки страницы.
router.on('success', (event) => syncI18n(event.detail.page.props));

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob('./Pages/**/*.vue'),
        ),
    setup({ el, App, props, plugin }) {
        syncI18n(props.initialPage.props);
        const app = createApp({ render: () => h(App, props) });

        // Доступно в любом шаблоне без импортов:
        //   $t('site.nav.catalog')      — текст на текущем языке
        //   $tc('site.catalog.found', 5) — форма слова при числе
        //   $e('Сохранить')            — текст интерфейса ERP
        //   $r('site.catalog')          — ссылка с сохранением языка
        //   $rIs('site.catalog')        — открыта ли эта страница сейчас
        app.config.globalProperties.$t = t;
        app.config.globalProperties.$tc = tc;
        app.config.globalProperties.$e = e;
        app.config.globalProperties.$r = siteRoute;
        app.config.globalProperties.$rIs = isCurrentRoute;

        return app
            .use(plugin)
            .use(ZiggyVue)
            .mount(el);
    },
    progress: {
        color: '#4B5563',
    },
});
