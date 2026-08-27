<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Avatar from '@/Components/Avatar.vue';
import { useE } from '@/composables/useTranslations';

const tr = useE();

const props = defineProps({
    byEmployee: Array, monthsFilter: Number, funnel: Array, byStatus: Object, monthly: Array,
    abc: Array, abcSummary: Object, conversion: Object, totals: Object,
    attention: Object, period: Object, topManagers: Array, filters: Object, managers: Array, stageOptions: Array, companyMoney: Object,
});

const tab = ref('general');

// Серверные фильтры: период (для «за период»/топа менеджеров), менеджер,
// этап, поиск — применяются к воронке, «за период» и топу менеджеров.
const search = ref(props.filters?.search ?? '');
const from = ref(props.filters?.from ?? '');
const to = ref(props.filters?.to ?? '');
const manager = ref(props.filters?.manager ?? '');
const stageF = ref(props.filters?.stage ?? '');
const params = (extra = {}) => ({
    months: props.monthsFilter,
    search: search.value || undefined,
    from: from.value || undefined,
    to: to.value || undefined,
    manager: manager.value || undefined,
    stage: stageF.value || undefined,
    ...extra,
});
const apply = (extra = {}) => router.get(route('analytics.index'), params(extra), { preserveState: true, preserveScroll: true, replace: true });
let searchTimer = null;
const onSearch = () => { clearTimeout(searchTimer); searchTimer = setTimeout(apply, 350); };
const hasFilters = computed(() => search.value || manager.value || stageF.value);
const resetFilters = () => { search.value = ''; from.value = ''; to.value = ''; manager.value = ''; stageF.value = ''; apply(); };
const selected = ref(props.byEmployee?.[0] ?? null);
// Фильтр по сотрудникам: поиск по имени, список и выбор обновляются сразу.
const empSearch = ref('');
const filteredEmployees = computed(() => {
    const s = empSearch.value.trim().toLowerCase();
    const list = s ? props.byEmployee.filter((e) => (e.user ?? '').toLowerCase().includes(s)) : props.byEmployee;
    if (list.length && (!selected.value || !list.some((e) => e.uid === selected.value.uid))) selected.value = list[0];
    return list;
});
const money = (v) => new Intl.NumberFormat('ru-RU').format(Math.round(v ?? 0)) + ' ₸';
// Рейтинг сотрудников: место по списку (он отсортирован) + шкала ЗП к лидеру.
const maxBonus = computed(() => Math.max(...(props.byEmployee ?? []).map((e) => Number(e.bonus) || 0), 1));
const rankOf = (uid) => (props.byEmployee ?? []).findIndex((e) => e.uid === uid) + 1;
const rankBadge = (r) => r === 1 ? '👑' : r === 2 ? '🥈' : r === 3 ? '🥉' : '#' + r;
// Colour a per-deal margin badge: healthy ≥ 40%, thin 20–40%, poor/negative below.
const marginClass = (m) => m >= 40 ? 'bg-emerald-50 text-emerald-700 ring-emerald-200' : m >= 20 ? 'bg-amber-50 text-amber-700 ring-amber-200' : 'bg-rose-50 text-rose-700 ring-rose-200';
const maxFunnel = computed(() => Math.max(1, ...props.funnel.map((f) => f.count)));
const maxMonthly = computed(() => Math.max(1, ...props.monthly.flatMap((m) => [m.income, m.expense])));
const setMonths = (m) => apply({ months: m });
const statusLabels = { draft: tr('Черновик'), active: tr('Активные'), closed: tr('Закрыты'), cancelled: tr('Отменены') };
const statusColors = { draft: '#cbd5e1', active: '#6366f1', closed: '#10b981', cancelled: '#fb7185' };

// Короткий формат для оси диаграммы: 1.2М / 350К.
const fmtShort = (v) => {
    const n = Math.abs(v ?? 0);
    if (n >= 1e6) return (v / 1e6).toFixed(1).replace(/\.0$/, '') + tr('М');
    if (n >= 1e3) return Math.round(v / 1e3) + tr('К');
    return String(Math.round(v ?? 0));
};

// «Реальное время»: часы + автообновление данных раз в 60с (фоновая вкладка
// не дёргает сервер), LIVE-чип показывает время последнего обновления.
const clock = ref('');
const lastUpdated = ref(new Date());
let clockTimer = null, refreshTimer = null;
const tick = () => (clock.value = new Date().toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit', second: '2-digit' }));
onMounted(() => {
    tick();
    clockTimer = setInterval(tick, 1000);
    refreshTimer = setInterval(() => {
        if (document.hidden) return;
        router.reload({ preserveScroll: true, onSuccess: () => (lastUpdated.value = new Date()) });
    }, 60000);
    requestAnimationFrame(() => (drawn.value = true));
});
onUnmounted(() => { clearInterval(clockTimer); clearInterval(refreshTimer); });
const updatedAt = computed(() => lastUpdated.value.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit', second: '2-digit' }));

// SVG-диаграмма «Доходы и расходы»: шкала с «красивым» максимумом (1/2/2.5/5),
// сетка, столбики доход/расход и линия итога (доход − расход).
const drawn = ref(false);
const hovered = ref(null);
const chart = computed(() => {
    const W = 720, H = 240, padL = 48, padR = 10, padT = 14, padB = 26;
    const innerW = W - padL - padR, innerH = H - padT - padB;
    const ms = props.monthly ?? [];
    const max = Math.max(1, ...ms.flatMap((m) => [m.income, m.expense]));
    const pow = Math.pow(10, Math.floor(Math.log10(max)));
    const niceMax = [1, 2, 2.5, 5, 10].map((k) => k * pow).find((v) => v >= max) ?? max;
    const y = (v) => padT + innerH - (Math.max(0, v) / niceMax) * innerH;
    const ticks = [0, 0.25, 0.5, 0.75, 1].map((t) => ({ v: niceMax * t, y: y(niceMax * t) }));
    const n = Math.max(1, ms.length);
    const slot = innerW / n;
    const bw = Math.min(20, slot * 0.26);
    const bars = ms.map((m, i) => {
        const cx = padL + slot * i + slot / 2;
        return {
            ...m, cx, slotX: padL + slot * i, slot,
            label: m.month.slice(5) + '.' + m.month.slice(2, 4),
            inc: { x: cx - bw - 2, y: y(m.income), h: innerH + padT - y(m.income) },
            exp: { x: cx + 2, y: y(m.expense), h: innerH + padT - y(m.expense) },
            net: (m.income ?? 0) - (m.expense ?? 0), netY: y((m.income ?? 0) - (m.expense ?? 0)),
        };
    });
    return { W, H, padL, padT, innerH, ticks, bars, bw, line: bars.map((b) => `${b.cx},${b.netY}`).join(' ') };
});
const chartTotals = computed(() => ({
    income: (props.monthly ?? []).reduce((s, m) => s + (m.income ?? 0), 0),
    expense: (props.monthly ?? []).reduce((s, m) => s + (m.expense ?? 0), 0),
}));

// Донат «Сделки по статусам».
const donut = computed(() => {
    const entries = Object.entries(props.byStatus ?? {}).filter(([, c]) => c > 0);
    const total = entries.reduce((sum, [, c]) => sum + c, 0);
    const C = 2 * Math.PI * 40;
    let acc = 0;
    const segs = entries.map(([status, cnt]) => {
        const seg = { status, cnt, color: statusColors[status] ?? '#94a3b8', dash: `${(cnt / (total || 1)) * C} ${C}`, offset: -acc * C };
        acc += cnt / (total || 1);
        return seg;
    });
    return { total, segs };
});
</script>

<template>
    <Head :title="$e('Аналитика')" />
    <AppLayout>
        <template #header>{{ $t('page.analytics', 'Аналитика') }}</template>

        <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
            <div class="inline-flex rounded-lg border border-slate-200 bg-white p-0.5">
                <button :class="tab==='general' ? 'bg-slate-900 text-white' : 'text-slate-600 hover:text-slate-900'" class="rounded-md px-4 py-1.5 text-sm font-medium transition-colors" @click="tab='general'">{{ $e('Обзор') }}</button>
                <button :class="tab==='employees' ? 'bg-slate-900 text-white' : 'text-slate-600 hover:text-slate-900'" class="rounded-md px-4 py-1.5 text-sm font-medium transition-colors" @click="tab='employees'">{{ $e('По сотрудникам') }}</button>
            </div>
            <!-- Реальное время: часы + автообновление данных раз в минуту -->
            <div class="flex items-center gap-2 rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1.5 text-xs font-medium text-emerald-700">
                <span class="relative flex h-2 w-2"><span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75"></span><span class="relative inline-flex h-2 w-2 rounded-full bg-emerald-500"></span></span>
                LIVE <span class="tabular-nums">{{ clock }}</span>
                <span class="hidden text-emerald-500/70 sm:inline">{{ $e('· данные на') }} {{ updatedAt }}</span>
            </div>
        </div>

        <!-- ============ BENTO: ОБЗОР ============ -->
        <div v-show="tab==='general'" class="space-y-4">
            <!-- Фильтры: поиск, период, менеджер, этап (серверные) -->
            <div class="flex flex-wrap items-center gap-2 rounded-xl border border-slate-100 bg-white p-3 shadow-sm">
                <div class="relative w-full sm:w-56">
                    <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
                    <input v-model="search" @input="onSearch" type="text" :placeholder="$e('Поиск: №, контрагент, договор…')"
                        class="w-full rounded-lg border-slate-200 py-1.5 pl-9 pr-3 text-sm shadow-sm transition focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20" />
                </div>
                <label class="flex items-center gap-1 text-xs text-slate-400">{{ $e('с') }}
                    <input v-model="from" @change="apply()" type="date" class="rounded-lg border-slate-200 py-1.5 text-xs shadow-sm" />
                </label>
                <label class="flex items-center gap-1 text-xs text-slate-400">{{ $e('по') }}
                    <input v-model="to" @change="apply()" type="date" class="rounded-lg border-slate-200 py-1.5 text-xs shadow-sm" />
                </label>
                <select v-model="manager" @change="apply()" class="w-full rounded-lg border-slate-200 py-1.5 text-sm text-slate-600 shadow-sm sm:w-auto">
                    <option value="">{{ $e('Все менеджеры') }}</option>
                    <option v-for="m in managers" :key="m.id" :value="m.id">{{ m.name }}</option>
                </select>
                <select v-model="stageF" @change="apply()" class="w-full rounded-lg border-slate-200 py-1.5 text-sm text-slate-600 shadow-sm sm:w-auto">
                    <option value="">{{ $e('Все этапы') }}</option>
                    <option v-for="s in stageOptions" :key="s.id" :value="s.id">{{ s.name }}</option>
                </select>
                <button v-if="hasFilters || from !== filters.from || to !== filters.to" @click="resetFilters"
                    class="rounded-lg px-2.5 py-1.5 text-xs font-medium text-slate-400 transition hover:bg-slate-100 hover:text-slate-600">{{ $e('Сбросить ✕') }}</button>
                <span class="ml-auto hidden text-[11px] text-slate-300 sm:block">{{ $e('фильтры действуют на воронку, «за период» и топ менеджеров') }}</span>
            </div>

            <!-- Суммы в две колонки: слева договоры/расходы/прибыль, справа оплачено/налог/ЗП.
                 Каждая строка кликабельна — переход в раздел. -->
            <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
                <div v-for="col in [
                        { grad: 'bg-gradient-to-br from-indigo-50/80 via-white to-white border-indigo-100', rows: [
                            { label: $e('Общая сумма договоров'), value: totals.contracts, accent: 'text-slate-900', sub: $e('все сделки (кроме отменённых) · won: ') + money(totals.budget), href: route('finance.index') },
                            { label: $e('Расходы'), value: totals.expense, accent: 'text-rose-600', minus: true, sub: $e('подтверждённые'), href: route('finance.index', { exp_status: 'confirmed' }) },
                            { label: $e('Чистая прибыль'), value: totals.net, accent: 'text-emerald-600', sub: $e('по won-сделкам · полная — в «Деньгах компании» ниже'), href: route('finance.index') },
                        ] },
                        { grad: 'bg-gradient-to-br from-emerald-50/80 via-white to-white border-emerald-100', rows: [
                            { label: $e('Оплачено'), value: totals.income, accent: 'text-emerald-600', sub: $e('фактически поступило'), href: route('finance.index') },
                            { label: $e('Налог'), value: totals.tax, accent: 'text-rose-500', minus: true, sub: $e('ставка ') + totals.taxRate + '%', href: route('finance.index') },
                            { label: $e('ЗП (бонусы)'), value: totals.bonus, accent: 'text-slate-900', sub: $e('бонусы менеджеров'), href: route('payroll.index') },
                        ] },
                    ]" :key="col.rows[0].label"
                    class="divide-y divide-slate-100 rounded-xl border shadow-sm" :class="col.grad">
                    <Link v-for="r in col.rows" :key="r.label" :href="r.href"
                        class="flex items-center justify-between gap-3 px-5 py-3.5 transition first:rounded-t-xl last:rounded-b-xl hover:bg-white/60">
                        <div>
                            <div class="text-sm font-medium text-slate-700">{{ r.label }}</div>
                            <div class="mt-0.5 text-xs text-slate-400">{{ r.sub }}</div>
                        </div>
                        <div class="text-right text-xl font-semibold tracking-tight tabular-nums" :class="r.accent">{{ r.minus ? '−' : '' }}{{ money(r.value) }}</div>
                    </Link>
                </div>
            </div>

            <!-- Деньги компании: касса, банк, все расходы с разбивкой (как на Финансах) -->
            <div class="grid grid-cols-2 items-start gap-4 xl:grid-cols-4">
                <Link :href="route('finance.index')" class="block rounded-xl bg-gradient-to-br from-emerald-500 to-teal-600 p-4 text-white shadow-md transition hover:shadow-lg hover:brightness-110">
                    <span class="text-xs font-medium text-emerald-50/90">{{ $e('Остаток в кассе') }}</span>
                    <div class="mt-1.5 whitespace-nowrap text-2xl font-semibold tracking-tight tabular-nums">{{ money(companyMoney.cash) }}</div>
                    <div class="mt-1 text-xs text-emerald-50/70">{{ $e('наличные ОБЩИЕ по холдингу') }}</div>
                </Link>
                <Link :href="route('finance.index')" class="block rounded-xl bg-gradient-to-br from-sky-500 to-indigo-600 p-4 text-white shadow-md transition hover:shadow-lg hover:brightness-110">
                    <span class="text-xs font-medium text-sky-50/90">{{ $e('Остаток в банке') }}</span>
                    <div class="mt-1.5 whitespace-nowrap text-2xl font-semibold tracking-tight tabular-nums">{{ money(companyMoney.bank) }}</div>
                    <div class="mt-1 text-xs text-sky-50/70">{{ $e('безнал: поступило − потрачено') }}</div>
                </Link>
                <div class="col-span-2 rounded-xl border border-rose-100 bg-gradient-to-br from-rose-50/80 via-white to-orange-50/60 p-4 shadow-sm">
                    <div class="flex items-baseline justify-between">
                        <span class="text-xs font-medium text-slate-500">{{ $e('Все расходы компании') }}</span>
                        <span class="text-xl font-semibold tabular-nums text-rose-600">−{{ money(companyMoney.expensesTotal) }}</span>
                    </div>
                    <div class="mt-2 space-y-1 text-sm">
                        <div class="flex justify-between"><span class="text-slate-500">{{ $e('Зарплата (оклады + бонусы)') }}</span><span class="tabular-nums text-slate-700">{{ money(companyMoney.payroll) }}</span></div>
                        <div class="flex justify-between"><span class="text-slate-500">{{ $e('Налог') }}</span><span class="tabular-nums text-slate-700">{{ money(companyMoney.tax) }}</span></div>
                        <div class="flex justify-between"><span class="text-slate-500">{{ $e('По сделкам и цеху') }}</span><span class="tabular-nums text-slate-700">{{ money(companyMoney.dealExpenses) }}</span></div>
                        <!-- Разбивка по видам: склад / доставка / закуп / прочие -->
                        <div v-if="companyMoney.dealSplit" class="space-y-0.5 pl-4 text-xs">
                            <div class="flex justify-between"><span class="text-slate-400">{{ $e('— склад (материалы)') }}</span><span class="tabular-nums text-slate-500">{{ money(companyMoney.dealSplit.material) }}</span></div>
                            <div class="flex justify-between"><span class="text-slate-400">{{ $e('— 🚚 доставка') }}</span><span class="tabular-nums text-sky-600">{{ money(companyMoney.dealSplit.delivery) }}</span></div>
                            <div class="flex justify-between"><span class="text-slate-400">{{ $e('— 📦 закуп') }}</span><span class="tabular-nums text-amber-600">{{ money(companyMoney.dealSplit.purchase) }}</span></div>
                            <div class="flex justify-between"><span class="text-slate-400">{{ $e('— 🔧 сборка') }}</span><span class="tabular-nums text-teal-600">{{ money(companyMoney.dealSplit.assembly) }}</span></div>
                            <div class="flex justify-between"><span class="text-slate-400">{{ $e('— прочие') }}</span><span class="tabular-nums text-slate-500">{{ money(companyMoney.dealSplit.other) }}</span></div>
                        </div>
                        <div v-for="c in companyMoney.categories" :key="c.name" class="flex justify-between">
                            <span class="text-slate-500">{{ c.name }}</span><span class="tabular-nums text-slate-700">{{ money(c.sum) }}</span>
                        </div>
                        <div class="flex justify-between border-t border-slate-100 pt-1.5">
                            <span class="font-medium text-slate-600">{{ $e('Доход (счета + поступления)') }}</span>
                            <span class="font-semibold tabular-nums text-emerald-600">{{ money(companyMoney.income) }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="font-medium text-slate-600">{{ $e('Чистая прибыль (доход − все расходы)') }}</span>
                            <span class="font-semibold tabular-nums" :class="companyMoney.net >= 0 ? 'text-slate-900' : 'text-rose-600'">{{ money(companyMoney.net) }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Долг клиентов · За период · Конверсия -->
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <Link :href="route('finance.index')" class="block rounded-xl border p-4 shadow-sm transition hover:shadow-md"
                    :class="totals.debt > 0 ? 'border-rose-200 bg-gradient-to-br from-rose-50 to-pink-50 hover:border-rose-300' : 'border-slate-200 bg-gradient-to-br from-slate-50 to-white hover:border-slate-300'">
                    <span class="text-xs font-medium" :class="totals.debt > 0 ? 'text-rose-500' : 'text-slate-500'">{{ $e('Долг клиентов') }}</span>
                    <div class="mt-1.5 text-2xl font-semibold tracking-tight tabular-nums" :class="totals.debt > 0 ? 'text-rose-600' : 'text-slate-900'">{{ money(totals.debt) }}</div>
                    <div class="mt-1 text-xs" :class="totals.debt > 0 ? 'text-rose-400' : 'text-slate-400'">{{ $e('по выставленным счетам') }}</div>
                </Link>
                <Link :href="route('finance.index', { exp_from: filters.from, exp_to: filters.to })"
                    class="block rounded-xl border border-violet-100 bg-gradient-to-br from-violet-50/80 to-white p-4 shadow-sm transition hover:border-violet-200 hover:shadow-md">
                    <span class="text-xs font-medium text-slate-500">{{ $e('За период') }} <span class="font-normal text-slate-300">{{ filters.from }} — {{ filters.to }}</span></span>
                    <div class="mt-2 space-y-1 text-sm">
                        <div class="flex justify-between"><span class="text-slate-400">{{ $e('Оплачено') }}</span><b class="tabular-nums text-emerald-600">{{ money(period.paid) }}</b></div>
                        <div class="flex justify-between"><span class="text-slate-400">{{ $e('Расходы') }}</span><b class="tabular-nums text-rose-600">−{{ money(period.expenses) }}</b></div>
                        <div class="flex justify-between"><span class="text-slate-400">{{ $e('Новых сделок') }}</span><b class="tabular-nums text-slate-800">{{ period.newDeals }}</b></div>
                    </div>
                </Link>
                <div class="rounded-xl border border-amber-100 bg-gradient-to-br from-amber-50/80 to-white p-4 shadow-sm">
                    <span class="text-xs font-medium text-slate-500">{{ $e('Конверсия в «Оплата успешно»') }}</span>
                    <div class="mt-1 flex items-center justify-center">
                        <svg viewBox="0 0 120 68" class="w-40">
                            <path d="M 10 60 A 50 50 0 0 1 110 60" fill="none" stroke="#f1f5f9" stroke-width="11" stroke-linecap="round"/>
                            <path d="M 10 60 A 50 50 0 0 1 110 60" fill="none" :stroke="conversion.rate >= 50 ? '#10b981' : conversion.rate >= 25 ? '#f59e0b' : '#fb7185'"
                                stroke-width="11" stroke-linecap="round" stroke-dasharray="157"
                                :stroke-dashoffset="drawn ? 157 - conversion.rate / 100 * 157 : 157" style="transition: stroke-dashoffset 1s cubic-bezier(.2,.8,.2,1)"/>
                            <text x="60" y="52" text-anchor="middle" class="fill-slate-900" style="font-size: 19px; font-weight: 700">{{ conversion.rate }}%</text>
                        </svg>
                    </div>
                    <div class="text-center text-xs text-slate-400">{{ conversion.won }} {{ $e('успешных из') }} {{ conversion.total }} {{ $e('сделок') }}</div>
                </div>
            </div>

            <!-- Требует внимания (клик — в раздел) -->
            <div class="grid grid-cols-2 gap-4 xl:grid-cols-4">
                <Link :href="route('deals.overdue')" class="rounded-xl border p-4 shadow-sm transition hover:shadow-md" :class="attention.overdueDeals > 0 ? 'border-rose-200 bg-rose-50' : 'border-slate-200 bg-white'">
                    <div class="text-xs font-medium" :class="attention.overdueDeals > 0 ? 'text-rose-500' : 'text-slate-400'">{{ $e('⚠️ Просроченные сделки') }}</div>
                    <div class="mt-1 text-2xl font-bold tabular-nums" :class="attention.overdueDeals > 0 ? 'text-rose-600' : 'text-slate-900'">{{ attention.overdueDeals }}</div>
                </Link>
                <Link :href="route('deals.index')" class="rounded-xl border p-4 shadow-sm transition hover:shadow-md" :class="attention.overdueTasks > 0 ? 'border-amber-200 bg-amber-50' : 'border-slate-200 bg-white'">
                    <div class="text-xs font-medium" :class="attention.overdueTasks > 0 ? 'text-amber-600' : 'text-slate-400'">{{ $e('Просроченные задачи') }}</div>
                    <div class="mt-1 text-2xl font-bold tabular-nums" :class="attention.overdueTasks > 0 ? 'text-amber-600' : 'text-slate-900'">{{ attention.overdueTasks }}</div>
                </Link>
                <Link :href="route('finance.index', { exp_status: 'pending' })" class="rounded-xl border p-4 shadow-sm transition hover:shadow-md" :class="attention.pendingExpenses.count > 0 ? 'border-amber-200 bg-amber-50' : 'border-slate-200 bg-white'">
                    <div class="text-xs font-medium" :class="attention.pendingExpenses.count > 0 ? 'text-amber-600' : 'text-slate-400'">{{ $e('Расходы ждут бухгалтера (') }}{{ attention.pendingExpenses.count }})</div>
                    <div class="mt-1 text-2xl font-bold tabular-nums" :class="attention.pendingExpenses.count > 0 ? 'text-amber-600' : 'text-slate-900'">{{ money(attention.pendingExpenses.sum) }}</div>
                </Link>
                <Link :href="route('warehouse.index')" class="rounded-xl border p-4 shadow-sm transition hover:shadow-md" :class="attention.zeroMaterials > 0 ? 'border-rose-200 bg-rose-50' : 'border-slate-200 bg-white'">
                    <div class="text-xs font-medium" :class="attention.zeroMaterials > 0 ? 'text-rose-500' : 'text-slate-400'">{{ $e('Материалы на нуле') }}</div>
                    <div class="mt-1 text-2xl font-bold tabular-nums" :class="attention.zeroMaterials > 0 ? 'text-rose-600' : 'text-slate-900'">{{ attention.zeroMaterials }}</div>
                </Link>
            </div>

            <!-- Bento cells -->
            <div class="grid grid-cols-1 gap-4 lg:grid-cols-6">
                <!-- Monthly (wide) -->
                <div class="rounded-xl border border-slate-100 bg-white p-5 shadow-sm lg:col-span-4">
                    <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
                        <h3 class="text-sm font-semibold text-slate-900">{{ $e('Доходы и расходы по месяцам') }}</h3>
                        <div class="inline-flex rounded-lg border border-slate-200 bg-slate-50 p-0.5 text-xs">
                            <button v-for="m in [3, 6, 12]" :key="m" @click="setMonths(m)"
                                :class="monthsFilter === m ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-900'"
                                class="rounded-md px-2.5 py-1 font-medium transition-colors">{{ m }} {{ $e('мес') }}</button>
                        </div>
                    </div>
                    <!-- Наведение на месяц — детали; иначе итоги периода -->
                    <div class="mb-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs">
                        <template v-if="hovered !== null && chart.bars[hovered]">
                            <span class="font-semibold text-slate-700">{{ chart.bars[hovered].label }}</span>
                            <span class="text-emerald-600">{{ $e('доход') }} <b class="tabular-nums">{{ money(chart.bars[hovered].income) }}</b></span>
                            <span class="text-rose-500">{{ $e('расход') }} <b class="tabular-nums">{{ money(chart.bars[hovered].expense) }}</b></span>
                            <span :class="chart.bars[hovered].net >= 0 ? 'text-indigo-600' : 'text-rose-600'">{{ $e('итог') }} <b class="tabular-nums">{{ money(chart.bars[hovered].net) }}</b></span>
                        </template>
                        <template v-else>
                            <span class="text-slate-400">{{ $e('за период:') }}</span>
                            <span class="text-emerald-600">{{ $e('доход') }} <b class="tabular-nums">{{ money(chartTotals.income) }}</b></span>
                            <span class="text-rose-500">{{ $e('расход') }} <b class="tabular-nums">{{ money(chartTotals.expense) }}</b></span>
                            <span :class="chartTotals.income - chartTotals.expense >= 0 ? 'text-indigo-600' : 'text-rose-600'">{{ $e('итог') }} <b class="tabular-nums">{{ money(chartTotals.income - chartTotals.expense) }}</b></span>
                        </template>
                    </div>
                    <svg :viewBox="`0 0 ${chart.W} ${chart.H}`" class="w-full select-none" @mouseleave="hovered = null">
                        <defs>
                            <linearGradient id="gInc" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#10b981"/><stop offset="100%" stop-color="#6ee7b7"/></linearGradient>
                            <linearGradient id="gExp" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#fb7185"/><stop offset="100%" stop-color="#fecdd3"/></linearGradient>
                        </defs>
                        <!-- Шкала и сетка -->
                        <g v-for="t in chart.ticks" :key="t.v">
                            <line :x1="chart.padL" :x2="chart.W - 10" :y1="t.y" :y2="t.y" stroke="#e2e8f0" stroke-width="1" :stroke-dasharray="t.v ? '3 4' : ''"/>
                            <text :x="chart.padL - 6" :y="t.y + 3" text-anchor="end" class="fill-slate-400" style="font-size: 10px">{{ fmtShort(t.v) }}</text>
                        </g>
                        <!-- Столбики -->
                        <g v-for="(b, i) in chart.bars" :key="b.month" :opacity="hovered === null || hovered === i ? 1 : 0.35" style="transition: opacity .2s">
                            <rect :x="b.inc.x" :width="chart.bw" rx="3" fill="url(#gInc)"
                                :y="drawn ? b.inc.y : chart.padT + chart.innerH" :height="drawn ? Math.max(2, b.inc.h) : 2"
                                style="transition: y .7s cubic-bezier(.2,.8,.2,1), height .7s cubic-bezier(.2,.8,.2,1)"/>
                            <rect :x="b.exp.x" :width="chart.bw" rx="3" fill="url(#gExp)"
                                :y="drawn ? b.exp.y : chart.padT + chart.innerH" :height="drawn ? Math.max(2, b.exp.h) : 2"
                                style="transition: y .7s cubic-bezier(.2,.8,.2,1), height .7s cubic-bezier(.2,.8,.2,1)"/>
                            <text :x="b.cx" :y="chart.H - 8" text-anchor="middle" class="fill-slate-400" style="font-size: 10px">{{ b.label }}</text>
                            <rect :x="b.slotX" :y="chart.padT" :width="b.slot" :height="chart.innerH" fill="transparent" @mouseenter="hovered = i"/>
                        </g>
                        <!-- Линия итога (доход − расход) -->
                        <polyline :points="chart.line" fill="none" stroke="#6366f1" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" :opacity="drawn ? 1 : 0" style="transition: opacity .9s .3s"/>
                        <circle v-for="(b, i) in chart.bars" :key="'d'+b.month" :cx="b.cx" :cy="b.netY" :r="hovered === i ? 4.5 : 3" fill="#6366f1" stroke="#fff" stroke-width="1.5" style="transition: r .15s"/>
                    </svg>
                    <div class="mt-2 flex gap-4 text-xs text-slate-500">
                        <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-sm bg-emerald-500"></span> {{ $e('Доход') }}</span>
                        <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-sm bg-rose-400"></span> {{ $e('Расход') }}</span>
                        <span class="flex items-center gap-1.5"><span class="h-0.5 w-3 rounded bg-indigo-500"></span> {{ $e('Итог (доход − расход)') }}</span>
                    </div>
                </div>

                <!-- Deals by status -->
                <div class="rounded-xl border border-slate-100 bg-white p-5 shadow-sm lg:col-span-2">
                    <h3 class="mb-3 text-sm font-semibold text-slate-900">{{ $e('Сделки по статусам') }}</h3>
                    <div class="flex items-center gap-4">
                        <svg viewBox="0 0 100 100" class="h-28 w-28 flex-shrink-0 -rotate-90">
                            <circle cx="50" cy="50" r="40" fill="none" stroke="#f1f5f9" stroke-width="13"/>
                            <circle v-for="seg in donut.segs" :key="seg.status" cx="50" cy="50" r="40" fill="none"
                                :stroke="seg.color" stroke-width="13" :stroke-dasharray="seg.dash" :stroke-dashoffset="seg.offset"
                                stroke-linecap="butt" style="transition: stroke-dasharray .8s ease"/>
                            <text x="50" y="50" text-anchor="middle" transform="rotate(90 50 50)" dy="5" class="fill-slate-900" style="font-size: 20px; font-weight: 700">{{ donut.total }}</text>
                        </svg>
                        <div class="min-w-0 flex-1 space-y-1">
                            <div v-for="seg in donut.segs" :key="seg.status" class="flex items-center justify-between rounded-lg px-2 py-1 text-sm hover:bg-slate-50">
                                <span class="flex items-center gap-2 text-slate-600"><span class="h-2.5 w-2.5 rounded-full" :style="{ backgroundColor: seg.color }"></span>{{ statusLabels[seg.status] ?? seg.status }}</span>
                                <span class="font-semibold tabular-nums text-slate-900">{{ seg.cnt }}</span>
                            </div>
                            <div v-if="!donut.total" class="py-4 text-center text-sm text-slate-400">{{ $e('Нет данных') }}</div>
                        </div>
                    </div>
                </div>

                <!-- Funnel: активные сделки по этапам (перенесено с Дашборда) -->
                <div class="rounded-xl border border-slate-100 bg-white p-5 shadow-sm lg:col-span-3">
                    <div class="mb-4 flex items-center justify-between">
                        <h3 class="text-sm font-semibold text-slate-900">{{ $e('Воронка — активные сделки по этапам') }}</h3>
                        <Link :href="route('deals.index')" class="text-xs font-medium text-indigo-600 hover:text-indigo-700">{{ $e('Канбан →') }}</Link>
                    </div>
                    <div class="space-y-3">
                        <Link v-for="f in funnel" :key="f.name" :href="route('deals.index')" class="block rounded-lg px-1 py-0.5 transition hover:bg-slate-50">
                            <div class="mb-1 flex justify-between text-xs"><span class="text-slate-600">{{ f.name }}</span><span class="tabular-nums text-slate-400">{{ f.count }} · {{ money(f.total) }}</span></div>
                            <div class="h-2.5 overflow-hidden rounded-full bg-slate-100"><div class="h-2.5 rounded-full" :style="{ width: (drawn ? Math.max(2, f.count / maxFunnel * 100) : 0) + '%', backgroundColor: f.color, transition: 'width .8s cubic-bezier(.2,.8,.2,1)' }"></div></div>
                        </Link>
                        <div v-if="!funnel.length" class="py-4 text-center text-sm text-slate-400">{{ $e('Нет этапов') }}</div>
                    </div>
                </div>

                <!-- Топ менеджеров за период (клик — сделки менеджера) -->
                <div class="rounded-xl border border-slate-100 bg-white p-5 shadow-sm lg:col-span-3">
                    <h3 class="mb-3 text-sm font-semibold text-slate-900">{{ $e('Топ менеджеров') }} <span class="font-normal text-slate-400">{{ $e('за период') }}</span></h3>
                    <div class="space-y-2">
                        <Link v-for="(m, i) in topManagers" :key="m.uid" :href="route('deals.index', { responsible: m.uid })"
                            class="flex items-center justify-between rounded-lg bg-slate-50 px-3 py-2 transition hover:bg-indigo-50/60">
                            <div class="flex items-center gap-2 text-sm"><span class="text-xs font-bold text-slate-300">{{ i + 1 }}</span><span class="font-medium text-slate-800">{{ m.user }}</span></div>
                            <div class="text-right">
                                <div class="text-sm font-bold tabular-nums text-slate-800">{{ money(m.budget) }}</div>
                                <div class="text-[11px] text-slate-400">{{ m.deals }} {{ $e('сделок') }}</div>
                            </div>
                        </Link>
                        <div v-if="!topManagers.length" class="py-6 text-center text-sm text-slate-400">{{ $e('Нет сделок за период') }}</div>
                    </div>
                </div>

                <!-- ABC summary -->
                <div class="rounded-xl border border-slate-100 bg-white p-5 shadow-sm lg:col-span-6">
                    <h3 class="mb-1 text-sm font-semibold text-slate-900">{{ $e('ABC-анализ') }}</h3>
                    <p class="mb-3 text-xs text-slate-400">{{ $e('По фактическому доходу: A ≈ 30%, B ≈ 20%, C ≈ 10%') }}</p>
                    <div class="grid grid-cols-3 gap-2">
                        <div v-for="(s, cls) in abcSummary" :key="cls" class="rounded-lg border border-slate-100 bg-slate-50 p-3">
                            <div class="flex items-center gap-1.5"><span class="flex h-5 w-5 items-center justify-center rounded-md text-[10px] font-bold text-white" :class="{ A: 'bg-emerald-600', B: 'bg-amber-500', C: 'bg-slate-400' }[cls]">{{ cls }}</span><span class="text-xs text-slate-500">{{ s.count }} {{ $e('сд.') }}</span></div>
                            <div class="mt-1.5 text-sm font-semibold tabular-nums text-slate-900">{{ money(s.value) }}</div>
                        </div>
                    </div>
                </div>

                <!-- ABC table (full width) -->
                <div class="overflow-hidden rounded-xl border border-slate-100 bg-white shadow-sm lg:col-span-6">
                    <div class="border-b border-slate-100 px-5 py-3 text-sm font-semibold text-slate-900">{{ $e('Топ сделок по доходу') }}</div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-400">
                                <tr><th class="px-5 py-2.5">{{ $e('Класс') }}</th><th class="px-5 py-2.5">{{ $e('Сделка') }}</th><th class="px-5 py-2.5">{{ $e('Доход') }}</th><th class="px-5 py-2.5">{{ $e('Доля') }}</th><th class="px-5 py-2.5">{{ $e('Накопл.') }}</th></tr>
                            </thead>
                            <tbody class="divide-y divide-slate-50">
                                <tr v-for="row in abc" :key="row.number" class="hover:bg-slate-50">
                                    <td class="px-5 py-2.5"><span class="flex h-5 w-5 items-center justify-center rounded-md text-[10px] font-bold text-white" :class="{ A: 'bg-emerald-600', B: 'bg-amber-500', C: 'bg-slate-400' }[row.class]">{{ row.class }}</span></td>
                                    <td class="px-5 py-2.5"><span class="text-slate-400">{{ row.number }}</span> <span class="text-slate-700">{{ row.name }}</span></td>
                                    <td class="px-5 py-2.5 font-medium tabular-nums text-slate-900">{{ money(row.value) }}</td>
                                    <td class="px-5 py-2.5 tabular-nums text-slate-500">{{ row.share }}%</td>
                                    <td class="px-5 py-2.5 tabular-nums text-slate-400">{{ row.cumulative }}%</td>
                                </tr>
                                <tr v-if="!abc.length"><td colspan="5" class="px-5 py-8 text-center text-slate-400">{{ $e('Пока нет оплат') }}</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- ============ ПО СОТРУДНИКАМ ============ -->
        <div v-show="tab==='employees'" class="grid grid-cols-1 gap-4 lg:grid-cols-3">
            <!-- Employee list -->
            <div class="space-y-2 lg:col-span-1">
                <div class="relative">
                    <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
                    <input v-model="empSearch" type="text" :placeholder="$e('Фильтр по сотруднику…')"
                        class="w-full rounded-xl border-slate-200 py-2 pl-9 pr-3 text-sm shadow-sm transition focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20" />
                </div>
                <button v-for="e in filteredEmployees" :key="e.uid" @click="selected = e"
                    :class="selected && selected.uid===e.uid
                        ? 'border-indigo-400 bg-gradient-to-br from-indigo-50 to-white ring-2 ring-indigo-400/60 shadow-md'
                        : 'border-slate-200 bg-white hover:-translate-y-0.5 hover:border-indigo-200 hover:shadow-md'"
                    class="group w-full rounded-2xl border p-4 text-left shadow-sm transition-all">
                    <div class="flex items-center gap-3">
                        <div class="relative shrink-0">
                            <Avatar :name="e.user" :src="e.avatar" :size="44" />
                            <!-- Место в рейтинге: 👑 лидер, 🥈🥉, дальше номер -->
                            <span class="absolute -right-1.5 -top-1.5 flex h-5 min-w-5 items-center justify-center rounded-full bg-white px-1 text-[10px] font-bold shadow ring-1 ring-slate-200"
                                :class="rankOf(e.uid) <= 3 ? '' : 'text-slate-400'">{{ rankBadge(rankOf(e.uid)) }}</span>
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="truncate font-semibold text-slate-900">{{ e.user }}</div>
                            <div class="mt-0.5 flex items-center gap-1.5">
                                <span class="rounded-md px-1.5 py-0.5 text-[10px] font-semibold ring-1 ring-inset" :class="marginClass(e.margin)">{{ $e('маржа') }} {{ e.margin }}%</span>
                                <span class="text-[11px] text-slate-400">{{ $e('успешных') }} {{ e.closed }}</span>
                            </div>
                        </div>
                        <div class="text-right">
                            <div class="text-sm font-bold tabular-nums text-emerald-600">{{ money(e.bonus) }}</div>
                            <div class="text-[10px] uppercase tracking-wide text-slate-400">{{ $e('ЗП') }}</div>
                        </div>
                    </div>
                    <!-- Шкала ЗП относительно лидера — видно разрыв с первым местом -->
                    <div class="mt-2.5 h-1.5 overflow-hidden rounded-full bg-slate-100">
                        <div class="h-full rounded-full bg-gradient-to-r from-emerald-400 to-emerald-600 transition-all duration-700"
                            :style="{ width: Math.max(3, Math.round((Number(e.bonus) || 0) / maxBonus * 100)) + '%' }"></div>
                    </div>
                </button>
                <div v-if="!filteredEmployees.length" class="rounded-2xl border border-slate-100 bg-white p-6 text-center text-sm text-slate-400 shadow-sm">{{ byEmployee.length ? $e('Никто не найден') : $e('Нет данных') }}</div>
            </div>

            <!-- Detail -->
            <div v-if="selected" class="space-y-4 lg:col-span-2">
                <div class="overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-sm">
                    <!-- Герой-шапка: тёмный градиент, аватар с кольцом, место в рейтинге -->
                    <div class="flex items-center gap-4 bg-gradient-to-r from-slate-900 via-indigo-950 to-slate-900 px-5 py-4">
                        <div class="rounded-full p-0.5 ring-2 ring-indigo-400/70">
                            <Avatar :name="selected.user" :src="selected.avatar" :size="52" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="truncate text-lg font-bold text-white">{{ selected.user }}</div>
                            <div class="mt-0.5 flex flex-wrap items-center gap-1.5 text-[11px]">
                                <span class="rounded-full bg-white/10 px-2 py-0.5 font-semibold text-indigo-200 ring-1 ring-white/20">{{ rankBadge(rankOf(selected.uid)) }} {{ $e('место в рейтинге') }}</span>
                                <span class="rounded-full bg-white/10 px-2 py-0.5 font-semibold text-emerald-300 ring-1 ring-white/20">{{ $e('маржа') }} {{ selected.margin }}%</span>
                                <span class="rounded-full bg-white/10 px-2 py-0.5 font-semibold text-white/80 ring-1 ring-white/20">{{ $e('успешных:') }} {{ selected.closed }}</span>
                            </div>
                        </div>
                        <div class="hidden text-right sm:block">
                            <div class="text-[10px] font-semibold uppercase tracking-wide text-white/50">{{ $e('ЗП (бонус)') }}</div>
                            <div class="text-xl font-bold tabular-nums text-emerald-300">{{ money(selected.bonus) }}</div>
                        </div>
                    </div>
                    <!-- Плитки-показатели: каждая в своей мягкой гамме -->
                    <div class="grid grid-cols-2 gap-3 p-5 sm:grid-cols-3">
                        <div v-for="k in [
                                { l: $e('Доход'), v: money(selected.income), c: 'text-emerald-700', g: 'from-emerald-50 to-teal-50/60 border-emerald-100', i: '💰' },
                                { l: $e('Расход'), v: money(selected.expense), c: 'text-rose-600', g: 'from-rose-50 to-orange-50/60 border-rose-100', i: '📉' },
                                { l: $e('Чистая прибыль'), v: money(selected.net), c: 'text-indigo-700', g: 'from-indigo-50 to-violet-50/60 border-indigo-100', i: '💎' },
                                { l: $e('Налог'), v: money(selected.tax), c: 'text-amber-700', g: 'from-amber-50 to-yellow-50/60 border-amber-100', i: '🧾' },
                                { l: $e('Маржа'), v: selected.margin + '%', c: 'text-sky-700', g: 'from-sky-50 to-cyan-50/60 border-sky-100', i: '📊' },
                                { l: $e('ЗП'), v: money(selected.bonus), c: 'text-emerald-700', g: 'from-emerald-50 to-lime-50/60 border-emerald-100', i: '💵' },
                            ]" :key="k.l" class="rounded-xl border bg-gradient-to-br p-3 transition hover:shadow-sm" :class="k.g">
                            <div class="flex items-center justify-between text-[11px] uppercase tracking-wide text-slate-500">{{ k.l }} <span>{{ k.i }}</span></div>
                            <div class="mt-1 font-semibold tabular-nums" :class="k.c">{{ k.v }}</div>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                    <div v-for="col in [
                            { t: $e('Оплата успешно'), items: selected.won_deals, dot: 'bg-emerald-500', top: 'border-t-emerald-400', head: 'bg-emerald-50 text-emerald-700' },
                            { t: $e('Акт утверждение'), items: selected.act_deals, dot: 'bg-amber-500', top: 'border-t-amber-400', head: 'bg-amber-50 text-amber-700' },
                            { t: $e('Просроченные'), items: selected.overdue_deals, dot: 'bg-rose-500', top: 'border-t-rose-400', head: 'bg-rose-50 text-rose-600' },
                        ]" :key="col.t" class="rounded-2xl border border-slate-200 border-t-4 bg-white p-4 shadow-sm" :class="col.top">
                        <h4 class="mb-3 flex items-center gap-2 text-sm font-semibold text-slate-900"><span class="h-2 w-2 rounded-full" :class="col.dot"></span>{{ col.t }}
                            <span class="ml-auto rounded-full px-2 py-0.5 text-[11px] font-bold tabular-nums" :class="col.head">{{ col.items.length }}</span></h4>
                        <div class="space-y-1.5">
                            <Link v-for="d in col.items" :key="d.id" :href="route('deals.show', d.id)"
                                class="block rounded-xl border border-slate-100 px-3 py-2 text-xs transition-all hover:border-indigo-300 hover:bg-indigo-50/40 hover:shadow-sm">
                                <div class="flex items-center justify-between gap-2">
                                    <span class="min-w-0 flex-1 truncate font-medium text-slate-800">{{ d.company || d.number }}</span>
                                    <span class="flex-shrink-0 rounded-md px-1.5 py-0.5 text-[10px] font-semibold ring-1 ring-inset" :class="marginClass(d.margin)">{{ d.margin }}%</span>
                                </div>
                                <div class="mt-1 flex items-center justify-between tabular-nums">
                                    <span class="text-slate-400">{{ money(d.budget) }}<span v-if="d.overdue_days" class="font-medium text-rose-500"> · {{ d.overdue_days }} {{ $e('дн.') }}</span></span>
                                    <span class="text-slate-500">{{ $e('чист.') }} {{ money(d.net) }}</span>
                                </div>
                            </Link>
                            <div v-if="!col.items.length" class="py-3 text-center text-xs text-slate-300">—</div>
                        </div>
                    </div>
                </div>
            </div>
            <div v-else class="flex flex-col items-center justify-center rounded-2xl border border-dashed border-slate-300 bg-gradient-to-br from-slate-50 to-white p-10 text-center shadow-sm lg:col-span-2">
                <div class="mb-2 text-3xl">👥</div>
                <div class="text-sm text-slate-400">{{ $e('Выберите сотрудника слева — покажем его показатели и сделки') }}</div>
            </div>
        </div>
    </AppLayout>
</template>
