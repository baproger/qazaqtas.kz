<script setup>
/**
 * Раздел «Настройки»: двухуровневая навигация.
 *
 * Слева внутреннее меню раздела (одно на все страницы настроек — раньше
 * полоска вкладок копировалась в каждую и расходилась), справа содержимое.
 * Права и маршруты не меняются: пункт показывается по тем же условиям, что
 * раньше показывалась вкладка.
 */
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useE } from '@/composables/useTranslations';

const props = defineProps({
    /** Подпись текущей страницы в хлебной крошке «Настройки › …». */
    title: { type: String, default: '' },
    /** Ширина содержимого: узкая форма или таблица во всю ширину. */
    wide: { type: Boolean, default: false },
});

const tr = useE();
const page = usePage();
const isAdmin = computed(() => page.props.auth.user?.roles?.includes('admin'));

const groups = computed(() => [
    { title: tr('Компания'), items: [
        { route: 'settings.index', label: tr('Общие'), icon: 'M12 3l9 8h-3v9h-5v-6h-2v6H6v-9H3z' },
        { route: 'structure.index', label: tr('Структура'), icon: 'M12 3v6M5 15v-3h14v3M5 21v-6M12 21v-6M19 21v-6' },
        { route: 'access.index', label: tr('Права доступа'), icon: 'M6 11V8a6 6 0 1 1 12 0v3M5 11h14v10H5z', show: isAdmin.value },
    ] },
    { title: tr('Продажи и производство'), items: [
        { route: 'stages.index', label: tr('Этапы'), icon: 'M4 6h16M4 12h10M4 18h6' },
        { route: 'custom-fields.index', label: tr('Доп. поля'), icon: 'M4 5h16v4H4zM4 15h16v4H4z' },
        { route: 'screens.index', label: tr('Экраны цехов'), icon: 'M3 5h18v11H3zM8 21h8M12 16v5' },
        { route: 'robots.index', label: tr('Автоматизация'), icon: 'M12 2v4M8 6h8a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2zM9 11h.01M15 11h.01M9 15h6M2 12h4M18 12h4' },
    ] },
    { title: tr('Сайт'), items: [
        { route: 'siteSettings.index', label: tr('Витрина'), icon: 'M3 12a9 9 0 1 0 18 0 9 9 0 0 0-18 0zM3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18' },
        { route: 'translations.index', label: tr('Переводы'), icon: 'M4 5h8M8 3v2M6 5c0 5 3 8 6 10M10 5c0 5-3 8-6 10M14 21l4-9 4 9M15.5 17h5' },
    ] },
]);

const isActive = (name) => route().current(name);
</script>

<template>
    <AppLayout>
        <template #header>
            <span class="flex items-center gap-2">
                <span>{{ tr('Настройки') }}</span>
                <template v-if="title">
                    <span class="text-slate-300 dark:text-slate-600">›</span>
                    <span class="font-medium text-slate-500 dark:text-slate-400">{{ title }}</span>
                </template>
            </span>
        </template>

        <div class="flex flex-col gap-6 lg:flex-row lg:gap-8">
            <!-- Внутреннее меню раздела -->
            <nav class="shrink-0 lg:w-56">
                <div class="flex gap-1 overflow-x-auto lg:flex-col lg:gap-5 lg:overflow-visible">
                    <div v-for="g in groups" :key="g.title" class="flex shrink-0 gap-1 lg:block">
                        <div class="hidden px-3 pb-1.5 text-xs font-semibold uppercase tracking-wide text-slate-400 lg:block">{{ g.title }}</div>
                        <template v-for="item in g.items" :key="item.route">
                            <Link v-if="item.show !== false" :href="route(item.route)"
                                class="flex items-center gap-2.5 whitespace-nowrap rounded-lg px-3 py-2 text-sm transition"
                                :class="isActive(item.route)
                                    ? 'bg-indigo-50 font-semibold text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300'
                                    : 'font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-300 dark:hover:bg-slate-800/60 dark:hover:text-slate-100'">
                                <svg class="h-4 w-4 shrink-0" :class="isActive(item.route) ? 'text-indigo-600 dark:text-indigo-400' : 'text-slate-400'"
                                    viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                    <path :d="item.icon" />
                                </svg>
                                {{ item.label }}
                            </Link>
                        </template>
                    </div>
                </div>
            </nav>

            <!-- Содержимое -->
            <div class="min-w-0 flex-1" :class="wide ? '' : 'max-w-3xl'">
                <slot />
            </div>
        </div>
    </AppLayout>
</template>
