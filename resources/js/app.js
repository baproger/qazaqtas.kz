import '../css/app.css';
import './bootstrap';

import { createInertiaApp, router } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createApp, h, reactive } from 'vue';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

// Global reactive UI translations. Updated on every Inertia visit so the whole
// app re-renders in the new language when the locale switches.
const i18n = reactive({ map: {} });
router.on('success', (event) => {
    i18n.map = event.detail.page.props.translations || {};
});

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob('./Pages/**/*.vue'),
        ),
    setup({ el, App, props, plugin }) {
        i18n.map = props.initialPage.props.translations || {};
        const app = createApp({ render: () => h(App, props) });
        // Global t() available in every template as $t('key', 'fallback') — no imports needed.
        app.config.globalProperties.$t = (key, fallback = null) => i18n.map[key] ?? fallback ?? key;
        return app
            .use(plugin)
            .use(ZiggyVue)
            .mount(el);
    },
    progress: {
        color: '#4B5563',
    },
});
