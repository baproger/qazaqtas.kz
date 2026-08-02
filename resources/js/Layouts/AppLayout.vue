<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { Link, usePage, router } from '@inertiajs/vue3';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import SkeletonScreen from '@/Components/SkeletonScreen.vue';
import { useT } from '@/composables/useTranslations';
import { useChatAlerts } from '@/composables/useChatAlerts';

const t = useT();

// Глобальные оповещения чата: звук + браузерное уведомление на любой странице ERP,
// счётчик непрочитанных — бейдж на пункте «Чат» в меню.
const { chatUnread } = useChatAlerts();

const page = usePage();
const user = computed(() => page.props.auth.user);
const perms = computed(() => page.props.auth.user?.permissions ?? []);
const roles = computed(() => page.props.auth.user?.roles ?? []);
const isLeadership = computed(() => roles.value.some((r) => ['admin', 'director', 'financist'].includes(r)));
const flash = computed(() => page.props.flash || {});
const notifications = computed(() => page.props.notifications || { unread: 0, items: [] });
const locale = computed(() => page.props.locale || 'ru');

const collapsed = ref(false);   // desktop collapse
const mobileOpen = ref(false);  // mobile slide-over

// Global navigation loading bar + delayed skeleton (only on slow loads).
const loading = ref(false);
const showSkeleton = ref(false);
let skeletonTimer = null;
router.on('start', () => {
    loading.value = true;
    skeletonTimer = setTimeout(() => (showSkeleton.value = true), 300);
});
router.on('finish', () => {
    loading.value = false;
    clearTimeout(skeletonTimer);
    showSkeleton.value = false;
});

const allNav = [
    // Дашборд слит с Аналитикой: financist видит её по роли (как раньше дашборд).
    // Порядок меню — просьба от 22.07.2026: Аналитика → Просроченные → Сделки
    // → Цех → Склад → Сводный отчет → Финансы → ЗП → остальное.
    { key: 'nav.analytics', name: 'Аналитика', route: 'analytics.index', icon: '◊', leadershipOnly: true },
    { key: 'nav.overdue', name: 'Просроченные', route: 'deals.overdue', icon: '⏰', perm: 'deal.viewAny' },
    { key: 'nav.predeals', name: 'Предв. сделки', route: 'preDeals.index', icon: '◧', roles: ['admin', 'director', 'financist', 'manager'] },
    { key: 'nav.deals', name: 'Сделки', route: 'deals.index', icon: '◈', perm: 'deal.viewAny' },
    { key: 'nav.workshop', name: 'Цех', route: 'projects.index', icon: '◇', perm: 'project.viewAny' },
    { key: 'nav.warehouse', name: 'Склад', route: 'warehouse.index', icon: '▤', roles: ['admin', 'director', 'financist', 'manager'] },
    { key: 'nav.reports', name: 'Сводный отчет', route: 'reports.deals', icon: '▦', roles: ['admin', 'director'] },
    { key: 'nav.finance', name: 'Финансы', route: 'finance.index', icon: '₸', perm: 'invoice.viewAny', leadershipOnly: true },
    { key: 'nav.payroll', name: 'Зарплата', route: 'payroll.index', icon: '💵', perm: 'payroll.view' },
    { key: 'nav.chat', name: 'Чат', route: 'chat.index', icon: '✉' },
    { key: 'nav.audit', name: 'Аудит', route: 'audit.index', icon: '❑', roles: ['admin'] },
    { key: 'nav.departments', name: 'Отделы', route: 'departments.index', icon: '⌂', perm: 'department.viewAny', leadershipOnly: true },
    { key: 'nav.users', name: 'Сотрудники', route: 'users.index', icon: '☻', perm: 'user.viewAny' },
    { key: 'nav.profile', name: 'Профиль', route: 'profile.edit', icon: '🪪' },
    { key: 'nav.settings', name: 'Настройки', route: 'settings.index', icon: '⚙', perm: 'setting.update' },
    { key: 'nav.translations', name: 'Переводы', route: 'translations.index', icon: '🌐', perm: 'setting.update' },
];
const nav = computed(() => allNav.filter((i) => (!i.perm || perms.value.includes(i.perm)) && (!i.leadershipOnly || isLeadership.value) && (!i.roles || i.roles.some((r) => roles.value.includes(r)))));

// Инлайн-SVG иконки (Lucide-style outline) по route — заменяют псевдо-иконки.
// Чисто презентационно: массив allNav и его perm/leadershipOnly не тронуты.
const navIcons = {
    'analytics.index': '<path d="M3 3v16a2 2 0 0 0 2 2h16"/><path d="M7 15v-4M12 15V7M17 15v-6"/>',
    'reports.deals': '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M3 15h18M9 3v18M15 3v18"/>',
    'deals.index': '<rect x="2" y="7" width="20" height="14" rx="2"/><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
    'deals.overdue': '<circle cx="12" cy="13" r="8"/><path d="M12 9v4l2.5 2"/><path d="M5 3 2 6M22 6l-3-3"/>',
    'projects.index': '<path d="M2 20h20"/><path d="M4 20V10l5 4v-4l5 4V6h6v14"/>',
    'warehouse.index': '<path d="M21 8 12 3 3 8v8l9 5 9-5z"/><path d="M3 8l9 5 9-5M12 13v8"/>',
    'chat.index': '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
    'profile.edit': '<circle cx="12" cy="8" r="4"/><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>',
    'finance.index': '<rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/><path d="M6 12h.01M18 12h.01"/>',
    'payroll.index': '<path d="M21 12V7H5a2 2 0 0 1 0-4h14v4"/><path d="M3 5v14a2 2 0 0 0 2 2h16v-5"/><path d="M18 12a2 2 0 0 0 0 4h4v-4z"/>',
    'audit.index': '<rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M9 11h6M9 15h6"/>',
    'departments.index': '<rect x="4" y="2" width="16" height="20" rx="2"/><path d="M9 22v-4h6v4"/><path d="M8 6h.01M12 6h.01M16 6h.01M8 10h.01M12 10h.01M16 10h.01M8 14h.01M12 14h.01M16 14h.01"/>',
    'users.index': '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
    'settings.index': '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
    'translations.index': '<circle cx="12" cy="12" r="10"/><path d="M2 12h20"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>',
};

const isActive = (name) => {
    const base = name.split('.')[0];
    return route().current(base + '.*') || route().current(base);
};
const go = () => { mobileOpen.value = false; };

// Company switcher — full data separation per firm.
// «Все» (id=0) — общий отчёт по обеим фирмам для бухгалтера/админа.
const companies = computed(() => page.props.auth.companies ?? []);
const currentCompanyId = computed(() => page.props.auth.currentCompanyId);
const currentCompany = computed(() => companies.value.find((c) => c.id === currentCompanyId.value));
const canAllCompanies = computed(() => roles.value.some((r) => ['admin', 'financist'].includes(r)) && companies.value.length > 1);
const switchCompany = (id) => { if (id !== currentCompanyId.value) router.patch(route('company.switch'), { company_id: id }); };

const markRead = (id) => router.patch(route('notifications.read', id), {}, { preserveScroll: true, preserveState: true });
// Клик по уведомлению: отмечаем прочитанным и открываем связанную сделку/заказ.
const openNotification = (n) => {
    markRead(n.id);
    if (n.data?.url) router.get(n.data.url);
};
const markAllRead = () => router.patch(route('notifications.readAll'), {}, { preserveScroll: true });
const setLocale = (l) => router.patch(route('locale.update'), { locale: l }, { preserveScroll: true });
// Иконка/цвет уведомления по смыслу заголовка (просрочка, назначение, этап).
const notifMeta = (n) => {
    const s = ((n.data?.title || '') + ' ' + (n.data?.message || '')).toLowerCase();
    if (s.includes('просроч') || s.includes('overdue')) return { icon: '⏰', cls: 'bg-red-100 text-red-600' };
    if (s.includes('задач') || s.includes('назнач')) return { icon: '✅', cls: 'bg-emerald-100 text-emerald-600' };
    if (s.includes('этап') || s.includes('сделк')) return { icon: '📊', cls: 'bg-indigo-100 text-indigo-600' };
    if (s.includes('оплат') || s.includes('счёт') || s.includes('счет')) return { icon: '💰', cls: 'bg-amber-100 text-amber-600' };
    return { icon: '🔔', cls: 'bg-slate-100 text-slate-500' };
};
const relTime = (t) => {
    const d = (Date.now() - new Date(t).getTime()) / 1000;
    if (d < 60) return 'только что';
    if (d < 3600) return Math.floor(d / 60) + ' мин назад';
    if (d < 86400) return Math.floor(d / 3600) + ' ч назад';
    if (d < 604800) return Math.floor(d / 86400) + ' дн назад';
    return new Date(t).toLocaleDateString('ru-RU');
};

const roleLabels = { admin: 'СЕО (админ)', director: 'Директор', financist: 'Финансист-Бухгалтер', manager: 'Менеджер', employee: 'Сотрудник (цех)', lawyer: 'Юрист', cook: 'Повар', designer: 'Технолог', supplier: 'Снабженец' };
const roleLabel = computed(() => roleLabels[roles.value[0]] ?? roles.value[0] ?? '');

// Live clock next to the language switcher.
const now = ref(new Date());
let clockTimer = null;
onMounted(() => { clockTimer = setInterval(() => (now.value = new Date()), 1000); });
onUnmounted(() => clearInterval(clockTimer));
const clockTime = computed(() => now.value.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit', second: '2-digit' }));
const clockDate = computed(() => now.value.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' }));
</script>

<template>
    <div class="min-h-screen bg-slate-50">
        <!-- Mobile backdrop -->
        <transition enter-active-class="transition-opacity duration-200" enter-from-class="opacity-0" leave-active-class="transition-opacity duration-200" leave-to-class="opacity-0">
            <div v-if="mobileOpen" class="fixed inset-0 z-30 bg-black/40 lg:hidden" @click="mobileOpen = false"></div>
        </transition>

        <!-- Sidebar -->
        <aside
            :class="[
                collapsed ? 'lg:w-16' : 'lg:w-60',
                mobileOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0',
            ]"
            class="fixed inset-y-0 left-0 z-40 flex w-60 flex-col bg-slate-900 text-slate-300 transition-all duration-300 ease-in-out">
            <div class="flex h-16 items-center gap-2.5 px-4">
                <span class="flex h-9 w-9 flex-shrink-0 items-center justify-center overflow-hidden rounded-xl shadow-lg shadow-black/30">
                    <img src="/logo-qazaqtas.svg" alt="QAZAQ TAS" class="h-full w-full object-cover" />
                </span>
                <div v-if="!collapsed || mobileOpen" class="leading-tight">
                    <div class="text-sm font-semibold tracking-tight text-white">QAZAQ TAS</div>
                    <div class="text-[10px] font-medium uppercase tracking-widest text-slate-500">ERP · CRM</div>
                </div>
            </div>
            <nav class="flex-1 space-y-1.5 overflow-y-auto px-3 py-3">
                <Link v-for="item in nav" :key="item.route" :href="route(item.route)" @click="go"
                    :title="collapsed ? t(item.key, item.name) : ''"
                    :class="isActive(item.route) ? 'bg-white/10 text-white' : 'text-slate-400 hover:bg-white/5 hover:text-white'"
                    class="group relative flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors duration-200 ease-out">
                    <span v-if="isActive(item.route)" class="absolute left-0 top-1/2 h-5 w-[3px] -translate-y-1/2 rounded-r-full bg-indigo-500"></span>
                    <svg v-if="navIcons[item.route]" class="h-5 w-5 shrink-0 transition-colors duration-200"
                        :class="isActive(item.route) ? 'text-indigo-400' : 'text-slate-500 group-hover:text-slate-300'"
                        viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"
                        v-html="navIcons[item.route]"></svg>
                    <span v-else class="text-lg leading-none transition-colors duration-200" :class="isActive(item.route) ? 'text-indigo-400' : 'text-slate-500 group-hover:text-slate-300'">{{ item.icon }}</span>
                    <span v-if="!collapsed || mobileOpen" class="truncate">{{ t(item.key, item.name) }}</span>
                    <span v-if="item.route === 'chat.index' && chatUnread > 0 && (!collapsed || mobileOpen)"
                        class="ml-auto flex h-5 min-w-5 items-center justify-center rounded-full bg-red-500 px-1.5 text-[10px] font-bold text-white">{{ chatUnread > 99 ? '99+' : chatUnread }}</span>
                    <span v-else-if="item.route === 'chat.index' && chatUnread > 0"
                        class="absolute right-1.5 top-1.5 h-2 w-2 rounded-full bg-red-500 ring-2 ring-slate-900"></span>
                </Link>
            </nav>
            <!-- User block -->
            <Link :href="route('profile.edit')" @click="go"
                class="flex items-center gap-2.5 border-t border-white/5 px-3 py-3 transition-colors hover:bg-white/5">
                <span class="flex h-8 w-8 flex-shrink-0 items-center justify-center overflow-hidden rounded-full bg-indigo-600 text-xs font-bold text-white">
                    <img v-if="user?.avatar" :src="user.avatar" class="h-full w-full object-cover" alt="" />
                    <template v-else>{{ user?.name?.charAt(0) ?? '?' }}</template>
                </span>
                <div v-if="!collapsed || mobileOpen" class="min-w-0 leading-tight">
                    <div class="truncate text-xs font-semibold text-white">{{ user?.name }}</div>
                    <div class="truncate text-[10px] text-slate-500">{{ roleLabel }}</div>
                </div>
            </Link>
            <button class="hidden items-center gap-2 border-t border-white/5 px-4 py-2.5 text-left text-xs font-medium text-slate-500 transition-colors hover:text-white lg:flex" @click="collapsed = !collapsed">
                <span>{{ collapsed ? '»' : '«' }}</span><span v-if="!collapsed">{{ t('header.collapse', 'Свернуть') }}</span>
            </button>
        </aside>

        <!-- Main -->
        <div :class="collapsed ? 'lg:ml-16' : 'lg:ml-60'" class="flex-1 transition-all duration-300">
            <header class="sticky top-0 z-20 flex h-16 items-center justify-between border-b bg-white/80 px-4 shadow-sm backdrop-blur-md sm:px-6">
                <div class="flex min-w-0 flex-1 items-center gap-3">
                    <button class="flex-shrink-0 rounded-md p-2 text-slate-500 hover:bg-slate-100 lg:hidden" @click="mobileOpen = true">☰</button>
                    <h1 class="min-w-0 flex-1 text-base font-semibold text-slate-800 sm:text-lg"><slot name="header">{{ t('header.title', 'Панель управления') }}</slot></h1>
                </div>
                <!-- правый блок не сжимается: часы/фирма/язык всегда целиком -->
                <div class="flex flex-shrink-0 items-center gap-2 sm:gap-3">
                    <!-- Company switcher -->
                    <div v-if="companies.length > 1" class="flex items-center rounded-lg bg-slate-100 p-0.5 text-xs">
                        <button v-for="c in companies" :key="c.id" @click="switchCompany(c.id)"
                            :class="currentCompanyId === c.id ? 'bg-white text-emerald-600 shadow' : 'text-slate-500'"
                            class="rounded px-2.5 py-1 font-semibold transition-all">{{ c.name }}</button>
                        <button v-if="canAllCompanies" @click="switchCompany(0)" title="Общий отчёт по обеим компаниям"
                            :class="currentCompanyId === 0 ? 'bg-white text-emerald-600 shadow' : 'text-slate-500'"
                            class="rounded px-2.5 py-1 font-semibold transition-all">Все</button>
                    </div>
                    <span v-else-if="currentCompany" class="rounded-lg bg-slate-100 px-2.5 py-1.5 text-xs font-semibold text-slate-600">{{ currentCompany.name }}</span>

                    <!-- Live date & time -->
                    <div class="hidden items-center gap-2 rounded-lg bg-slate-100 px-3 py-1.5 text-xs md:flex">
                        <svg viewBox="0 0 24 24" class="h-3.5 w-3.5 text-slate-400" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                        <span class="font-semibold tabular-nums text-slate-700">{{ clockTime }}</span>
                        <span class="text-slate-300">·</span>
                        <span class="tabular-nums text-slate-500">{{ clockDate }}</span>
                    </div>

                    <div class="hidden items-center rounded-lg bg-slate-100 p-0.5 text-xs sm:flex">
                        <button v-for="l in ['ru','kk']" :key="l" @click="setLocale(l)"
                            :class="locale === l ? 'bg-white text-indigo-600 shadow' : 'text-slate-500'"
                            class="rounded px-2 py-1 font-medium uppercase transition-all">{{ l }}</button>
                    </div>

                    <Dropdown align="right" width="80">
                        <template #trigger>
                            <button class="relative rounded-full p-2 text-slate-500 transition-colors hover:bg-slate-100">
                                <span class="text-lg">🔔</span>
                                <span v-if="notifications.unread > 0" class="absolute -right-0.5 -top-0.5 flex h-5 min-w-5 items-center justify-center rounded-full bg-red-500 px-1 text-[10px] font-bold text-white ring-2 ring-white">{{ notifications.unread > 9 ? '9+' : notifications.unread }}</span>
                            </button>
                        </template>
                        <template #content>
                            <div class="w-72 sm:w-80">
                                <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3">
                                    <div class="flex items-center gap-2">
                                        <span class="text-sm font-semibold text-slate-800">{{ t('header.notifications', 'Уведомления') }}</span>
                                        <span v-if="notifications.unread > 0" class="rounded-full bg-red-100 px-1.5 py-0.5 text-[10px] font-bold text-red-600">{{ notifications.unread }}</span>
                                    </div>
                                    <button v-if="notifications.unread > 0" class="flex items-center gap-1 text-xs font-medium text-indigo-600 hover:text-indigo-700" @click="markAllRead">
                                        <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 10l3 3 4-5M9 13l3 3 5-7"/></svg>
                                        {{ t('header.read_all', 'Прочитать все') }}
                                    </button>
                                </div>
                                <div class="max-h-96 overflow-y-auto">
                                    <div v-for="n in notifications.items" :key="n.id"
                                        class="relative flex cursor-pointer gap-3 border-b border-slate-50 px-4 py-3 transition-colors hover:bg-slate-50"
                                        :class="!n.read_at ? 'bg-indigo-50/40' : ''" @click="openNotification(n)">
                                        <span v-if="!n.read_at" class="absolute left-0 top-0 h-full w-0.5 bg-indigo-500"></span>
                                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-sm" :class="notifMeta(n).cls">{{ notifMeta(n).icon }}</span>
                                        <div class="min-w-0 flex-1">
                                            <div class="flex items-start justify-between gap-2">
                                                <span class="text-sm font-semibold text-slate-800" :class="n.read_at ? 'text-slate-600' : ''">{{ n.data.title }}</span>
                                                <span v-if="!n.read_at" class="mt-1 h-2 w-2 shrink-0 rounded-full bg-indigo-500"></span>
                                            </div>
                                            <div class="mt-0.5 text-xs leading-snug text-slate-500">{{ n.data.message }}</div>
                                            <div class="mt-1 flex items-center gap-2 text-[11px] text-slate-400">
                                                <span>{{ relTime(n.created_at) }}</span>
                                                <span v-if="n.data.url" class="font-medium text-indigo-500">→ {{ n.data.deal_number || 'Открыть' }}</span>
                                            </div>
                                        </div>
                                    </div>
                                    <div v-if="!notifications.items.length" class="flex flex-col items-center gap-2 px-4 py-10 text-center">
                                        <span class="flex h-12 w-12 items-center justify-center rounded-full bg-slate-100 text-2xl">🔕</span>
                                        <span class="text-sm text-slate-400">{{ t('header.no_notifications', 'Нет уведомлений') }}</span>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </Dropdown>

                    <Dropdown align="right" width="48">
                        <template #trigger>
                            <button class="flex items-center gap-2 rounded-full bg-slate-50 px-2 py-1.5 text-sm text-slate-700 transition-colors hover:bg-slate-100 sm:px-3">
                                <span class="flex h-7 w-7 items-center justify-center overflow-hidden rounded-full bg-indigo-600 text-xs font-bold text-white">
                                    <img v-if="user?.avatar" :src="user.avatar" class="h-full w-full object-cover" alt="" />
                                    <template v-else>{{ user?.name?.charAt(0) ?? '?' }}</template>
                                </span>
                                <span class="hidden sm:block">{{ user?.name }}</span>
                            </button>
                        </template>
                        <template #content>
                            <div class="border-b px-4 py-2 text-xs text-slate-400">{{ user?.roles?.join(', ') }}</div>
                            <DropdownLink :href="route('profile.edit')">{{ t('header.profile', 'Профиль') }}</DropdownLink>
                            <DropdownLink :href="route('logout')" method="post" as="button">{{ t('header.logout', 'Выйти') }}</DropdownLink>
                        </template>
                    </Dropdown>
                </div>
            </header>

            <transition enter-active-class="transition duration-300" enter-from-class="opacity-0 -translate-y-1" enter-to-class="opacity-100">
                <div v-if="flash.success" class="mx-4 mt-4 rounded-lg bg-green-50 px-4 py-3 text-sm text-green-800 ring-1 ring-green-200 sm:mx-6">{{ flash.success }}</div>
            </transition>
            <div v-if="flash.error" class="mx-4 mt-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-800 ring-1 ring-red-200 sm:mx-6">{{ flash.error }}</div>

            <div v-show="loading" class="pointer-events-none fixed inset-x-0 top-0 z-[60] h-0.5 overflow-hidden bg-indigo-100">
                <div class="loadbar h-full w-2/5 bg-indigo-600"></div>
            </div>
            <main class="page-enter p-4 sm:p-6">
                <SkeletonScreen v-if="showSkeleton" />
                <slot v-else />
            </main>
        </div>

        <ConfirmModal />
    </div>
</template>

<style scoped>
.page-enter {
    animation: pageIn 0.35s cubic-bezier(0.16, 1, 0.3, 1);
}
@keyframes pageIn {
    from { opacity: 0; transform: translateY(8px); }
    to { opacity: 1; transform: translateY(0); }
}
.loadbar {
    animation: loadbar 1s ease-in-out infinite;
}
@keyframes loadbar {
    0% { transform: translateX(-100%); }
    100% { transform: translateX(350%); }
}
</style>
