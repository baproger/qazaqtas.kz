<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useE } from '@/composables/useTranslations';

const tr = useE();

const props = defineProps({ rows: Array, totals: Object, taxRate: Number, filters: Object, managers: Array, stageOptions: Array });

const money = (v) => new Intl.NumberFormat('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(Number(v ?? 0));
const money0 = (v) => new Intl.NumberFormat('ru-RU').format(Math.round(v ?? 0)) + ' ₸';

// Серверные фильтры: поиск, период (по дате создания), менеджер, этап.
const search = ref(props.filters?.search ?? '');
const from = ref(props.filters?.from ?? '');
const to = ref(props.filters?.to ?? '');
const manager = ref(props.filters?.manager ?? '');
const stageF = ref(props.filters?.stage ?? '');
const apply = () => router.get(route('reports.deals'), {
    search: search.value || undefined, from: from.value || undefined, to: to.value || undefined,
    manager: manager.value || undefined, stage: stageF.value || undefined,
}, { preserveState: true, preserveScroll: true, replace: true });
let searchTimer = null;
const onSearch = () => { clearTimeout(searchTimer); searchTimer = setTimeout(apply, 350); };
const hasFilters = () => search.value || from.value || to.value || manager.value || stageF.value;
const reset = () => { search.value = ''; from.value = ''; to.value = ''; manager.value = ''; stageF.value = ''; apply(); };

// Выбор сотрудника: менеджеры отдела продаж — сверху и всегда развёрнуты,
// остальные — по отделам, свёрнуты (раскрываются кликом).
const pickerOpen = ref(false);
const openDepts = ref(new Set());
const salesManagers = computed(() => props.managers.filter((m) => m.is_manager));
const otherGroups = computed(() => {
    const groups = {};
    props.managers.filter((m) => !m.is_manager).forEach((m) => {
        const key = m.department ?? 'Без отдела';
        (groups[key] ??= []).push(m);
    });
    return Object.entries(groups).map(([name, items]) => ({ name, items }))
        .sort((a, b) => (a.name === 'Без отдела') - (b.name === 'Без отдела') || a.name.localeCompare(b.name, 'ru'));
});
const toggleDept = (name) => {
    const s = new Set(openDepts.value);
    s.has(name) ? s.delete(name) : s.add(name);
    openDepts.value = s;
};
const selectedManagerName = computed(() => props.managers.find((m) => m.id === Number(manager.value))?.name ?? tr('Все менеджеры'));
const pickManager = (id) => { manager.value = id; pickerOpen.value = false; apply(); };
const closePicker = (e) => { if (!e.target.closest?.('.manager-picker')) pickerOpen.value = false; };
onMounted(() => document.addEventListener('click', closePicker));
onUnmounted(() => document.removeEventListener('click', closePicker));

const openDeal = (id) => router.get(route('deals.show', id));
// Цвет маржи — та же шкала, что на Аналитике: ≥40 здоровая, 20–40 тонкая, ниже — плохая.
const marginBadge = (m) => m >= 40 ? 'bg-emerald-50 text-emerald-700 ring-emerald-200' : m >= 20 ? 'bg-amber-50 text-amber-700 ring-amber-200' : 'bg-rose-50 text-rose-700 ring-rose-200';
const paidPct = (r) => r.budget > 0 ? Math.min(100, Math.round(r.paid / r.budget * 100)) : 0;
// ЭСФ и won просрочкой не считаются; Акт утверждение — считается.
const isOverdue = (r) => r.deadline && !r.is_won && !r.is_esf && new Date(r.deadline) < new Date(new Date().toDateString());
const fmtDate = (d) => d ? new Date(d).toLocaleDateString('ru-RU') : '—';
// Подсветка строк: won и Акт/ЭСФ — зелёный градиент (успешная / вот-вот);
// Логистика/Сборка — жёлтый; остальные — обычные.
const rowClass = (r) => (r.is_won || r.is_pending_won)
    ? 'row-won bg-gradient-to-r from-emerald-100/90 via-emerald-50/60 to-emerald-50/10 hover:from-emerald-100 hover:via-emerald-50'
    : r.is_logistics
        ? 'row-logistics bg-gradient-to-r from-amber-100/80 via-amber-50/50 to-amber-50/10 hover:from-amber-100 hover:via-amber-50'
        : 'hover:bg-slate-50';
// Доля от общей суммы договоров — как процентная строка в Excel-отчёте.
const share = (v) => props.totals.budget > 0 ? (v / props.totals.budget * 100).toFixed(1) + '%' : '—';
</script>

<template>
    <Head :title="$e('Сводный отчет')" />
    <AppLayout>
        <template #header>
            <span class="flex items-center gap-2">{{ $e('Сводный отчет') }}
                <span class="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-semibold text-slate-500">{{ totals.count }}</span>
            </span>
        </template>

        <!-- Фильтры: поиск, период, менеджер, этап (серверные) -->
        <div class="mb-4 flex flex-wrap items-center gap-2 rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
            <div class="relative w-full sm:w-60">
                <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
                <input v-model="search" @input="onSearch" type="text" :placeholder="$e('Поиск: №, компания, договор, адрес…')"
                    class="w-full rounded-lg border-slate-200 py-1.5 pl-9 pr-3 text-sm shadow-sm transition focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20" />
            </div>
            <label class="flex items-center gap-1 text-xs text-slate-400">{{ $e('с') }}
                <input v-model="from" @change="apply" type="date" class="rounded-lg border-slate-200 py-1.5 text-xs shadow-sm" />
            </label>
            <label class="flex items-center gap-1 text-xs text-slate-400">{{ $e('по') }}
                <input v-model="to" @change="apply" type="date" class="rounded-lg border-slate-200 py-1.5 text-xs shadow-sm" />
            </label>
            <!-- Выбор сотрудника: менеджеры сверху, остальные отделы — свёрнуты -->
            <div class="manager-picker relative w-full sm:w-auto">
                <button type="button" @click="pickerOpen = !pickerOpen"
                    class="flex w-full items-center justify-between gap-2 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-sm text-slate-600 shadow-sm sm:w-52">
                    <span class="truncate">{{ selectedManagerName }}</span>
                    <span class="text-slate-400">{{ pickerOpen ? '▲' : '▼' }}</span>
                </button>
                <div v-if="pickerOpen" class="absolute z-30 mt-1 max-h-80 w-64 overflow-y-auto rounded-xl border border-slate-200 bg-white py-1 shadow-lg">
                    <button type="button" @click="pickManager('')"
                        class="block w-full px-3 py-1.5 text-left text-sm hover:bg-indigo-50" :class="!manager ? 'font-semibold text-indigo-600' : 'text-slate-700'">{{ $e('Все менеджеры') }}</button>
                    <div class="mt-1 border-t border-slate-100 px-3 pb-1 pt-2 text-[10px] font-bold uppercase tracking-wide text-slate-400">{{ $e('Менеджеры (отдел продаж)') }}</div>
                    <button v-for="m in salesManagers" :key="m.id" type="button" @click="pickManager(m.id)"
                        class="block w-full px-3 py-1.5 text-left text-sm hover:bg-indigo-50"
                        :class="Number(manager) === m.id ? 'font-semibold text-indigo-600' : 'text-slate-700'">{{ m.name }}</button>
                    <div v-if="!salesManagers.length" class="px-3 py-1.5 text-xs text-slate-400">{{ $e('Нет менеджеров') }}</div>
                    <!-- Остальные отделы: свёрнуты, раскрываются кликом -->
                    <template v-for="g in otherGroups" :key="g.name">
                        <button type="button" @click="toggleDept(g.name)"
                            class="mt-1 flex w-full items-center justify-between border-t border-slate-100 px-3 pb-1 pt-2 text-[10px] font-bold uppercase tracking-wide text-slate-400 hover:text-slate-600">
                            {{ g.name }} ({{ g.items.length }})
                            <span>{{ openDepts.has(g.name) ? '▲' : '▼' }}</span>
                        </button>
                        <template v-if="openDepts.has(g.name)">
                            <button v-for="m in g.items" :key="m.id" type="button" @click="pickManager(m.id)"
                                class="block w-full px-3 py-1.5 text-left text-sm hover:bg-indigo-50"
                                :class="Number(manager) === m.id ? 'font-semibold text-indigo-600' : 'text-slate-700'">{{ m.name }}</button>
                        </template>
                    </template>
                </div>
            </div>
            <select v-model="stageF" @change="apply" class="w-full rounded-lg border-slate-200 py-1.5 text-sm text-slate-600 shadow-sm sm:w-auto">
                <option value="">{{ $e('Все этапы') }}</option>
                <option v-for="s in stageOptions" :key="s.id" :value="s.id">{{ s.name }}</option>
            </select>
            <button v-if="hasFilters()" @click="reset"
                class="rounded-lg px-2.5 py-1.5 text-xs font-medium text-slate-400 transition hover:bg-slate-100 hover:text-slate-600">{{ $e('Сбросить ✕') }}</button>
        </div>

        <!-- Итоги: те же плитки, что на Аналитике/Дашборде -->
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 xl:grid-cols-6">
            <div class="rise rounded-2xl border border-slate-200 bg-white p-4 shadow-sm" style="animation-delay: 0ms">
                <div class="text-[11px] font-medium uppercase tracking-wide text-slate-400">{{ $e('Сумма договоров') }}</div>
                <div class="mt-1.5 text-xl font-semibold tracking-tight tabular-nums text-slate-900">{{ money0(totals.budget) }}</div>
                <div class="mt-0.5 text-[11px] text-slate-400">{{ totals.count }} {{ $e('сделок · 100%') }}</div>
            </div>
            <div class="rise rounded-2xl border border-slate-200 bg-white p-4 shadow-sm" style="animation-delay: 40ms">
                <div class="text-[11px] font-medium uppercase tracking-wide text-slate-400">{{ $e('Оплачено') }}</div>
                <div class="mt-1.5 text-xl font-semibold tracking-tight tabular-nums text-emerald-600">{{ money0(totals.paid) }}</div>
                <div class="mt-0.5 text-[11px] text-slate-400">{{ share(totals.paid) }} {{ $e('от договоров') }}</div>
            </div>
            <div class="rise rounded-2xl border border-slate-200 bg-white p-4 shadow-sm" style="animation-delay: 80ms">
                <div class="text-[11px] font-medium uppercase tracking-wide text-slate-400">{{ $e('Расходы') }}</div>
                <div class="mt-1.5 text-xl font-semibold tracking-tight tabular-nums text-rose-600">−{{ money0(totals.material + (totals.delivery ?? 0) + (totals.purchase ?? 0) + (totals.assembly ?? 0) + totals.other) }}</div>
                <div class="mt-0.5 text-[11px] text-slate-400">{{ $e('склад') }} {{ money0(totals.material) }} · 🚚 {{ money0(totals.delivery ?? 0) }} · 📦 {{ money0(totals.purchase ?? 0) }} · 🔧 {{ money0(totals.assembly ?? 0) }} {{ $e('· прочие') }} {{ money0(totals.other) }}</div>
            </div>
            <div class="rise rounded-2xl border border-slate-200 bg-white p-4 shadow-sm" style="animation-delay: 120ms">
                <div class="text-[11px] font-medium uppercase tracking-wide text-slate-400">{{ $e('Налог') }} {{ taxRate }}%</div>
                <div class="mt-1.5 text-xl font-semibold tracking-tight tabular-nums text-rose-500">−{{ money0(totals.tax) }}</div>
                <div class="mt-0.5 text-[11px] text-slate-400">{{ share(totals.tax) }} {{ $e('от договоров') }}</div>
            </div>
            <div class="rise rounded-2xl border border-slate-200 bg-white p-4 shadow-sm" style="animation-delay: 160ms">
                <div class="text-[11px] font-medium uppercase tracking-wide text-slate-400">{{ $e('Бонусы менеджеров') }}</div>
                <div class="mt-1.5 text-xl font-semibold tracking-tight tabular-nums text-emerald-600">{{ money0(totals.bonus) }}</div>
                <div class="mt-0.5 text-[11px] text-slate-400">{{ share(totals.bonus) }} {{ $e('от договоров') }}</div>
            </div>
            <div class="rise rounded-2xl border border-transparent p-4 text-white shadow-md" style="animation-delay: 200ms; background-color: #1A3B5C">
                <div class="text-[11px] font-medium uppercase tracking-wide text-white/60">{{ $e('Доход') }}</div>
                <div class="mt-1.5 text-xl font-semibold tracking-tight tabular-nums">{{ money0(totals.company) }}</div>
                <div class="mt-0.5 text-[11px] text-white/60">{{ $e('маржа') }} {{ totals.margin }}%</div>
            </div>
        </div>

        <!-- Легенда подсветки строк -->
        <div class="mt-4 flex flex-wrap items-center gap-4 px-1 text-[11px] text-slate-400">
            <span class="flex items-center gap-1.5"><span class="h-3 w-6 rounded bg-gradient-to-r from-emerald-200 to-emerald-50"></span> {{ $e('Оплата успешно · Акт · ЭСФ') }}</span>
            <span class="flex items-center gap-1.5"><span class="h-3 w-6 rounded bg-gradient-to-r from-amber-200 to-amber-50"></span> {{ $e('Логистика · Сборка') }}</span>
            <span class="flex items-center gap-1.5"><span class="h-3 w-6 rounded bg-white ring-1 ring-inset ring-slate-200"></span> {{ $e('В работе') }}</span>
        </div>

        <!-- Таблица -->
        <div class="rise mt-2 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm" style="animation-delay: 240ms">
            <div class="max-h-[70vh] overflow-auto">
                <table class="min-w-full whitespace-nowrap text-xs">
                    <thead class="text-left uppercase tracking-wide text-slate-400">
                        <tr>
                            <th class="sticky top-0 z-20 border-b border-slate-100 bg-slate-50 px-4 py-3">{{ $e('Сделка') }}</th>
                            <th class="sticky top-0 z-10 border-b border-slate-100 bg-slate-50 px-4 py-3">{{ $e('Товар · кол-во') }}</th>
                            <th class="sticky top-0 z-10 border-b border-slate-100 bg-slate-50 px-4 py-3">{{ $e('Менеджер') }}</th>
                            <th class="sticky top-0 z-10 border-b border-slate-100 bg-slate-50 px-4 py-3">{{ $e('Этап · срок') }}</th>
                            <th class="sticky top-0 z-10 border-b border-slate-100 bg-slate-50 px-4 py-3 text-right">{{ $e('Сумма договора') }}</th>
                            <th class="sticky top-0 z-10 border-b border-slate-100 bg-slate-50 px-4 py-3 text-right">{{ $e('Оплачено') }}</th>
                            <th class="sticky top-0 z-10 border-b border-slate-100 bg-slate-50 px-4 py-3 text-right">{{ $e('Закуп (склад)') }}</th>
                            <th class="sticky top-0 z-10 border-b border-slate-100 bg-slate-50 px-4 py-3 text-right">{{ $e('🚚 Доставка') }}</th>
                            <th class="sticky top-0 z-10 border-b border-slate-100 bg-slate-50 px-4 py-3 text-right">{{ $e('📦 Закуп') }}</th>
                            <th class="sticky top-0 z-10 border-b border-slate-100 bg-slate-50 px-4 py-3 text-right">{{ $e('🔧 Сборка') }}</th>
                            <th class="sticky top-0 z-10 border-b border-slate-100 bg-slate-50 px-4 py-3 text-right">{{ $e('Прочие') }}</th>
                            <th class="sticky top-0 z-10 border-b border-slate-100 bg-slate-50 px-4 py-3 text-right" :title="$e('Доля партнёра: % от суммы договора')">{{ $e('Партнёр') }}</th>
                            <th class="sticky top-0 z-10 border-b border-slate-100 bg-slate-50 px-4 py-3 text-right">{{ $e('Налог') }}</th>
                            <th class="sticky top-0 z-10 border-b border-slate-100 bg-slate-50 px-4 py-3 text-right">{{ $e('Остаток') }}</th>
                            <th class="sticky top-0 z-10 border-b border-slate-100 bg-slate-50 px-4 py-3 text-center">{{ $e('Маржа') }}</th>
                            <th class="sticky top-0 z-10 border-b border-slate-100 bg-slate-50 px-4 py-3 text-right">{{ $e('Бонус') }}</th>
                            <th class="sticky top-0 z-10 border-b border-slate-100 bg-slate-50 px-4 py-3 text-right">{{ $e('Доход') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        <tr v-for="r in rows" :key="r.id" @click="openDeal(r.id)"
                            class="group cursor-pointer transition-colors" :class="rowClass(r)">
                            <!-- Сделка: организация + № / договор -->
                            <td class="max-w-64 px-4 py-3">
                                <div class="truncate text-[13px] font-semibold text-slate-800" :title="r.company_name">{{ r.company_name || '—' }}</div>
                                <div class="mt-0.5 flex items-center gap-1.5 text-[11px] text-slate-400">
                                    <span class="font-medium text-slate-500">{{ r.number }}</span>
                                    <span v-if="r.bin">· {{ r.bin }}</span>
                                </div>
                                <div v-if="r.address" class="mt-0.5 max-w-60 truncate text-[11px] text-slate-400" :title="r.address">{{ r.address }}</div>
                            </td>
                            <td class="max-w-40 px-4 py-3">
                                <div class="truncate text-slate-700" :title="r.product">{{ r.product || '—' }}</div>
                                <div v-if="r.qty" class="mt-0.5 text-[11px] text-slate-400">{{ r.qty }}</div>
                            </td>
                            <td class="max-w-36 truncate px-4 py-3 text-slate-600">{{ r.manager || '—' }}</td>
                            <td class="px-4 py-3">
                                <span v-if="r.stage" class="rounded-full px-2 py-0.5 text-[10px] font-semibold text-white" :style="{ backgroundColor: r.stage_color || '#94a3b8' }">{{ r.stage }}</span>
                                <!-- Сделка в цехе: этап цеха вторым бейджем — общая таблица, без дублей -->
                                <div v-if="r.workshop_stage" class="mt-1">
                                    <span class="rounded-full px-2 py-0.5 text-[10px] font-semibold text-white" :style="{ backgroundColor: r.workshop_color || '#f59e0b' }" :title="r.workshop_number">{{ $e('Цех ·') }} {{ r.workshop_stage }}</span>
                                </div>
                                <div class="mt-1 text-[11px]" :class="isOverdue(r) ? 'font-semibold text-rose-600' : 'text-slate-400'">
                                    {{ fmtDate(r.deadline) }}<span v-if="isOverdue(r)"> {{ $e('· просрочено') }}</span>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-right font-semibold tabular-nums text-slate-900">{{ money(r.budget) }}</td>
                            <!-- Оплачено + мини-прогресс -->
                            <td class="px-4 py-3 text-right">
                                <div class="tabular-nums" :class="paidPct(r) >= 100 ? 'font-semibold text-emerald-600' : 'text-slate-700'">{{ money(r.paid) }}</div>
                                <div class="ml-auto mt-1 h-1 w-16 overflow-hidden rounded-full bg-slate-100">
                                    <div class="h-1 rounded-full" :class="paidPct(r) >= 100 ? 'bg-emerald-500' : 'bg-indigo-400'" :style="{ width: paidPct(r) + '%' }"></div>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums text-indigo-600">{{ money(r.material) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-sky-600">{{ money(r.delivery) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-amber-600">{{ money(r.purchase) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-teal-600">{{ money(r.assembly) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-slate-600">{{ money(r.other) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-violet-600">{{ money(r.partner) }}<span v-if="r.partner_pct" class="ml-1 text-[10px] text-slate-400">{{ r.partner_pct }}%</span></td>
                            <td class="px-4 py-3 text-right tabular-nums text-rose-500">{{ money(r.tax) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums" :class="r.remainder < 0 ? 'font-semibold text-rose-600' : 'text-slate-700'">{{ money(r.remainder) }}</td>
                            <td class="px-4 py-3 text-center">
                                <span class="rounded-md px-1.5 py-0.5 text-[11px] font-semibold ring-1 ring-inset" :class="marginBadge(r.margin)">{{ r.margin }}%</span>
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums text-emerald-600">{{ money(r.bonus) }}</td>
                            <td class="px-4 py-3 text-right font-semibold tabular-nums text-slate-900">{{ money(r.company) }}</td>
                        </tr>
                        <tr v-if="!rows.length">
                            <td colspan="17" class="px-4 py-16 text-center">
                                <div class="text-3xl">📋</div>
                                <div class="mt-2 text-sm font-medium text-slate-500">{{ filters.search || filters.from || filters.to ? $e('Ничего не найдено') : $e('Сделок пока нет') }}</div>
                                <div v-if="filters.search || filters.from || filters.to" class="mt-1 text-xs text-slate-400">{{ $e('Попробуйте изменить поиск или период') }}</div>
                            </td>
                        </tr>
                    </tbody>
                    <tfoot v-if="rows.length">
                        <tr class="sticky bottom-0 z-10 bg-slate-900 font-semibold text-white">
                            <td colspan="4" class="rounded-bl-2xl px-4 py-3 text-[11px] uppercase tracking-wider text-slate-400">{{ $e('Итого ·') }} {{ totals.count }} {{ $e('сделок') }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ money(totals.budget) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-emerald-400">{{ money(totals.paid) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-indigo-300">{{ money(totals.material) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-sky-300">{{ money(totals.delivery) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-amber-300">{{ money(totals.purchase) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-teal-300">{{ money(totals.assembly) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-slate-300">{{ money(totals.other) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-violet-300">{{ money(totals.partner) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-rose-400">{{ money(totals.tax) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ money(totals.remainder) }}</td>
                            <td class="px-4 py-3 text-center"><span class="rounded-md bg-white/10 px-1.5 py-0.5 text-[11px]">{{ totals.margin }}%</span></td>
                            <td class="px-4 py-3 text-right tabular-nums text-emerald-400">{{ money(totals.bonus) }}</td>
                            <td class="rounded-br-2xl px-4 py-3 text-right tabular-nums">{{ money(totals.company) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

    </AppLayout>
</template>

<style scoped>
@keyframes rise {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}
.rise {
    opacity: 0;
    animation: rise 0.5s cubic-bezier(0.16, 1, 0.3, 1) forwards;
}
/* Линия-акцент слева: зелёная — won/Акт/ЭСФ, жёлтая — Логистика/Сборка. */
.row-won td:first-child {
    box-shadow: inset 3px 0 0 0 #10b981;
}
.row-logistics td:first-child {
    box-shadow: inset 3px 0 0 0 #f59e0b;
}
</style>
