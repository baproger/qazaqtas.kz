import '../css/app.css';
import './bootstrap';

import { createInertiaApp, router } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createApp, h } from 'vue';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';
import { syncI18n, t, tc, e, siteRoute, isCurrentRoute } from './i18n';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

// Ошибки браузера — в журнал ошибок ERP (Управление → Ошибки). Не чаще
// одного отчёта в секунду и не больше 20 за сеанс: журнал, а не поток.
const reportBrowserError = (() => {
    let sent = 0; let last = 0;
    return (payload) => {
        const now = Date.now();
        if (sent >= 20 || now - last < 1000) return;
        sent++; last = now;
        try {
            window.axios.post('/errors/browser', { ...payload, url: location.href }).catch(() => {});
        } catch { /* сеть недоступна — молчим */ }
    };
})();
window.addEventListener('error', (e) => reportBrowserError({
    kind: e.error?.name ?? 'Error', message: e.message ?? String(e.error ?? 'Ошибка'),
    file: e.filename, line: e.lineno, stack: e.error?.stack,
}));
window.addEventListener('unhandledrejection', (e) => reportBrowserError({
    kind: e.reason?.name ?? 'UnhandledRejection', message: e.reason?.message ?? String(e.reason ?? 'Promise rejected'),
    stack: e.reason?.stack,
}));

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

        // Ошибка рендера с ИМЕНЕМ компонента: голый stack в проде минифицирован
        // и не говорит, где упало, а __name у SFC переживает минификацию.
        // Цепочка родителей отвечает на вопрос «в каком именно месте страницы».
        app.config.errorHandler = (err, instance, info) => {
            const chain = [];
            let node = instance?.$ ?? instance;
            while (node && chain.length < 8) {
                const n = node.type?.__name || node.type?.name;
                if (n) chain.unshift(n);
                node = node.parent;
            }
            const where = chain.join(' > ') || 'неизвестный компонент';
            window.__vueCrash = { where, info, msg: String(err) };
            console.error(`[vue] ${where} (${info}):`, err);
            reportBrowserError({ kind: err?.name ?? 'VueError', message: `[${where}] ${err?.message ?? err}`, stack: err?.stack });
        };

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
