<script setup>
/**
 * Общий каркас денежных страниц: Обзор, Счета, Поступления, Расходы, Касса,
 * Задолженности, Мои расходы, Зарплата.
 *
 * Разделив Финансы на страницы, легко получить восемь разных страниц вместо
 * одного раздела: у каждой свой заголовок, своя ширина, свои отступы. Здесь
 * они получают одну шапку, один ряд вкладок и один контейнер — переход между
 * разделами перестаёт выглядеть как переход в другую систему.
 */
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useE } from '@/composables/useTranslations';

const tr = useE();

defineProps({
    // Заголовок страницы и пояснение под ним — короткое, одной строкой.
    title: { type: String, default: '' },
    subtitle: { type: String, default: '' },
    // Личные страницы уже́: на них нет широких таблиц.
    width: { type: String, default: 'max-w-7xl' },
});

const page = usePage();
const perms = computed(() => page.props.auth.user?.permissions ?? []);
const roles = computed(() => page.props.auth.user?.roles ?? []);
const leadership = computed(() => roles.value.some((r) => ['admin', 'director', 'financist'].includes(r)));

// Вкладки видны по тем же правам, что и пункты меню: сотрудник видит только
// «Мои расходы» и «Зарплату», и лишних дверей перед ним не появляется.
const TABS = [
    { name: tr('Обзор'), route: 'finance.index', perm: 'invoice.viewAny', leadership: true },
    { name: tr('Счета'), route: 'finance.invoices', perm: 'invoice.viewAny', leadership: true },
    { name: tr('Поступления'), route: 'finance.receipts', perm: 'invoice.viewAny', leadership: true },
    { name: tr('Расходы'), route: 'expensesBoard.index', perm: 'expense.viewAny', roles: ['admin', 'director', 'financist'] },
    { name: tr('Касса'), route: 'cashBook.index', perm: 'payment.viewAny', roles: ['admin', 'director', 'financist'] },
    { name: tr('Задолженности'), route: 'finance.debts', perm: 'invoice.viewAny', leadership: true },
    { name: tr('Мои расходы'), route: 'myExpenses.index', perm: 'expense.create' },
    { name: tr('Зарплата'), route: 'payroll.index', perm: 'payroll.view' },
    { name: tr('Бонусы'), route: 'bonuses.index', perm: 'payroll.view' },
];

const tabs = computed(() => TABS.filter((t) => (!t.perm || perms.value.includes(t.perm))
    && (!t.leadership || leadership.value)
    && (!t.roles || t.roles.some((r) => roles.value.includes(r)))));

const isActive = (name) => route().current(name);
</script>

<template>
    <AppLayout>
        <template #header>{{ title }}</template>

        <div class="mx-auto" :class="width">
            <!-- Вкладки раздела: горизонтальная прокрутка на телефоне, чтобы
                 длинный ряд не ломал страницу. -->
            <!-- Вкладки — тем же приёмом, что активный пункт меню: белая
                 приподнятая карточка. Два разных языка «где я» в одном окне
                 заставляли глаз искать активное дважды. -->
            <nav class="tab-rail mb-4 max-w-full overflow-x-auto">
                <Link v-for="t in tabs" :key="t.route" :href="route(t.route)"
                    class="tab-soft whitespace-nowrap"
                    :class="isActive(t.route) ? 'tab-soft-active' : ''">
                    {{ t.name }}
                </Link>
            </nav>

            <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold text-slate-900">{{ title }}</h2>
                    <p v-if="subtitle" class="mt-0.5 text-xs text-slate-400">{{ subtitle }}</p>
                </div>
                <!-- Кнопки и фильтры страницы — всегда в одном месте. -->
                <div class="flex flex-wrap items-center gap-2"><slot name="actions" /></div>
            </div>

            <slot />
        </div>
    </AppLayout>
</template>
