<script setup>
import { ref, computed, nextTick, onMounted, onUnmounted, watch } from 'vue';
import { Link, usePage, router } from '@inertiajs/vue3';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import SkeletonScreen from '@/Components/SkeletonScreen.vue';
import { useT } from '@/composables/useTranslations';
import { useChatAlerts } from '@/composables/useChatAlerts';
import { useLocale } from '@/composables/useTranslations';
import { useE } from '@/composables/useTranslations';

const tr = useE();

const t = useT();

// Глобальные оповещения чата: звук + браузерное уведомление на любой странице ERP,
// счётчик непрочитанных — бейдж на пункте «Чат» в меню.
const { chatUnread } = useChatAlerts();

const page = usePage();

/*
 * Фильтр, восстановленный из памяти. Флаг ставит сервер
 * (App\Support\StickyFilters) и только тогда, когда набор действительно
 * подставлен — на странице, открытой с параметрами в адресе, плашки нет:
 * там фильтр виден и так.
 *
 * «Показать всё» шлёт `clear=1`: сброс обязан быть СИЛЬНЕЕ памяти, иначе
 * пустой набор параметров не отличить от «пришёл впервые», и фильтр
 * возвращался бы сразу после сброса.
 */
const stickyFilter = computed(() => page.props.stickyFilter ?? null);
const clearStickyFilter = () => router.get(window.location.pathname, { clear: 1 }, { replace: true });
const user = computed(() => page.props.auth.user);
const perms = computed(() => page.props.auth.user?.permissions ?? []);
const roles = computed(() => page.props.auth.user?.roles ?? []);
const isLeadership = computed(() => roles.value.some((r) => ['admin', 'director', 'financist'].includes(r)));
const flash = computed(() => page.props.flash || {});

// Тосты вместо баннера во всю ширину: сообщение всплывает справа сверху
// и уходит само (успех — 4 с, ошибка — 7 с); крестик закрывает раньше.
const toasts = ref([]);
let toastId = 0;
const dismissToast = (id) => { toasts.value = toasts.value.filter((t) => t.id !== id); };
const pushToast = (type, text) => {
    if (!text) return;
    const ms = type === 'error' ? 7000 : 4000;
    const id = ++toastId;
    toasts.value.push({ id, type, text, ms });
    setTimeout(() => dismissToast(id), ms);
};
watch(() => [flash.value.success, flash.value.error, page.url], ([ok, err]) => { pushToast('success', ok); pushToast('error', err); }, { immediate: true });
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
    // Меню собрано в разделы (просьба от 21.08.2026: «слишком много пунктов»).
    // Наверху остаётся только то, куда заходят каждый день; остальное лежит
    // по смыслу — продажи, производство, деньги, сайт, управление.
    // Раздел виден, если внутри есть хоть один доступный пункт, а раскрыт
    // по умолчанию только тот, в котором открыта текущая страница.
    { key: 'nav.analytics', name: tr('Аналитика'), route: 'analytics.index', icon: '◊', perm: 'report.viewAny', leadershipOnly: true },
    {
        key: 'nav.sales', name: tr('Продажи'), icon: '◈', children: [
            { key: 'nav.deals', name: tr('Сделки'), route: 'deals.index', icon: '◈', perm: 'deal.viewAny', notRoles: ['foreman'] },
            { key: 'nav.overdue', name: tr('Просроченные'), route: 'deals.overdue', icon: '⏰', perm: 'deal.viewAny', notRoles: ['foreman'] },
            // Заказы, оформленные на сайте: менеджер превращает их в сделки.
            { key: 'nav.siteOrders', name: tr('Заказы с сайта'), route: 'siteOrders.index', icon: '🛒', perm: 'deal.viewAny', roles: ['admin', 'director', 'financist', 'manager'] },
            { key: 'nav.reports', name: tr('Сводный отчет'), route: 'reports.deals', icon: '▦', perm: 'report.viewAny', roles: ['admin', 'director'] },
        ],
    },
    {
        key: 'nav.factory', name: tr('Производство'), icon: '⚒', children: [
            { key: 'nav.workshop', name: tr('Цех'), route: 'projects.index', icon: '◇', perm: 'project.viewAny' },
            // Сделки бригадира лежат здесь, а не в «Продажах»: он их не
            // продаёт, а ведёт в цехе, и видит только назначенные ему.
            // Право обязательно: закрыл владелец «Сделки» бригадиру в Правах доступа —
            // пункт исчезает, а не ведёт на 403.
            { key: 'nav.myDeals', name: tr('Мои сделки'), route: 'deals.index', icon: '◈', perm: 'deal.viewAny', onlyRoles: ['foreman'] },
            // Выработка бригад по сменам (бригадир видит только свои бригады).
            { key: 'nav.production', name: tr('Производство'), route: 'production.plans.index', icon: '⚒', perm: 'project.viewAny', roles: ['admin', 'director', 'production_head', 'financist', 'foreman', 'assistant'] },
            { key: 'nav.warehouse', name: tr('Склад'), route: 'warehouse.index', icon: '▤', perm: 'expense.viewAny', roles: ['admin', 'director', 'financist', 'manager'] },
        ],
    },
    // «Финансы» — всё, что про деньги. Каждый пункт виден по СВОИМ правам:
    // цеховому раздел откроется «Моими расходами» и «Зарплатой», обзора фирмы
    // он не увидит.
    {
        key: 'nav.finance', name: tr('Финансы'), icon: '₸', children: [
            { key: 'nav.finance.overview', name: tr('Обзор'), route: 'finance.index', icon: '◔', perm: 'invoice.viewAny', leadershipOnly: true },
            { key: 'nav.finance.invoices', name: tr('Счета'), route: 'finance.invoices', icon: '▤', perm: 'invoice.viewAny', leadershipOnly: true },
            { key: 'nav.finance.receipts', name: tr('Поступления'), route: 'finance.receipts', icon: '◕', perm: 'invoice.viewAny', leadershipOnly: true },
            { key: 'nav.finance.expenses', name: tr('Расходы'), route: 'expensesBoard.index', icon: '◫', perm: 'expense.viewAny', roles: ['admin', 'director', 'financist'] },
            { key: 'nav.finance.cashBook', name: tr('Касса'), route: 'cashBook.index', icon: '◰', perm: 'payment.viewAny', roles: ['admin', 'director', 'financist'] },
            { key: 'nav.finance.debts', name: tr('Задолженности'), route: 'finance.debts', icon: '◵', perm: 'invoice.viewAny', leadershipOnly: true },
            { key: 'nav.finance.myExpenses', name: tr('Мои расходы'), route: 'myExpenses.index', icon: '◨', perm: 'expense.create' },
            { key: 'nav.finance.payroll', name: tr('Зарплата'), route: 'payroll.index', icon: '💵', perm: 'payroll.view' },
            { key: 'nav.finance.bonuses', name: tr('Бонусы'), route: 'bonuses.index', icon: '◍', perm: 'payroll.view' },
        ],
    },
    {
        key: 'nav.site', name: tr('Сайт'), icon: '▥', children: [
            // Каталог сайта: карточки продукции, которые видит витрина.
            { key: 'nav.catalog', name: tr('Каталог сайта'), route: 'catalog.index', icon: '▥', perm: 'product.viewAny' },
            // Реализованные объекты: их фото идут крупными кадрами на главной.
            { key: 'nav.siteProjects', name: tr('Объекты сайта'), route: 'siteProjects.index', icon: '◱', perm: 'product.viewAny', roles: ['admin', 'director', 'financist'] },
        ],
    },
    { key: 'nav.chat', name: tr('Чат'), route: 'chat.index', icon: '✉' },
    {
        key: 'nav.admin', name: tr('Управление'), icon: '⚙', children: [
            { key: 'nav.users', name: tr('Сотрудники'), route: 'users.index', icon: '☻', perm: 'user.viewAny' },
            { key: 'nav.departments', name: tr('Отделы'), route: 'departments.index', icon: '⌂', perm: 'department.viewAny', leadershipOnly: true },
            { key: 'nav.structure', name: tr('Структура компании'), route: 'structure.index', icon: '⑃', perm: 'department.viewAny', leadershipOnly: true },
            { key: 'nav.audit', name: tr('Аудит'), route: 'audit.index', icon: '❑', roles: ['admin'] },
            { key: 'nav.errors', name: tr('Ошибки'), route: 'errors.index', icon: '⚠', roles: ['admin'] },
            { key: 'nav.settings', name: tr('Настройки'), route: 'settings.index', icon: '⚙', perm: 'setting.update' },
            { key: 'nav.translations', name: tr('Переводы'), route: 'translations.index', icon: '🌐', perm: 'setting.update' },
        ],
    },
    // «Профиль» отдельным пунктом не нужен: в него ведёт карточка внизу меню.
];
/**
 * Виден ли пункт меню этой роли.
 *
 * `notRoles` прячет пункт у перечисленных ролей, `onlyRoles` — наоборот,
 * показывает лишь им. Оба нужны, чтобы один и тот же маршрут стоял в разных
 * разделах у разных людей: бригадир ведёт сделки в цехе, а не продаёт их, и
 * его «Мои сделки» живут в «Производстве».
 */
const visible = (i) => (!i.perm || perms.value.includes(i.perm))
    && (!i.leadershipOnly || isLeadership.value)
    && (!i.roles || i.roles.some((r) => roles.value.includes(r)))
    && (!i.notRoles || ! i.notRoles.some((r) => roles.value.includes(r)))
    && (!i.onlyRoles || i.onlyRoles.some((r) => roles.value.includes(r)));
// Группа остаётся в меню, только если внутри есть что открыть.
/**
 * Заголовки секций меню.
 *
 * Порядок пунктов не трогаем — он настроен владельцем; только надписываем,
 * где кончается одно и начинается другое. Двадцать шесть строк подряд без
 * заголовков глаз не делит.
 */
const NAV_SECTIONS = {
    'nav.analytics': 'Главное',
    'nav.sales': 'Работа',
    'nav.site': 'Система',
};
const sectionFor = (item) => NAV_SECTIONS[item.key] ?? null;

const nav = computed(() => allNav
    .map((i) => (i.children ? { ...i, children: i.children.filter(visible) } : i))
    .filter((i) => (i.children ? i.children.length > 0 : visible(i))));

// Инлайн-SVG иконки (Lucide-style outline) по route — заменяют псевдо-иконки.
// Чисто презентационно: массив allNav и его perm/leadershipOnly не тронуты.
const navIcons = {
    'nav.sales': '<rect x="2" y="7" width="20" height="14" rx="2"/><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
    'nav.factory': '<path d="M2 20h20"/><path d="M4 20V10l5 4v-4l5 4V6h6v14"/>',
    'nav.finance': '<rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/><path d="M6 12h.01M18 12h.01"/>',
    'nav.site': '<circle cx="12" cy="12" r="10"/><path d="M2 12h20"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>',
    'nav.admin': '<path d="M12 2 4 6v6c0 5 3.4 8.4 8 10 4.6-1.6 8-5 8-10V6z"/><path d="m9 12 2 2 4-4"/>',
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
    'errors.index': '<path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/>',
    'audit.index': '<rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M9 11h6M9 15h6"/>',
    'departments.index': '<rect x="4" y="2" width="16" height="20" rx="2"/><path d="M9 22v-4h6v4"/><path d="M8 6h.01M12 6h.01M16 6h.01M8 10h.01M12 10h.01M16 10h.01M8 14h.01M12 14h.01M16 14h.01"/>',
    'users.index': '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
    'settings.index': '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
    'translations.index': '<circle cx="12" cy="12" r="10"/><path d="M2 12h20"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>',
};

/**
 * Текущий пункт меню — тот, чьё имя маршрута совпадает с открытой страницей
 * ТОЧНЕЕ остальных.
 *
 * Раньше сравнивался только первый сегмент имени, и на «Финансы → Счета»
 * подсвечивались разом Обзор, Счета, Поступления и Задолженности: у них общий
 * префикс `finance`. Пока подсветка была бледной, этого не замечали.
 *
 * `.index` из имени отбрасывается намеренно: «Сделки» должны оставаться
 * активными на карточке сделки (`deals.show`). Но если у раздела есть пункт с
 * более длинным совпадением, выигрывает он.
 */
const navRoutes = computed(() => nav.value.flatMap((i) => (i.children ? i.children.map((c) => c.route) : [i.route])).filter(Boolean));

const activeRoute = computed(() => {
    const current = route().current();
    if (! current) {
        return null;
    }

    let best = null;
    for (const name of navRoutes.value) {
        const stem = name.replace(/\.index$/, '');
        const hit = current === name || current.startsWith(stem + '.');
        if (hit && (best === null || name.length > best.length)) {
            best = name;
        }
    }

    return best;
});

const isActive = (name) => activeRoute.value === name;
const go = () => { mobileOpen.value = false; };

// Раскрытие группы меню («Финансы») переживает переходы между страницами:
// бухгалтер держит её открытой, цеховой — свёрнутой.
const groupOpen = ref({});
const groupStore = (key) => 'qt.menu.' + key.replace(/^nav\./, '');
const groupActive = (item) => item.children.some((c) => isActive(c.route));
// Клик по разделу в узком меню: разворачиваем меню и открываем раздел —
// иначе значок ведёт в никуда.
const openFromRail = (item) => {
    collapsed.value = false;
    groupOpen.value[item.key] = true;
};
const toggleGroup = (item) => {
    groupOpen.value[item.key] = !groupOpen.value[item.key];
    try { localStorage.setItem(groupStore(item.key), groupOpen.value[item.key] ? '1' : '0'); } catch { /* приватный режим */ }
};
/*
 * Прокрутка меню переживает переход.
 *
 * Лейаут не persistent: каждая страница подключает <AppLayout> в своём
 * шаблоне, поэтому при переходе меню целиком уничтожается и создаётся заново,
 * а новый узел начинается с нуля. Пользователь пролистывал до «Управления»,
 * нажимал пункт — и его выбрасывало наверх, к «Аналитике».
 *
 * Помним положение в sessionStorage: это одна вкладка и один сеанс, а не
 * настройка, которую надо тащить между устройствами. Восстанавливаем ДО
 * отрисовки — иначе виден прыжок сверху вниз.
 */
const NAV_SCROLL = 'nav.scroll';
const navEl = ref(null);
const rememberNavScroll = () => {
    try { sessionStorage.setItem(NAV_SCROLL, String(navEl.value?.scrollTop ?? 0)); } catch { /* приватный режим */ }
};

onMounted(() => {
    for (const item of allNav.filter((i) => i.children)) {
        let stored = null;
        try { stored = localStorage.getItem(groupStore(item.key)); } catch { /* приватный режим */ }
        // По умолчанию раскрыт только раздел с текущей страницей: разделы
        // затевались ради короткого меню, а открытые сразу все его удлиняют.
        // Ручное раскрытие запоминается и переживает переходы.
        groupOpen.value[item.key] = stored === null ? groupActive(item) : stored === '1' || groupActive(item);
    }

    // Прокрутку возвращаем ПОСЛЕ раскрытия разделов: они меняют высоту меню,
    // и восстановленное до них смещение указывало бы уже на другое место.
    nextTick(() => {
        try {
            const saved = Number(sessionStorage.getItem(NAV_SCROLL) ?? 0);
            if (saved > 0 && navEl.value) navEl.value.scrollTop = saved;
        } catch { /* приватный режим */ }
    });
});

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
const markAllRead = (silent = false) => router.patch(route('notifications.readAll'), { silent },
    { preserveScroll: true, preserveState: silent });

// Колокольчик как в мессенджерах: список открыли — через 1,5 с непрочитанное
// считается прочитанным. Раньше красный счётчик держался, пока каждое
// уведомление не откроют руками, и его переставали замечать. Кнопка
// «Открыть» на конкретном уведомлении работает независимо.
let bellTimer = null;
const bellClicked = () => {
    clearTimeout(bellTimer);
    bellTimer = setTimeout(() => {
        if (notifications.value.unread > 0) markAllRead(true);
    }, 1500);
};
onUnmounted(() => clearTimeout(bellTimer));
const setLocale = (l) => router.patch(route('locale.update'), { locale: l }, { preserveScroll: true });
const i18n = useLocale();
// Иконка/цвет уведомления по смыслу заголовка (просрочка, назначение, этап).
// Вкладки колокольчика: задачи отдельно от остального.
const TASK_TYPES = ['task_assigned', 'task_overdue', 'department_task_overdue'];
const notifTab = ref('tasks');
const isTaskNotif = (n) => TASK_TYPES.includes(n.data?.type);
const notifTabs = computed(() => [
    { key: 'tasks', label: tr('Задачи'), unread: notifications.value.items.filter((n) => !n.read_at && isTaskNotif(n)).length },
    { key: 'other', label: tr('Остальные'), unread: notifications.value.items.filter((n) => !n.read_at && !isTaskNotif(n)).length },
]);
const notifList = computed(() => notifications.value.items.filter((n) => (notifTab.value === 'tasks') === isTaskNotif(n)));
const NOTIF_ICONS = { task_assigned: ['✅', 'bg-emerald-100 text-emerald-700'], task_overdue: ['⏰', 'bg-rose-100 text-rose-600'], department_task_overdue: ['⏰', 'bg-rose-100 text-rose-600'],
    deal_stage_changed: ['📊', 'bg-indigo-100 text-indigo-600'], robot: ['🤖', 'bg-violet-100 text-violet-700'], expense_pending: ['🧾', 'bg-amber-100 text-amber-700'], expense_confirmed: ['✅', 'bg-emerald-100 text-emerald-700'],
    expense_handled: ['🧾', 'bg-slate-100 text-slate-600'], expense_threshold: ['⚠️', 'bg-rose-100 text-rose-600'], company_expense_submitted: ['🧾', 'bg-amber-100 text-amber-700'], company_expense_paid: ['💸', 'bg-emerald-100 text-emerald-700'],
    company_expense_stale: ['⏳', 'bg-amber-100 text-amber-700'], finance_deleted: ['🗑️', 'bg-rose-100 text-rose-600'], product_shortage: ['📦', 'bg-amber-100 text-amber-700'], production_plan_queued: ['🏭', 'bg-sky-100 text-sky-700'],
    site_order: ['🛒', 'bg-indigo-100 text-indigo-600'], chat_mention: ['💬', 'bg-sky-100 text-sky-700'], birthday: ['🎂', 'bg-pink-100 text-pink-700'] };
const notifMeta = (n) => {
    const byType = NOTIF_ICONS[n.data?.type];
    if (byType) return { icon: byType[0], cls: byType[1] };
    const s = ((n.data?.title || '') + ' ' + (n.data?.message || '')).toLowerCase();
    if (s.includes(tr('просроч')) || s.includes('overdue')) return { icon: '⏰', cls: 'bg-red-100 text-red-600' };
    if (s.includes(tr('задач')) || s.includes(tr('назнач'))) return { icon: '✅', cls: 'bg-emerald-100 text-emerald-600' };
    if (s.includes(tr('этап')) || s.includes(tr('сделк'))) return { icon: '📊', cls: 'bg-indigo-100 text-indigo-600' };
    if (s.includes(tr('оплат')) || s.includes(tr('счёт')) || s.includes(tr('счет'))) return { icon: '💰', cls: 'bg-amber-100 text-amber-600' };
    return { icon: '🔔', cls: 'bg-slate-100 text-slate-500' };
};
const relTime = (t) => {
    const d = (Date.now() - new Date(t).getTime()) / 1000;
    if (d < 60) return tr('только что');
    if (d < 3600) return Math.floor(d / 60) + tr(' мин назад');
    if (d < 86400) return Math.floor(d / 3600) + tr(' ч назад');
    if (d < 604800) return Math.floor(d / 86400) + tr(' дн назад');
    return new Date(t).toLocaleDateString('ru-RU');
};

// Подписи ролей приходят из БД одним общим списком (HandleInertiaRequests):
// зашитый в шаблоне словарь не знал ни «Бригадира», ни ролей, созданных
// владельцем через Настройки → Права доступа, и показывал голый код.
const roleLabels = computed(() => usePage().props.roleLabels ?? {});
const roleTitle = (code) => roleLabels.value[code] ?? code ?? '';
const roleLabel = computed(() => roleTitle(roles.value[0]));

// Live clock next to the language switcher.
const now = ref(new Date());
let clockTimer = null;
onMounted(() => { clockTimer = setInterval(() => (now.value = new Date()), 1000); });
onUnmounted(() => clearInterval(clockTimer));

// Размер шрифта из настроек — на корень документа. Снимаем при уходе из ERP
// (витрина живёт своим размером). Следим за пропом: сохранили настройку —
// применилось без перезагрузки.
const applyFont = (size) => { document.documentElement.dataset.uiFont = size || 'normal'; };
onMounted(() => applyFont(page.props.uiFontSize));
watch(() => page.props.uiFontSize, applyFont);
onUnmounted(() => { delete document.documentElement.dataset.uiFont; });
const clockTime = computed(() => now.value.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit', second: '2-digit' }));
const clockDate = computed(() => now.value.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' }));
</script>

<template>
    <div class="app-canvas min-h-screen">
        <!-- Mobile backdrop -->
        <transition enter-active-class="transition-opacity duration-200" enter-from-class="opacity-0" leave-active-class="transition-opacity duration-200" leave-to-class="opacity-0">
            <div v-if="mobileOpen" class="glass-backdrop fixed inset-0 z-30 lg:hidden" @click="mobileOpen = false"></div>
        </transition>

        <!-- Sidebar -->
        <aside
            :class="[
                collapsed ? 'lg:w-20' : 'lg:w-60',
                mobileOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0',
            ]"
            :data-rail="collapsed && !mobileOpen ? '' : null"
            class="sidebar-soft fixed inset-y-0 left-0 z-40 flex w-60 flex-col overflow-hidden transition-all duration-300 ease-in-out ">
            <!-- Шапка: знак, под ним кнопка сворачивания. В рельсе оба по
                 центру — иначе колонка значков смотрится сдвинутой вбок. -->
            <div class="flex items-center gap-2 px-4"
                :class="collapsed && !mobileOpen ? 'flex-col pt-4' : 'h-16'">
                <img
                    v-if="collapsed && !mobileOpen"
                    src="/logo-mark.png"
                    alt="QAZAQ TAS"
                    class="h-8 w-8 flex-shrink-0"
                />
                <button v-if="collapsed && !mobileOpen" type="button" @click="collapsed = false"
                    :title="t('header.collapse', 'Свернуть')"
                    class="hidden h-8 w-8 items-center justify-center rounded-lg bg-slate-100/80 text-slate-500 transition-colors duration-150 hover:bg-slate-200 hover:text-slate-700 lg:flex">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
                </button>
                <!-- Тёмный вариант надписи: исходный логотип белый и на
                     светлой панели пропадал. Знак в обоих одинаковый. -->
                <img v-if="!collapsed || mobileOpen" src="/logo-qazaqtas-dark.png" alt="QAZAQ TAS"
                    width="696" height="141" class="h-7 w-auto" />
                <!-- Свернуть — здесь, у края панели: рука уже у меню, и не надо
                     вести её вниз через весь список. -->
                <button v-if="!collapsed || mobileOpen" type="button" @click="collapsed = !collapsed"
                    :title="t('header.collapse', 'Свернуть')"
                    class="ml-auto hidden h-8 w-8 items-center justify-center rounded-lg bg-slate-100/80 text-slate-500 transition-colors duration-150 hover:bg-slate-200 hover:text-slate-700 lg:flex">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
                </button>
            </div>
            <nav ref="navEl" @scroll.passive="rememberNavScroll" class="flex-1 space-y-0.5 overflow-y-auto px-4 py-6">
                <template v-for="item in nav" :key="item.key ?? item.route">
                    <div v-if="sectionFor(item) && (!collapsed || mobileOpen)" class="nav-section">{{ $e(sectionFor(item)) }}</div>
                    <!-- В свёрнутом рельсе вместо надписи — черта: место
                         разрыва видно, а читать там нечего. -->
                    <div v-else-if="sectionFor(item) && item.key !== 'nav.analytics'" class="mx-3 my-2 h-px bg-slate-300/50"></div>
                    <!-- ===== Группа («Финансы»): свои пункты по своим правам ===== -->
                    <template v-if="item.children">
                        <!-- Узкое меню: раздел — один значок. Раньше сюда
                             вываливались все его пункты, и рельс становился
                             длиннее развёрнутого меню. Клик разворачивает
                             меню и открывает сам раздел. -->
                        <template v-if="collapsed && !mobileOpen">
                            <button type="button" @click="openFromRail(item)" :title="t(item.key, item.name)"
                                :class="groupActive(item) ? 'nav-group-open' : ''"
                                class="nav-item group relative">
                                                                <svg v-if="navIcons[item.key]" class="h-5 w-5 shrink-0 transition-colors duration-200"
                                    
                                    viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"
                                    v-html="navIcons[item.key]"></svg>
                                <span v-else class="text-lg leading-none">{{ item.icon }}</span>
                                <span class="nav-tip">{{ t(item.key, item.name) }}</span>
                            </button>
                        </template>
                        <div v-else>
                            <button type="button" @click="toggleGroup(item)"
                                :class="groupActive(item) ? 'nav-group-open' : ''"
                                class="nav-item group relative">
                                                                <svg v-if="navIcons[item.key]" class="h-5 w-5 shrink-0 transition-colors duration-200"
                                    
                                    viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"
                                    v-html="navIcons[item.key]"></svg>
                                <span v-else class="text-lg leading-none transition-colors duration-200"
                                    >{{ item.icon }}</span>
                                <span class="truncate">{{ t(item.key, item.name) }}</span>
                                <svg class="ml-auto h-3.5 w-3.5 shrink-0 text-slate-500 transition-transform duration-200"
                                    :class="groupOpen[item.key] ? 'rotate-90' : ''"
                                    viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
                            </button>
                            <Transition
                                enter-active-class="transition duration-200 ease-out" enter-from-class="opacity-0 -translate-y-1"
                                leave-active-class="transition duration-150 ease-in" leave-to-class="opacity-0 -translate-y-1">
                                <!-- Дочерние пункты БЕЗ иконок: раздел уже назван
                                     значком выше, а два десятка мелких символов
                                     рядом друг с другом читались как рябь. Их
                                     место обозначает направляющая слева. -->
                                <div v-show="groupOpen[item.key]" class="nav-children mt-1 space-y-0.5">
                                    <Link v-for="child in item.children" :key="child.route" :href="route(child.route)" @click="go"
                                        :class="isActive(child.route) ? 'nav-child-active' : 'text-slate-500 hover:bg-slate-100 hover:text-slate-900'"
                                        class="nav-child block truncate rounded-lg py-1.5 pl-4 pr-3 text-sm transition-colors duration-150">
                                        {{ t(child.key, child.name) }}
                                    </Link>
                                </div>
                            </Transition>
                        </div>
                    </template>

                    <Link v-else :href="route(item.route)" @click="go"
                        :title="collapsed ? t(item.key, item.name) : ''"
                        :class="isActive(item.route) ? 'nav-current' : ''"
                        class="nav-item group relative">
                        <svg v-if="navIcons[item.route]" class="h-5 w-5 shrink-0 transition-colors duration-200"
                            
                            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"
                            v-html="navIcons[item.route]"></svg>
                        <span v-else class="text-lg leading-none transition-colors duration-200" >{{ item.icon }}</span>
                        <span v-if="!collapsed || mobileOpen" class="truncate">{{ t(item.key, item.name) }}</span>
                        <span v-else class="nav-tip">{{ t(item.key, item.name) }}</span>
                        <span v-if="item.route === 'chat.index' && chatUnread > 0 && (!collapsed || mobileOpen)"
                            class="nav-badge">{{ chatUnread > 99 ? '99+' : chatUnread }}</span>
                        <span v-else-if="item.route === 'chat.index' && chatUnread > 0"
                            class="absolute right-1.5 top-1.5 h-2 w-2 rounded-full bg-indigo-600 ring-2 ring-white"></span>
                    </Link>
                </template>
            </nav>
            <!-- Низ панели: профиль и сворачивание. Наверху — работа, внизу
                 системное; так их не ищут глазами каждый раз заново. -->
            <!-- Карточка профиля: прижата к низу, отделена от списка. Имя и
                 почта — чтобы в общей базе было видно, под кем сидишь. -->
            <div class="mt-auto px-4 pb-6 pt-2">
                <Link :href="route('profile.edit')" @click="go" class="nav-profile">
                    <span class="flex flex-shrink-0 items-center justify-center overflow-hidden rounded-full bg-indigo-600 text-xs font-bold text-white"
                        :class="collapsed && !mobileOpen ? 'h-10 w-10' : 'h-9 w-9'">
                        <img v-if="user?.avatar" :src="user.avatar" class="h-full w-full object-cover" alt="" />
                        <template v-else>{{ user?.name?.charAt(0) ?? '?' }}</template>
                    </span>
                    <div v-if="!collapsed || mobileOpen" class="min-w-0 leading-tight">
                        <div class="truncate text-xs font-semibold text-slate-800">{{ user?.name }}</div>
                        <div class="truncate text-xs text-slate-500">{{ user?.email ?? roleLabel }}</div>
                    </div>
                </Link>
            </div>
        </aside>

        <!-- Main -->
        <div :class="collapsed ? 'lg:ml-20' : 'lg:ml-60'" class="flex-1 transition-all duration-300">
            <header class="glass sticky top-0 z-20 flex h-16 items-center justify-between border-b px-4 sm:px-6">
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
                        <button v-if="canAllCompanies" @click="switchCompany(0)" :title="$e('Общий отчёт по обеим компаниям')"
                            :class="currentCompanyId === 0 ? 'bg-white text-emerald-600 shadow' : 'text-slate-500'"
                            class="rounded px-2.5 py-1 font-semibold transition-all">{{ $e('Все') }}</button>
                    </div>
                    <span v-else-if="currentCompany" class="rounded-lg bg-slate-100 px-2.5 py-1.5 text-xs font-semibold text-slate-600">{{ currentCompany.name }}</span>

                    <!-- Live date & time -->
                    <div class="hidden items-center gap-2 rounded-lg bg-slate-100 px-3 py-1.5 text-xs md:flex">
                        <svg viewBox="0 0 24 24" class="h-3.5 w-3.5 text-slate-400" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                        <span class="font-semibold tabular-nums text-slate-700">{{ clockTime }}</span>
                        <span class="text-slate-300">·</span>
                        <span class="tabular-nums text-slate-500">{{ clockDate }}</span>
                    </div>

                    <!-- Список языков и подписи приходят с сервера: код языка
                         `kk`, но в кнопке стоит «KZ» — рядом с «RU» это
                         читается как страна, а не как опечатка. -->
                    <div class="hidden items-center rounded-lg bg-slate-100 p-0.5 text-xs sm:flex">
                        <button v-for="l in i18n.available" :key="l" @click="setLocale(l)"
                            :title="i18n.names[l]"
                            :aria-current="locale === l ? 'true' : undefined"
                            :class="locale === l ? 'bg-white text-indigo-600 shadow' : 'text-slate-500'"
                            class="rounded px-2 py-1 font-medium uppercase transition-all">{{ i18n.short[l] ?? l }}</button>
                    </div>

                    <Dropdown align="right" width="80">
                        <template #trigger>
                            <button class="relative rounded-full p-2 text-slate-500 transition-colors hover:bg-slate-100" @click="bellClicked">
                                <span class="text-lg">🔔</span>
                                <span v-if="notifications.unread > 0" class="absolute -right-0.5 -top-0.5 flex h-5 min-w-5 items-center justify-center rounded-full bg-red-500 px-1 text-xs font-bold text-white ring-2 ring-white">{{ notifications.unread > 9 ? '9+' : notifications.unread }}</span>
                            </button>
                        </template>
                        <template #content>
                            <div class="w-[min(26rem,calc(100vw-2rem))] rounded-2xl border border-white/60 bg-white/80 shadow-soft-lg backdrop-blur-xl">
                                <!-- Шапка + вкладки -->
                                <div class="flex items-center justify-between gap-2 px-4 pt-3">
                                    <span class="text-sm font-semibold text-slate-800">{{ t('header.notifications', 'Уведомления') }}</span>
                                    <button v-if="notifications.unread > 0" class="text-xs font-medium text-indigo-600 hover:text-indigo-700" @click.stop="markAllRead">{{ t('header.read_all', 'Прочитать все') }}</button>
                                </div>
                                <div class="mx-4 mt-2 flex rounded-xl bg-slate-100/80 p-0.5 text-xs font-medium" @click.stop>
                                    <button v-for="tab in notifTabs" :key="tab.key" type="button" @click="notifTab = tab.key"
                                        class="flex flex-1 items-center justify-center gap-1.5 rounded-lg px-2 py-1.5 transition"
                                        :class="notifTab === tab.key ? 'bg-white text-slate-900 shadow-soft' : 'text-slate-500 hover:text-slate-800'">
                                        {{ tab.label }}
                                        <span v-if="tab.unread" class="rounded-full bg-rose-500 px-1.5 text-xs font-bold text-white">{{ tab.unread }}</span>
                                    </button>
                                </div>
                                <!-- Список -->
                                <div class="mt-2 max-h-[22rem] overflow-y-auto px-2 pb-2">
                                    <div v-for="n in notifList" :key="n.id"
                                        class="group/n relative flex cursor-pointer gap-3 rounded-xl px-2.5 py-2.5 transition-colors hover:bg-white"
                                        :class="!n.read_at ? 'bg-indigo-50/60' : ''" @click="openNotification(n)">
                                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-base" :class="notifMeta(n).cls">{{ notifMeta(n).icon }}</span>
                                        <div class="min-w-0 flex-1">
                                            <div class="flex items-start justify-between gap-2">
                                                <span class="truncate text-sm" :class="n.read_at ? 'font-medium text-slate-600' : 'font-semibold text-slate-900'">{{ n.data.title }}</span>
                                                <span class="shrink-0 text-xs text-slate-400">{{ relTime(n.created_at) }}</span>
                                            </div>
                                            <div class="mt-0.5 line-clamp-2 text-xs leading-snug text-slate-500">{{ n.data.message }}</div>
                                        </div>
                                        <span v-if="!n.read_at" class="absolute right-2.5 top-2.5 h-2 w-2 rounded-full bg-indigo-500"></span>
                                    </div>
                                    <div v-if="!notifList.length" class="flex flex-col items-center gap-2 px-4 py-10 text-center">
                                        <span class="flex h-12 w-12 items-center justify-center rounded-full bg-slate-100 text-2xl">🔕</span>
                                        <span class="text-sm text-slate-400">{{ t('header.no_notifications', 'Нет уведомлений') }}</span>
                                    </div>
                                </div>
                                <Link :href="route('notifications.index')" class="block border-t border-slate-100/80 px-4 py-2.5 text-center text-sm font-medium text-indigo-600 hover:bg-indigo-50/60">{{ tr('Все уведомления и события →') }}</Link>
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

            <!-- Тосты: системные сообщения справа сверху, уходят сами -->
            <div class="pointer-events-none fixed right-4 top-4 z-[70] flex w-[min(24rem,calc(100vw-2rem))] flex-col gap-2">
                <transition-group enter-active-class="transition duration-300 ease-out" enter-from-class="opacity-0 translate-x-6" enter-to-class="opacity-100 translate-x-0"
                    leave-active-class="transition duration-200 ease-in" leave-to-class="opacity-0 translate-x-6">
                    <div v-for="tst in toasts" :key="tst.id"
                        class="pointer-events-auto relative flex items-start gap-3 overflow-hidden rounded-2xl border px-4 py-3 text-sm shadow-soft-lg backdrop-blur-md"
                        :class="tst.type === 'error' ? 'border-rose-200/70 bg-rose-50/90 text-rose-800' : 'border-emerald-200/70 bg-emerald-50/90 text-emerald-800'">
                        <span class="mt-0.5 text-base leading-none">{{ tst.type === 'error' ? '⛔' : '✅' }}</span>
                        <div class="min-w-0 flex-1 leading-snug">{{ tst.text }}</div>
                        <button type="button" class="-mr-1 rounded-md px-1 text-lg leading-none opacity-50 hover:opacity-100" @click="dismissToast(tst.id)">×</button>
                        <span class="absolute inset-x-3 bottom-0 h-0.5 origin-left rounded-full" :class="tst.type === 'error' ? 'bg-rose-400/60' : 'bg-emerald-400/60'" :style="{ animation: `toast-bar ${tst.ms}ms linear forwards` }"></span>
                    </div>
                </transition-group>
            </div>

            <div v-show="loading" class="pointer-events-none fixed inset-x-0 top-0 z-[60] h-0.5 overflow-hidden bg-indigo-100">
                <div class="loadbar h-full w-2/5 bg-indigo-600"></div>
            </div>
            <main class="page-enter p-4 sm:p-6">
                <!-- Фильтр вернулся из памяти — скажи об этом.
                     Молчаливое восстановление опаснее потерянного фильтра:
                     открыл «Сделки», увидел три штуки вместо ста и решил, что
                     данные пропали. Сброс здесь же, одним кликом. -->
                <div v-if="stickyFilter" class="mb-4 flex flex-wrap items-center gap-x-3 gap-y-1 rounded-xl border border-amber-200 bg-amber-50/60 px-4 py-2.5 text-sm">
                    <span class="font-medium text-amber-900">{{ $e('Показано по сохранённому фильтру') }}</span>
                    <span class="text-xs text-amber-700">{{ $e('условий:') }} {{ stickyFilter.count }}</span>
                    <button type="button" @click="clearStickyFilter"
                        class="ml-auto rounded-lg bg-white px-3 py-1 text-xs font-semibold text-amber-800 ring-1 ring-amber-200 transition-colors duration-150 hover:bg-amber-100">
                        {{ $e('Показать всё') }}
                    </button>
                </div>

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

<style>
@keyframes toast-bar { from { transform: scaleX(1); } to { transform: scaleX(0); } }
</style>
