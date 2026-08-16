<script setup>
import { ref, computed } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { formatDate, formatDateTime } from '@/utils/format';
import { confirmDialog } from '@/composables/useConfirm';
import DdsPanel from '@/Components/DdsPanel.vue';
import CompanyExpenseModal from '@/Components/CompanyExpenseModal.vue';
import ExpenseCategoriesModal from '@/Components/ExpenseCategoriesModal.vue';
import { useE } from '@/composables/useTranslations';

const tr = useE();

const props = defineProps({ invoicesToday: { type: Array, default: () => [] }, invoicesPast: { type: Array, default: () => [] }, invoicesPastStats: Object, invoiceTotals: Object, expensesToday: Array, expensesPast: Array, expensesPastStats: Object, expenseTotals: Object, filters: Object, summary: Object, categories: Array, receiptsToday: Array, receiptsPast: Array, receiptsPastStats: Object, debts: Object, canManage: Boolean, isAdmin: Boolean, dds: { type: Object, default: () => ({ accounts: [], debts: [], date: '' }) } });
const money = (v) => new Intl.NumberFormat('ru-RU').format(Math.round(v ?? 0)) + ' ₸';

// Фильтры раздела «Расходы»: вид (материалы/прочие), оплата (нал/банк),
// статус и период. Период влияет и на сводку-плитки, и на таблицу.
const expKind = ref(props.filters?.exp_kind ?? '');
const expMethod = ref(props.filters?.exp_method ?? '');
const expStatus = ref(props.filters?.exp_status ?? '');
const expFrom = ref(props.filters?.exp_from ?? '');
const expTo = ref(props.filters?.exp_to ?? '');
const applyExpFilters = () => router.get(route('finance.index'), {
    ...props.filters,
    exp_kind: expKind.value || undefined,
    exp_method: expMethod.value || undefined,
    exp_status: expStatus.value || undefined,
    exp_from: expFrom.value || undefined,
    exp_to: expTo.value || undefined,
}, { preserveState: true, preserveScroll: true, replace: true });

// Плитки сводки работают как фильтры: клик по «Наличные» фильтрует таблицу.
const setTile = (kind, method, status) => {
    expKind.value = kind; expMethod.value = method; expStatus.value = status;
    applyExpFilters();
};
const tileActive = (kind, method, status) =>
    expKind.value === kind && expMethod.value === method && expStatus.value === status;

// Ссылка на сделку/заказ расхода (морф: deal | project).
const expLink = (e) => e.expenseable_type === 'project'
    ? route('projects.show', e.expenseable_id)
    : route('deals.show', e.expenseable_id);

// Поступление денег (финансист): сумма, нал/банк, откуда, дата, комментарий.
const showReceipt = ref(false);
const rForm = useForm({ amount: '', method: 'bank', source: '', date: new Date().toISOString().slice(0, 10), note: '' });
const openReceipt = () => { rForm.reset(); rForm.date = new Date().toISOString().slice(0, 10); showReceipt.value = true; };
const submitReceipt = () => rForm.post(route('finance.receipts.store'), { preserveScroll: true, onSuccess: () => (showReceipt.value = false) });
const delReceipt = (r) => router.delete(route('finance.receipts.destroy', r.id), { preserveScroll: true });

// Прошлые поступления: аккордеон снизу, фильтр серверный (поиск + период).
const pastOpen = ref(!!(props.filters?.rc_search || props.filters?.rc_from || props.filters?.rc_to));
// Корректировка кассы (инвентаризация): финансист задаёт фактический остаток.
const cashFixOpen = ref(false);
const cashFixInput = ref('');
const openCashFix = () => { cashFixInput.value = String(props.summary?.cash ?? 0); cashFixOpen.value = true; };
const saveCashFix = () => router.post(route('finance.cashCorrection'), { actual: cashFixInput.value }, {
    preserveScroll: true, onSuccess: () => (cashFixOpen.value = false),
});

// Прошлые счета: аккордеон + поиск по номеру (параметр search).
const invPastOpen = ref(false);
const invSearch = ref(props.filters?.search ?? '');
const applyInvFilters = () => router.get(route('finance.index'),
    { ...props.filters, search: invSearch.value || undefined },
    { preserveState: true, preserveScroll: true, replace: true, onSuccess: () => (invPastOpen.value = true) });
const resetInvFilters = () => { invSearch.value = ''; applyInvFilters(); };

const rcSearch = ref(props.filters?.rc_search ?? '');
const rcFrom = ref(props.filters?.rc_from ?? '');
const rcTo = ref(props.filters?.rc_to ?? '');
const applyRcFilters = () => router.get(route('finance.index'), {
    ...props.filters,
    rc_search: rcSearch.value || undefined,
    rc_from: rcFrom.value || undefined,
    rc_to: rcTo.value || undefined,
}, { preserveState: true, preserveScroll: true, replace: true });
const resetRcFilters = () => { rcSearch.value = ''; rcFrom.value = ''; rcTo.value = ''; applyRcFilters(); };
const todaySum = computed(() => (props.receiptsToday ?? []).reduce((sum, r) => sum + Number(r.amount || 0), 0));

// Прошлые расходы: аккордеон снизу, фильтр серверный (поиск + период).
const expPastOpen = ref(!!(props.filters?.xp_search || props.filters?.xp_from || props.filters?.xp_to));
const xpSearch = ref(props.filters?.xp_search ?? '');
const xpFrom = ref(props.filters?.xp_from ?? '');
const xpTo = ref(props.filters?.xp_to ?? '');
const applyXpFilters = () => router.get(route('finance.index'), {
    ...props.filters,
    xp_search: xpSearch.value || undefined,
    xp_from: xpFrom.value || undefined,
    xp_to: xpTo.value || undefined,
}, { preserveState: true, preserveScroll: true, replace: true });
const resetXpFilters = () => { xpSearch.value = ''; xpFrom.value = ''; xpTo.value = ''; applyXpFilters(); };
const expTodaySum = computed(() => (props.expensesToday ?? []).reduce((sum, e) => sum + Number(e.amount || 0), 0));

// Категории и расход компании живут в общих компонентах: та же форма
// открывается и на рабочем месте бухгалтера.
const showCats = ref(false);
const showCompanyExpense = ref(false);

// Фильтр сводки «Доход − Расходы» по месяцу: пусто = за всё время.
const finMonth = ref(props.filters?.fin_month ?? '');
const applyFinMonth = () => router.get(route('finance.index'), {
    ...props.filters, fin_month: finMonth.value || undefined,
}, { preserveState: true, preserveScroll: true, replace: true });
const resetFinMonth = () => { finMonth.value = ''; applyFinMonth(); };
const monthActive = computed(() => !!props.filters?.fin_month);
const monthLabel = computed(() => monthActive.value
    ? new Date(props.filters.fin_month + '-01T00:00:00').toLocaleDateString('ru-RU', { month: 'long', year: 'numeric' })
    : '');

// Задолженности: дебиторка (нам должны) / кредиторка (мы должны). Аккордеоны.
const debtOpen = ref({ receivable: false, payable: false });
const showDebt = ref(false);
const debtEditing = ref(null);
const dForm = useForm({ type: 'receivable', counterparty: '', amount: '', date: '', note: '' });
const openDebt = (type, d = null) => {
    debtEditing.value = d;
    dForm.type = type;
    dForm.counterparty = d?.counterparty ?? '';
    dForm.amount = d ? Number(d.amount) : '';
    dForm.date = (d?.date ?? '').slice(0, 10);
    dForm.note = d?.note ?? '';
    dForm.clearErrors();
    showDebt.value = true;
};
const submitDebt = () => {
    const opts = { preserveScroll: true, onSuccess: () => (showDebt.value = false) };
    debtEditing.value ? dForm.put(route('finance.debts.update', debtEditing.value.id), opts) : dForm.post(route('finance.debts.store'), opts);
};
const delDebt = async (d) => {
    if (await confirmDialog({ title: tr('Удалить задолженность'), message: `«${d.counterparty}» на ${money(d.amount)} будет удалена. СЕО и директор получат уведомление.`, confirmText: tr('Удалить'), danger: true })) {
        router.delete(route('finance.debts.destroy', d.id), { preserveScroll: true });
    }
};

// Правка/удаление расхода (financist/admin). Материал/кол-во и способ оплаты
// через update не меняются (правила сервера); сумма материального — авто.
const editingExp = ref(null);
const eForm = useForm({ amount: '', date: '', description: '', category_id: '' });
const openEditExp = (e) => {
    editingExp.value = e;
    eForm.amount = Number(e.amount);
    eForm.date = (e.date ?? '').slice(0, 10);
    eForm.description = e.description ?? '';
    eForm.category_id = e.category_id ?? '';
    eForm.clearErrors();
};
const submitEditExp = () => eForm.put(route('expenses.update', editingExp.value.id), {
    preserveScroll: true, onSuccess: () => (editingExp.value = null),
});
const delExpense = async (e) => {
    if (await confirmDialog({ title: tr('Удалить расход'), message: `Расход ${money(e.amount)} будет удалён${e.material ? tr(', остаток вернётся на склад') : ''}.`, confirmText: tr('Удалить'), danger: true })) {
        router.delete(route('expenses.destroy', e.id), { preserveScroll: true });
    }
};
</script>

<template>
    <Head :title="$e('Финансы')" />
    <AppLayout>
        <template #header>{{ $t('page.finance', 'Финансы') }}</template>

        <!-- ================= ДДС: ручная сводка финансиста (первый блок) ================= -->
        <div class="mb-4">
            <DdsPanel :dds="dds" :can-manage="canManage" />
        </div>

        <!-- Верхний ряд: договоры · дебиторка · касса · банк.
             «Кредиторка (мы должны)» скрыта по просьбе владельца (24.07.2026). -->
        <div class="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="text-[11px] uppercase tracking-wide text-slate-400">{{ $e('Общая сумма договоров') }}</div>
                <div class="mt-1 text-xl font-bold tabular-nums text-slate-800">{{ money(summary.contracts) }}</div>
            </div>
            <div class="rounded-xl border p-5 shadow-sm" :class="summary.receivablesTotal > 0 ? 'border-rose-200 bg-rose-50' : 'border-slate-200 bg-white'">
                <div class="text-[11px] uppercase tracking-wide" :class="summary.receivablesTotal > 0 ? 'text-rose-500' : 'text-slate-400'">{{ $e('Дебиторка (нам должны)') }}</div>
                <div class="mt-1 text-xl font-bold tabular-nums" :class="summary.receivablesTotal > 0 ? 'text-rose-600' : 'text-slate-800'">{{ money(summary.receivablesTotal) }}</div>
                <div class="mt-0.5 text-[11px]" :class="summary.receivablesTotal > 0 ? 'text-rose-400' : 'text-slate-400'">{{ $e('счета') }} {{ money(summary.receivables) }} {{ $e('· вручную') }} {{ money(summary.receivablesManual) }}</div>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="flex items-center justify-between">
                    <div class="text-[11px] uppercase tracking-wide text-slate-400">{{ $e('Остаток в кассе') }}</div>
                    <button v-if="isAdmin" @click="openCashFix" :title="$e('Корректировка кассы (инвентаризация): задать фактический остаток')"
                        class="text-slate-300 hover:text-indigo-500">✎</button>
                </div>
                <div class="mt-1 text-xl font-bold tabular-nums" :class="summary.cash >= 0 ? 'text-slate-800' : 'text-rose-600'">{{ money(summary.cash) }}</div>
                <div class="mt-0.5 text-[11px] text-slate-400">
                    {{ $e('наличные ОБЩИЕ по всем фирмам') }}
                    <span v-if="summary.cashCorrection" class="text-amber-500" :title="$e('Корректировка: ') + money(summary.cashCorrection)">{{ $e('· скорректировано') }}</span>
                </div>
                <div v-if="cashFixOpen" class="mt-2 flex items-center gap-1.5">
                    <input v-model="cashFixInput" type="number" step="0.01" :placeholder="$e('Фактический остаток')"
                        class="w-32 rounded-lg border-slate-200 py-1 text-sm shadow-sm focus:border-indigo-400 focus:ring-indigo-400" />
                    <button @click="saveCashFix" class="rounded-lg bg-indigo-600 px-2 py-1 text-xs font-semibold text-white hover:bg-indigo-700">{{ $e('ОК') }}</button>
                    <button @click="cashFixOpen = false" class="text-slate-400 hover:text-slate-600">✕</button>
                </div>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="text-[11px] uppercase tracking-wide text-slate-400">{{ $e('Остаток в банке') }}</div>
                <div class="mt-1 text-xl font-bold tabular-nums" :class="summary.bank >= 0 ? 'text-slate-800' : 'text-rose-600'">{{ money(summary.bank) }}</div>
                <div class="mt-0.5 text-[11px] text-slate-400">{{ $e('безнал своей компании: поступило − потрачено') }}</div>
            </div>
        </div>

        <!-- Доход − ВСЕ расходы = Чистая прибыль (минимализм, как в тетради) -->
        <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
            <span v-if="monthActive" class="rounded-full bg-indigo-100 px-3 py-1 text-xs font-semibold text-indigo-700">{{ $e('Сводка за') }} {{ monthLabel }}</span>
            <span v-else class="text-xs font-medium text-slate-400">{{ $e('Сводка за всё время') }}</span>
            <div class="flex items-center gap-2">
                <span class="text-xs text-slate-400">{{ $e('Месяц:') }}</span>
                <input v-model="finMonth" @change="applyFinMonth" type="month"
                    class="rounded-lg border-slate-300 py-1.5 text-sm shadow-sm transition focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20" />
                <button v-if="monthActive" @click="resetFinMonth"
                    class="rounded-lg px-3 py-1.5 text-xs font-medium text-slate-500 transition hover:bg-slate-100">{{ $e('за всё время') }}</button>
            </div>
        </div>
        <div class="mb-6 grid grid-cols-1 gap-3 lg:grid-cols-3">
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="text-[11px] uppercase tracking-wide text-slate-400">{{ $e('Доход') }} <span class="normal-case text-slate-300">{{ $e('— итог Сводного отчёта') }}</span></div>
                <div class="mt-1 text-2xl font-bold tabular-nums text-emerald-600">{{ money(summary.dealsIncome) }}</div>
                <div class="mt-0.5 text-[11px] text-slate-400">{{ $e('по сделкам: остаток − бонус (как в отчёте)') }}{{ monthActive ? $e(' · сделки за ') + monthLabel + $e(' (по дате договора)') : '' }}</div>
                <div class="mt-2 border-t border-slate-100 pt-2 text-[11px] text-slate-400">
                    {{ $e('Оборот') }} {{ monthActive ? $e('за ') + monthLabel : $e('(движение денег)') }}: <b class="tabular-nums text-slate-600">{{ money(summary.income) }}</b>
                    {{ $e('· счета') }} {{ money(summary.incomeInvoices) }} {{ $e('· поступления') }} {{ money(summary.incomeManual) }}
                </div>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="flex items-baseline justify-between">
                    <span class="text-[11px] uppercase tracking-wide text-slate-400">{{ $e('Расходы —') }} {{ monthActive ? monthLabel : $e('всего') }}</span>
                    <span class="text-xl font-bold tabular-nums text-rose-600">−{{ money(summary.expensesTotal) }}</span>
                </div>
                <div class="mt-2 space-y-1 text-sm">
                    <div v-if="!monthActive" class="flex justify-between"><span class="text-slate-500">{{ $e('Зарплата (оклады + бонусы)') }}</span><span class="tabular-nums text-slate-700">{{ money(summary.payroll) }}</span></div>
                    <div v-if="!monthActive" class="flex justify-between"><span class="text-slate-500">{{ $e('Налог') }}</span><span class="tabular-nums text-slate-700">{{ money(summary.tax) }}</span></div>
                    <div v-if="monthActive" class="text-[11px] text-slate-400">{{ $e('ЗП и налог считаются по сделкам — видны в режиме «за всё время»') }}</div>
                    <div class="flex justify-between"><span class="text-slate-500">{{ $e('По сделкам и цеху') }}</span><span class="tabular-nums text-slate-700">{{ money(summary.dealExpenses) }}</span></div>
                    <!-- Списания со склада показаны, но в итог не входят: эти
                         деньги уже посчитаны закупом. -->
                    <div v-if="summary.materialWriteoffs" class="flex justify-between text-slate-400">
                        <span>{{ $e('Списано со склада') }} <span class="text-[11px]">{{ $e('· учтено в закупе') }}</span></span>
                        <span class="tabular-nums">{{ money(summary.materialWriteoffs) }}</span>
                    </div>
                    <div v-for="c in summary.categories" :key="c.name" class="flex justify-between"
                        :class="c.in_payroll ? 'text-slate-400' : ''">
                        <span :class="c.in_payroll ? '' : 'text-slate-500'">
                            {{ c.name }}
                            <!-- Выплаты сотрудникам уже посчитаны строкой «Зарплата». -->
                            <span v-if="c.in_payroll" class="text-[11px]">{{ $e('· учтено в строке «Зарплата»') }}</span>
                        </span>
                        <span class="tabular-nums" :class="c.in_payroll ? '' : 'text-slate-700'">{{ money(c.sum) }}</span>
                    </div>
                </div>
            </div>
            <div class="rounded-xl p-5 shadow-md" style="background-color: #1A3B5C">
                <div class="text-[11px] uppercase tracking-wide text-white/60">{{ monthActive ? $e('Итог за ') + monthLabel : $e('Чистая прибыль') }}</div>
                <div class="mt-1 text-2xl font-bold tabular-nums" :class="summary.net >= 0 ? 'text-emerald-300' : 'text-rose-300'">{{ money(summary.net) }}</div>
                <div class="mt-0.5 text-[11px] text-white/60">{{ monthActive ? $e('оборот − расходы за месяц (без ЗП и налога)') : $e('оборот − все расходы') }}</div>
            </div>
        </div>

        <!-- ================= Поступления денег ================= -->
        <div class="mt-6 rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b px-6 py-4">
                <div class="flex items-center gap-3">
                    <h3 class="text-sm font-semibold text-slate-900">{{ $e('Поступления денег') }}</h3>
                    <span class="rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-medium text-emerald-700">{{ $e('сегодня') }} <b class="tabular-nums">{{ money(todaySum) }}</b></span>
                    <span class="hidden rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-500 sm:inline-flex">{{ monthActive ? monthLabel : $e('всего') }} <b class="ml-1 tabular-nums">{{ money(summary.incomeManual) }}</b></span>
                </div>
                <button v-if="canManage" @click="openReceipt"
                    class="rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-emerald-700">{{ $e('+ Поступление') }}</button>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full whitespace-nowrap divide-y divide-slate-100 text-sm">
                    <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-400">
                        <tr>
                            <th class="px-6 py-2.5">{{ $e('Дата') }}</th>
                            <th class="px-4 py-2.5 text-right">{{ $e('Сумма') }}</th>
                            <th class="px-4 py-2.5">{{ $e('Куда') }}</th>
                            <th class="px-4 py-2.5">{{ $e('Откуда поступили') }}</th>
                            <th class="px-4 py-2.5">{{ $e('Комментарий') }}</th>
                            <th class="px-4 py-2.5">{{ $e('Внёс') }}</th>
                            <th v-if="canManage" class="px-4 py-2.5"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        <tr v-for="r in receiptsToday" :key="r.id" class="hover:bg-slate-50">
                            <td class="px-6 py-3 text-slate-500">{{ formatDate(r.date) }}<span class="block text-[10px] text-slate-400">{{ $e('внесено') }} {{ formatDateTime(r.created_at) }}</span></td>
                            <td class="px-4 py-3 text-right font-semibold tabular-nums text-emerald-600">+ {{ money(r.amount) }}</td>
                            <td class="px-4 py-3">
                                <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="r.method === 'cash' ? 'bg-emerald-100 text-emerald-700' : 'bg-sky-100 text-sky-700'">{{ r.method === 'cash' ? $e('наличные') : $e('банк (счёт)') }}</span>
                            </td>
                            <td class="max-w-56 truncate px-4 py-3 font-medium text-slate-800" :title="r.source">{{ r.source }}</td>
                            <td class="max-w-56 truncate px-4 py-3 text-slate-500" :title="r.note">{{ r.note || '—' }}</td>
                            <td class="px-4 py-3 text-xs text-slate-400">{{ r.creator?.name ?? '—' }}</td>
                            <td v-if="canManage" class="px-4 py-3 text-right">
                                <button class="text-slate-300 transition hover:text-rose-600" :title="$e('Удалить поступление')" @click="delReceipt(r)">✕</button>
                            </td>
                        </tr>
                        <tr v-if="!receiptsToday.length"><td colspan="7" class="px-6 py-8 text-center text-sm text-slate-400">{{ $e('Сегодня поступлений не было — «+ Поступление»') }}</td></tr>
                    </tbody>
                </table>
            </div>

            <!-- Прошлые поступления: аккордеон с поиском и периодом -->
            <div class="border-t border-slate-100">
                <button type="button" @click="pastOpen = !pastOpen" class="flex w-full items-center justify-between gap-3 px-6 py-3.5 text-left">
                    <div class="flex min-w-0 items-center gap-2">
                        <svg class="h-4 w-4 flex-shrink-0 text-slate-400 transition-transform" :class="pastOpen ? 'rotate-90' : ''" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M8 5l5 5-5 5"/></svg>
                        <span class="text-sm font-semibold text-slate-900">{{ $e('Прошлые поступления') }}</span>
                    </div>
                    <span class="flex-shrink-0 rounded-full bg-slate-100 px-2.5 py-1 text-xs font-bold tabular-nums text-slate-600">{{ receiptsPastStats?.count ?? 0 }} · {{ money(receiptsPastStats?.sum) }}</span>
                </button>
                <div v-show="pastOpen" class="border-t border-slate-100">
                    <div class="flex flex-wrap items-center gap-2 px-6 py-3">
                        <input v-model="rcSearch" @keyup.enter="applyRcFilters" type="text" :placeholder="$e('Поиск: откуда / комментарий')"
                            class="w-56 rounded-lg border-slate-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500" />
                        <input v-model="rcFrom" type="date" class="rounded-lg border-slate-300 text-sm shadow-sm" :title="$e('Период с')" />
                        <span class="text-xs text-slate-400">—</span>
                        <input v-model="rcTo" type="date" class="rounded-lg border-slate-300 text-sm shadow-sm" :title="$e('Период по')" />
                        <button @click="applyRcFilters" class="rounded-lg bg-slate-800 px-3 py-2 text-xs font-semibold text-white transition hover:bg-slate-900">{{ $e('Найти') }}</button>
                        <button v-if="filters?.rc_search || filters?.rc_from || filters?.rc_to" @click="resetRcFilters"
                            class="rounded-lg px-3 py-2 text-xs font-medium text-slate-500 transition hover:bg-slate-100">{{ $e('Сбросить') }}</button>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full whitespace-nowrap divide-y divide-slate-100 text-sm">
                            <tbody class="divide-y divide-slate-50">
                                <tr v-for="r in receiptsPast" :key="r.id" class="hover:bg-slate-50">
                                    <td class="px-6 py-3 text-slate-500">{{ formatDate(r.date) }}<span class="block text-[10px] text-slate-400">{{ $e('внесено') }} {{ formatDateTime(r.created_at) }}</span></td>
                                    <td class="px-4 py-3 text-right font-semibold tabular-nums text-emerald-600">+ {{ money(r.amount) }}</td>
                                    <td class="px-4 py-3">
                                        <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="r.method === 'cash' ? 'bg-emerald-100 text-emerald-700' : 'bg-sky-100 text-sky-700'">{{ r.method === 'cash' ? $e('наличные') : $e('банк (счёт)') }}</span>
                                    </td>
                                    <td class="max-w-56 truncate px-4 py-3 font-medium text-slate-800" :title="r.source">{{ r.source }}</td>
                                    <td class="max-w-56 truncate px-4 py-3 text-slate-500" :title="r.note">{{ r.note || '—' }}</td>
                                    <td class="px-4 py-3 text-xs text-slate-400">{{ r.creator?.name ?? '—' }}</td>
                                    <td v-if="canManage" class="px-4 py-3 text-right">
                                        <button class="text-slate-300 transition hover:text-rose-600" :title="$e('Удалить поступление')" @click="delReceipt(r)">✕</button>
                                    </td>
                                </tr>
                                <tr v-if="!receiptsPast.length"><td colspan="7" class="px-6 py-6 text-center text-sm text-slate-400">{{ $e('Прошлых поступлений не найдено') }}</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- ================= Задолженности (аккордеоны) =================
             Кредиторка скрыта по просьбе владельца (24.07.2026) — вернуть:
             добавить обратно строку { type: 'payable', … } в массив. -->
        <div class="mt-6 grid grid-cols-1 items-start gap-4">
            <div v-for="acc in [
                    { type: 'receivable', title: $e('Дебиторская задолженность — кто нам должен'), list: debts.receivables, total: summary.receivablesTotal, color: 'rose' },
                ]" :key="acc.type" class="rounded-2xl border border-slate-200 bg-white shadow-sm">
                <!-- Шапка-аккордеон: клик сворачивает/разворачивает -->
                <button type="button" @click="debtOpen[acc.type] = !debtOpen[acc.type]"
                    class="flex w-full items-center justify-between gap-3 px-5 py-4 text-left">
                    <div class="flex min-w-0 items-center gap-2">
                        <svg class="h-4 w-4 flex-shrink-0 text-slate-400 transition-transform" :class="debtOpen[acc.type] ? 'rotate-90' : ''" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M8 5l5 5-5 5"/></svg>
                        <span class="truncate text-sm font-semibold text-slate-900">{{ acc.title }}</span>
                    </div>
                    <span class="flex-shrink-0 rounded-full px-2.5 py-1 text-xs font-bold tabular-nums"
                        :class="acc.total > 0 ? (acc.color === 'rose' ? 'bg-rose-100 text-rose-700' : 'bg-amber-100 text-amber-700') : 'bg-slate-100 text-slate-400'">{{ money(acc.total) }}</span>
                </button>
                <div v-show="debtOpen[acc.type]" class="border-t border-slate-100 px-5 py-3">
                    <!-- Дебиторка: автоматическая часть по счетам сделок -->
                    <div v-if="acc.type === 'receivable'" class="mb-2 flex items-center justify-between rounded-lg bg-slate-50 px-3 py-2 text-sm">
                        <span class="text-slate-500">{{ $e('По счетам сделок (автоматически)') }}</span>
                        <span class="font-semibold tabular-nums text-slate-700">{{ money(summary.receivables) }}</span>
                    </div>
                    <div class="divide-y divide-slate-50">
                        <div v-for="d in acc.list" :key="d.id" class="flex items-center justify-between gap-3 py-2.5 text-sm">
                            <div class="min-w-0">
                                <div class="truncate font-medium text-slate-800">{{ d.counterparty }}</div>
                                <div class="text-[11px] text-slate-400">
                                    <template v-if="d.date">{{ formatDate(d.date) }} · </template>{{ d.note || '—' }}<template v-if="d.creator?.name"> · {{ d.creator.name }}</template> {{ $e('· внесено') }} {{ formatDateTime(d.created_at) }}
                                </div>
                            </div>
                            <div class="flex flex-shrink-0 items-center gap-2">
                                <span class="font-semibold tabular-nums" :class="acc.color === 'rose' ? 'text-rose-600' : 'text-amber-600'">{{ money(d.amount) }}</span>
                                <template v-if="canManage">
                                    <button class="rounded p-1 text-slate-300 transition hover:text-indigo-600" :title="$e('Редактировать')" @click="openDebt(acc.type, d)">
                                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>
                                    </button>
                                    <button class="rounded p-1 text-slate-300 transition hover:text-rose-600" :title="$e('Удалить (СЕО и директор получат уведомление)')" @click="delDebt(d)">
                                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
                                    </button>
                                </template>
                            </div>
                        </div>
                        <div v-if="!acc.list.length" class="py-3 text-center text-xs text-slate-300">{{ $e('Записей нет') }}</div>
                    </div>
                    <button v-if="canManage" type="button" @click="openDebt(acc.type)"
                        class="mt-2 w-full rounded-lg border border-dashed border-slate-300 py-2 text-xs font-medium text-slate-500 transition hover:border-indigo-400 hover:text-indigo-600">{{ $e('+ Добавить запись') }}</button>
                </div>
            </div>
        </div>

        <!-- ================= Расходы ================= -->
        <div class="mt-6 rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b px-6 py-4">
                <div class="flex items-center gap-3">
                    <h3 class="text-sm font-semibold text-slate-900">{{ $e('Расходы') }}</h3>
                    <span class="rounded-full bg-rose-100 px-2.5 py-1 text-xs font-medium text-rose-700">{{ $e('сегодня') }} <b class="tabular-nums">{{ money(expTodaySum) }}</b></span>
                    <button v-if="canManage" @click="showCompanyExpense = true"
                        class="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-indigo-700">{{ $e('+ Расход компании') }}</button>
                    <button v-if="canManage" @click="showCats = true"
                        class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-medium text-slate-500 transition hover:bg-slate-50 hover:text-slate-700" :title="$e('Категории расходов компании')">{{ $e('⚙ Категории') }}</button>
                </div>
                <div class="flex flex-wrap items-center gap-2 text-sm">
                    <label class="flex items-center gap-1 text-xs text-slate-400">{{ $e('с') }}
                        <input v-model="expFrom" @change="applyExpFilters" type="date" class="rounded-lg border-slate-200 py-1.5 text-xs shadow-sm transition focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20" />
                    </label>
                    <label class="flex items-center gap-1 text-xs text-slate-400">{{ $e('по') }}
                        <input v-model="expTo" @change="applyExpFilters" type="date" class="rounded-lg border-slate-200 py-1.5 text-xs shadow-sm transition focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20" />
                    </label>
                    <select v-model="expKind" @change="applyExpFilters" class="rounded-lg border-slate-200 py-1.5 text-xs shadow-sm transition focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20">
                        <option value="">{{ $e('Все виды') }}</option>
                        <option value="material">{{ $e('Материальные (склад)') }}</option>
                        <option value="other">{{ $e('Прочие') }}</option>
                    </select>
                    <select v-model="expMethod" @change="applyExpFilters" class="rounded-lg border-slate-200 py-1.5 text-xs shadow-sm transition focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20">
                        <option value="">{{ $e('Любая оплата') }}</option>
                        <option value="cash">{{ $e('Наличные') }}</option>
                        <option value="bank">{{ $e('Банк (счёт)') }}</option>
                    </select>
                    <select v-model="expStatus" @change="applyExpFilters" class="rounded-lg border-slate-200 py-1.5 text-xs shadow-sm transition focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20">
                        <option value="">{{ $e('Все статусы') }}</option>
                        <option value="pending">{{ $e('Ждёт бухгалтера') }}</option>
                        <option value="confirmed">{{ $e('Подтверждён') }}</option>
                    </select>
                </div>
            </div>

            <!-- Сводка по расходам: плитки-фильтры (клик фильтрует таблицу).
                 Нал + банк = прочие: у материальных списаний способа оплаты нет. -->
            <div class="grid grid-cols-2 gap-3 px-6 py-4 lg:grid-cols-5">
                <button type="button" @click="setTile('', '', '')"
                    class="rounded-xl bg-slate-900 p-3 text-left transition hover:opacity-90"
                    :class="tileActive('', '', '') ? 'ring-2 ring-slate-900 ring-offset-2' : ''">
                    <div class="text-[11px] font-medium text-slate-300">{{ $e('Все расходы (') }}{{ expenseTotals.all_count }})</div>
                    <div class="mt-0.5 text-base font-bold tabular-nums text-white">{{ money(expenseTotals.all) }}</div>
                </button>
                <button type="button" @click="setTile('material', '', 'confirmed')"
                    class="rounded-xl bg-indigo-50 p-3 text-left transition hover:bg-indigo-100"
                    :class="tileActive('material', '', 'confirmed') ? 'ring-2 ring-indigo-400 ring-offset-1' : ''">
                    <div class="text-[11px] font-medium text-indigo-700">{{ $e('Материальные (склад)') }}</div>
                    <div class="mt-0.5 text-base font-bold tabular-nums text-indigo-700">{{ money(expenseTotals.material) }}</div>
                </button>
                <button type="button" @click="setTile('other', 'cash', 'confirmed')"
                    class="rounded-xl bg-emerald-50 p-3 text-left transition hover:bg-emerald-100"
                    :class="tileActive('other', 'cash', 'confirmed') ? 'ring-2 ring-emerald-400 ring-offset-1' : ''">
                    <div class="text-[11px] font-medium text-emerald-700">{{ $e('Прочие расходы (нал)') }}</div>
                    <div class="mt-0.5 text-base font-bold tabular-nums text-emerald-700">{{ money(expenseTotals.cash) }}</div>
                </button>
                <button type="button" @click="setTile('other', 'bank', 'confirmed')"
                    class="rounded-xl bg-sky-50 p-3 text-left transition hover:bg-sky-100"
                    :class="tileActive('other', 'bank', 'confirmed') ? 'ring-2 ring-sky-400 ring-offset-1' : ''">
                    <div class="text-[11px] font-medium text-sky-700">{{ $e('Прочие расходы (банк)') }}</div>
                    <div class="mt-0.5 text-base font-bold tabular-nums text-sky-700">{{ money(expenseTotals.bank) }}</div>
                </button>
                <button type="button" @click="setTile('', '', 'pending')"
                    class="rounded-xl bg-amber-50 p-3 text-left transition hover:bg-amber-100"
                    :class="tileActive('', '', 'pending') ? 'ring-2 ring-amber-400 ring-offset-1' : ''">
                    <div class="text-[11px] font-medium text-amber-700">{{ $e('Ждёт бухгалтера (') }}{{ expenseTotals.pending_count }})</div>
                    <div class="mt-0.5 text-base font-bold tabular-nums text-amber-700">{{ money(expenseTotals.pending_sum) }}</div>
                </button>
            </div>

            <table class="min-w-full divide-y divide-slate-100 text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase text-slate-500">
                    <tr>
                        <th class="px-6 py-3">{{ $e('Сумма') }}</th>
                        <th class="px-4 py-3">{{ $e('Описание') }}</th>
                        <th class="px-4 py-3">{{ $e('Сделка / заказ') }}</th>
                        <th class="px-4 py-3">{{ $e('Вид') }}</th>
                        <th class="px-4 py-3">{{ $e('Оплата') }}</th>
                        <th class="px-4 py-3">{{ $e('Статус') }}</th>
                        <th class="px-4 py-3">{{ $e('Автор') }}</th>
                        <th class="px-4 py-3">{{ $e('Дата') }}</th>
                        <th v-if="canManage" class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="e in expensesToday" :key="e.id" class="transition-colors hover:bg-slate-50">
                        <td class="px-6 py-3 font-semibold tabular-nums text-slate-900">{{ money(e.amount) }}</td>
                        <td class="max-w-[220px] truncate px-4 py-3 text-slate-500">{{ e.description || '—' }}</td>
                        <td class="px-4 py-3">
                            <Link v-if="e.expenseable_id" :href="expLink(e)" class="font-medium text-indigo-600 hover:underline">{{ e.expenseable?.number ?? '—' }}</Link>
                            <span v-else class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600">{{ e.category?.name ?? $e('Компания') }}</span>
                        </td>
                        <td class="px-4 py-3">
                            <span v-if="e.material" class="rounded-full bg-indigo-100 px-2 py-0.5 text-xs font-medium text-indigo-700">{{ $e('склад') }}</span>
                            <span v-else class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-500">{{ $e('прочий') }}</span>
                        </td>
                        <td class="px-4 py-3 text-xs text-slate-500">{{ e.payment_method === 'cash' ? $e('наличные') : (e.payment_method === 'bank' ? $e('банк') : '—') }}</td>
                        <td class="px-4 py-3">
                            <span v-if="e.status === 'confirmed'" class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-700">{{ $e('Подтверждён') }}</span>
                            <span v-else-if="e.status === 'pending'" class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-700">{{ $e('Ждёт бухгалтера') }}</span>
                            <span v-else class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-500">{{ $e('Черновик') }}</span>
                        </td>
                        <td class="px-4 py-3 text-xs text-slate-500">{{ e.responsible?.name ?? '—' }}<span v-if="e.confirmed_by?.name" class="block text-[10px] text-slate-400">{{ $e('подтв.:') }} {{ e.confirmed_by.name }}</span></td>
                        <td class="px-4 py-3 text-xs text-slate-400">{{ formatDate(e.date) }}<span class="block text-[10px] text-slate-300">{{ $e('внесено') }} {{ formatDateTime(e.created_at) }}</span></td>
                        <td v-if="canManage" class="px-4 py-3 text-right whitespace-nowrap">
                            <button class="rounded p-1 text-slate-300 transition hover:text-indigo-600" :title="$e('Редактировать расход')" @click="openEditExp(e)">
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>
                            </button>
                            <button class="rounded p-1 text-slate-300 transition hover:text-rose-600" :title="$e('Удалить расход')" @click="delExpense(e)">
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
                            </button>
                        </td>
                    </tr>
                    <tr v-if="!expensesToday.length"><td :colspan="canManage ? 9 : 8" class="px-6 py-10 text-center text-slate-400">{{ $e('Сегодня расходов не было') }}</td></tr>
                </tbody>
            </table>

            <!-- Прошлые расходы: аккордеон с поиском и периодом -->
            <div class="border-t border-slate-100">
                <button type="button" @click="expPastOpen = !expPastOpen" class="flex w-full items-center justify-between gap-3 px-6 py-3.5 text-left">
                    <div class="flex min-w-0 items-center gap-2">
                        <svg class="h-4 w-4 flex-shrink-0 text-slate-400 transition-transform" :class="expPastOpen ? 'rotate-90' : ''" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M8 5l5 5-5 5"/></svg>
                        <span class="text-sm font-semibold text-slate-900">{{ $e('Прошлые расходы') }}</span>
                    </div>
                    <span class="flex-shrink-0 rounded-full bg-slate-100 px-2.5 py-1 text-xs font-bold tabular-nums text-slate-600">{{ expensesPastStats?.count ?? 0 }} · {{ money(expensesPastStats?.sum) }}</span>
                </button>
                <div v-show="expPastOpen" class="border-t border-slate-100">
                    <div class="flex flex-wrap items-center gap-2 px-6 py-3">
                        <input v-model="xpSearch" @keyup.enter="applyXpFilters" type="text" :placeholder="$e('Поиск: описание / категория')"
                            class="w-56 rounded-lg border-slate-300 text-sm shadow-sm focus:border-rose-500 focus:ring-rose-500" />
                        <input v-model="xpFrom" type="date" class="rounded-lg border-slate-300 text-sm shadow-sm" :title="$e('Период с')" />
                        <span class="text-xs text-slate-400">—</span>
                        <input v-model="xpTo" type="date" class="rounded-lg border-slate-300 text-sm shadow-sm" :title="$e('Период по')" />
                        <button @click="applyXpFilters" class="rounded-lg bg-slate-800 px-3 py-2 text-xs font-semibold text-white transition hover:bg-slate-900">{{ $e('Найти') }}</button>
                        <button v-if="filters?.xp_search || filters?.xp_from || filters?.xp_to" @click="resetXpFilters"
                            class="rounded-lg px-3 py-2 text-xs font-medium text-slate-500 transition hover:bg-slate-100">{{ $e('Сбросить') }}</button>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-100 text-sm">
                            <tbody class="divide-y divide-slate-100">
<tr v-for="e in expensesPast" :key="e.id" class="transition-colors hover:bg-slate-50">
                        <td class="px-6 py-3 font-semibold tabular-nums text-slate-900">{{ money(e.amount) }}</td>
                        <td class="max-w-[220px] truncate px-4 py-3 text-slate-500">{{ e.description || '—' }}</td>
                        <td class="px-4 py-3">
                            <Link v-if="e.expenseable_id" :href="expLink(e)" class="font-medium text-indigo-600 hover:underline">{{ e.expenseable?.number ?? '—' }}</Link>
                            <span v-else class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600">{{ e.category?.name ?? $e('Компания') }}</span>
                        </td>
                        <td class="px-4 py-3">
                            <span v-if="e.material" class="rounded-full bg-indigo-100 px-2 py-0.5 text-xs font-medium text-indigo-700">{{ $e('склад') }}</span>
                            <span v-else class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-500">{{ $e('прочий') }}</span>
                        </td>
                        <td class="px-4 py-3 text-xs text-slate-500">{{ e.payment_method === 'cash' ? $e('наличные') : (e.payment_method === 'bank' ? $e('банк') : '—') }}</td>
                        <td class="px-4 py-3">
                            <span v-if="e.status === 'confirmed'" class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-700">{{ $e('Подтверждён') }}</span>
                            <span v-else-if="e.status === 'pending'" class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-700">{{ $e('Ждёт бухгалтера') }}</span>
                            <span v-else class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-500">{{ $e('Черновик') }}</span>
                        </td>
                        <td class="px-4 py-3 text-xs text-slate-500">{{ e.responsible?.name ?? '—' }}<span v-if="e.confirmed_by?.name" class="block text-[10px] text-slate-400">{{ $e('подтв.:') }} {{ e.confirmed_by.name }}</span></td>
                        <td class="px-4 py-3 text-xs text-slate-400">{{ formatDate(e.date) }}<span class="block text-[10px] text-slate-300">{{ $e('внесено') }} {{ formatDateTime(e.created_at) }}</span></td>
                        <td v-if="canManage" class="px-4 py-3 text-right whitespace-nowrap">
                            <button class="rounded p-1 text-slate-300 transition hover:text-indigo-600" :title="$e('Редактировать расход')" @click="openEditExp(e)">
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>
                            </button>
                            <button class="rounded p-1 text-slate-300 transition hover:text-rose-600" :title="$e('Удалить расход')" @click="delExpense(e)">
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
                            </button>
                        </td>
                    </tr>
                                                    <tr v-if="!expensesPast.length"><td :colspan="canManage ? 9 : 8" class="px-6 py-6 text-center text-slate-400">{{ $e('Прошлых расходов не найдено') }}</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Блок «Зарплаты сотрудников» убран (24.07.2026) — ЗП живёт на своей
             странице; здесь остаются только Счета на всю ширину. -->
        <div class="mt-6">
            <!-- Счета -->
            <div class="overflow-hidden rounded-2xl bg-white shadow-sm border border-slate-200">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b px-6 py-4">
                    <span class="text-sm font-semibold text-slate-900">{{ $e('Счета') }}</span>
                    <!-- Дебиторка: выставлено / оплачено / клиенты должны -->
                    <div class="flex flex-wrap gap-2 text-xs">
                        <span class="rounded-full bg-slate-100 px-2.5 py-1 font-medium text-slate-600">{{ $e('выставлено') }} <b class="tabular-nums">{{ money(invoiceTotals.invoiced) }}</b></span>
                        <span class="rounded-full bg-emerald-100 px-2.5 py-1 font-medium text-emerald-700">{{ $e('оплачено') }} <b class="tabular-nums">{{ money(invoiceTotals.paid) }}</b></span>
                        <span class="rounded-full px-2.5 py-1 font-medium" :class="invoiceTotals.debt > 0 ? 'bg-rose-100 text-rose-700' : 'bg-slate-100 text-slate-400'">{{ $e('долг клиентов') }} <b class="tabular-nums">{{ money(invoiceTotals.debt) }}</b></span>
                    </div>
                </div>
                <!-- Сегодняшние счета -->
                <table class="min-w-full divide-y divide-slate-100 text-sm">
                    <thead class="bg-slate-50 text-left text-xs uppercase text-slate-500">
                        <tr>
                            <th class="px-4 py-3">{{ $e('Номер') }}</th><th class="px-4 py-3">{{ $e('Сделка') }}</th><th class="px-4 py-3">{{ $e('Клиент') }}</th><th class="px-4 py-3">{{ $e('Сумма') }}</th>
                            <th class="px-4 py-3">{{ $e('Оплачено') }}</th><th class="px-4 py-3">{{ $e('Статус') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="inv in invoicesToday" :key="inv.id" class="hover:bg-slate-50">
                            <td class="px-4 py-3 font-medium text-slate-900">{{ inv.number }}</td>
                            <td class="px-4 py-3">
                                <Link v-if="inv.link && inv.link.id" :href="route(inv.link.type === 'project' ? 'projects.show' : 'deals.show', inv.link.id)"
                                    class="text-indigo-600 hover:underline">{{ inv.link.label }}</Link>
                                <span v-else-if="inv.link" class="text-slate-400">{{ inv.link.label }}</span>
                                <span v-else class="text-slate-400">—</span>
                            </td>
                            <td class="px-4 py-3 text-slate-500">{{ inv.client?.name ?? '—' }}</td>
                            <td class="px-4 py-3">{{ money(inv.amount) }}</td>
                            <td class="px-4 py-3 text-green-600">{{ money(inv.payments_sum_amount ?? 0) }}</td>
                            <td class="px-4 py-3"><StatusBadge :status="inv.status" /></td>
                        </tr>
                        <tr v-if="!invoicesToday.length"><td colspan="6" class="px-4 py-8 text-center text-slate-400">{{ $e('Сегодня счетов нет') }}</td></tr>
                    </tbody>
                </table>

                <!-- Прошлые счета: аккордеон с поиском по номеру -->
                <div class="border-t border-slate-100">
                    <button type="button" @click="invPastOpen = !invPastOpen" class="flex w-full items-center justify-between gap-3 px-6 py-3.5 text-left">
                        <div class="flex min-w-0 items-center gap-2">
                            <svg class="h-4 w-4 flex-shrink-0 text-slate-400 transition-transform" :class="invPastOpen ? 'rotate-90' : ''" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M8 5l5 5-5 5"/></svg>
                            <span class="text-sm font-semibold text-slate-900">{{ $e('Прошлые счета') }}</span>
                        </div>
                        <span class="flex-shrink-0 rounded-full bg-slate-100 px-2.5 py-1 text-xs font-bold tabular-nums text-slate-600">{{ invoicesPastStats?.count ?? 0 }} · {{ money(invoicesPastStats?.sum) }}</span>
                    </button>
                    <div v-show="invPastOpen" class="border-t border-slate-100">
                        <div class="flex flex-wrap items-center gap-2 px-6 py-3">
                            <input v-model="invSearch" @keyup.enter="applyInvFilters" type="text" :placeholder="$e('Поиск по номеру счёта')"
                                class="w-56 rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                            <button @click="applyInvFilters" class="rounded-lg bg-slate-800 px-3 py-2 text-xs font-semibold text-white transition hover:bg-slate-900">{{ $e('Найти') }}</button>
                            <button v-if="filters?.search" @click="resetInvFilters"
                                class="rounded-lg px-3 py-2 text-xs font-medium text-slate-500 transition hover:bg-slate-100">{{ $e('Сбросить') }}</button>
                        </div>
                        <table class="min-w-full divide-y divide-slate-100 text-sm">
                            <tbody class="divide-y divide-slate-50">
                                <tr v-for="inv in invoicesPast" :key="inv.id" class="hover:bg-slate-50">
                                    <td class="px-4 py-3">
                                        <span class="font-medium text-slate-900">{{ inv.number }}</span>
                                        <span class="block text-[10px] text-slate-400">{{ formatDate(inv.date) }}</span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <Link v-if="inv.link && inv.link.id" :href="route(inv.link.type === 'project' ? 'projects.show' : 'deals.show', inv.link.id)"
                                            class="text-indigo-600 hover:underline">{{ inv.link.label }}</Link>
                                        <span v-else-if="inv.link" class="text-slate-400">{{ inv.link.label }}</span>
                                        <span v-else class="text-slate-400">—</span>
                                    </td>
                                    <td class="px-4 py-3 text-slate-500">{{ inv.client?.name ?? '—' }}</td>
                                    <td class="px-4 py-3">{{ money(inv.amount) }}</td>
                                    <td class="px-4 py-3 text-green-600">{{ money(inv.payments_sum_amount ?? 0) }}</td>
                                    <td class="px-4 py-3"><StatusBadge :status="inv.status" /></td>
                                </tr>
                                <tr v-if="!invoicesPast.length"><td colspan="6" class="px-6 py-6 text-center text-sm text-slate-400">{{ $e('Прошлых счетов не найдено') }}</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Модалка: редактирование расхода -->
        <Modal :show="!!editingExp" @close="editingExp = null" max-width="lg">
            <div class="p-6">
                <h2 class="mb-1 text-lg font-semibold text-slate-900">{{ $e('Редактировать расход') }}</h2>
                <p class="mb-4 text-xs text-slate-400">{{ $e('Способ оплаты и материал/количество не меняются: способ ставится при подтверждении, материальный расход — удалить (остаток вернётся) и создать заново.') }}</p>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">{{ $e('Сумма, ₸') }}</label>
                        <input v-model="eForm.amount" type="number" min="0" step="0.01" :disabled="!!(editingExp?.material_id && Number(editingExp?.material?.price) > 0)"
                            class="w-full rounded-md border-slate-300 text-sm shadow-sm disabled:bg-slate-100 disabled:text-slate-400" />
                        <div v-if="editingExp?.material_id && Number(editingExp?.material?.price) > 0" class="mt-1 text-[11px] text-slate-400">{{ $e('Сумма материального расхода = количество × цена (авто)') }}</div>
                        <div v-if="eForm.errors.amount" class="mt-1 text-xs text-red-600">{{ eForm.errors.amount }}</div>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">{{ $e('Дата *') }}</label>
                        <input v-model="eForm.date" type="date" class="w-full rounded-md border-slate-300 text-sm shadow-sm" />
                        <div v-if="eForm.errors.date" class="mt-1 text-xs text-red-600">{{ eForm.errors.date }}</div>
                    </div>
                    <div v-if="editingExp && !editingExp.expenseable_id" class="sm:col-span-2">
                        <label class="mb-1 block text-xs font-medium text-slate-500">{{ $e('Категория') }}</label>
                        <select v-model="eForm.category_id" class="w-full rounded-md border-slate-300 text-sm shadow-sm">
                            <option value="">{{ $e('— без категории —') }}</option>
                            <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
                        </select>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-xs font-medium text-slate-500">{{ $e('Описание') }}</label>
                        <input v-model="eForm.description" type="text" class="w-full rounded-md border-slate-300 text-sm shadow-sm" />
                    </div>
                </div>
                <div class="mt-6 flex justify-end gap-2">
                    <SecondaryButton @click="editingExp = null">{{ $e('Отмена') }}</SecondaryButton>
                    <PrimaryButton :disabled="eForm.processing" @click="submitEditExp">{{ $e('Сохранить') }}</PrimaryButton>
                </div>
            </div>
        </Modal>

        <!-- Модалка: задолженность (дебиторка/кредиторка) -->
        <Modal :show="showDebt" @close="showDebt = false" max-width="lg">
            <div class="p-6">
                <h2 class="mb-1 text-lg font-semibold text-slate-900">{{ debtEditing ? $e('Редактировать') : $e('Добавить') }} {{ dForm.type === 'payable' ? $e('кредиторскую задолженность (кому мы должны)') : $e('дебиторскую задолженность (кто нам должен)') }}</h2>
                <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-xs font-medium text-slate-500">{{ dForm.type === 'payable' ? $e('Кому мы должны *') : $e('Кто нам должен *') }}</label>
                        <input v-model="dForm.counterparty" type="text" class="w-full rounded-md border-slate-300 text-sm shadow-sm" :placeholder="$e('Компания / человек…')" />
                        <div v-if="dForm.errors.counterparty" class="mt-1 text-xs text-red-600">{{ dForm.errors.counterparty }}</div>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">{{ $e('Сумма, ₸ *') }}</label>
                        <input v-model="dForm.amount" type="number" min="0.01" step="0.01" class="w-full rounded-md border-slate-300 text-sm shadow-sm" />
                        <div v-if="dForm.errors.amount" class="mt-1 text-xs text-red-600">{{ dForm.errors.amount }}</div>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">{{ $e('Срок / дата') }}</label>
                        <input v-model="dForm.date" type="date" class="w-full rounded-md border-slate-300 text-sm shadow-sm" />
                    </div>
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-xs font-medium text-slate-500">{{ $e('Комментарий') }}</label>
                        <input v-model="dForm.note" type="text" class="w-full rounded-md border-slate-300 text-sm shadow-sm" :placeholder="$e('За что…')" />
                    </div>
                </div>
                <div class="mt-6 flex justify-end gap-2">
                    <SecondaryButton @click="showDebt = false">{{ $e('Отмена') }}</SecondaryButton>
                    <PrimaryButton :disabled="dForm.processing || !dForm.counterparty || !(Number(dForm.amount) > 0)" @click="submitDebt">{{ $e('Сохранить') }}</PrimaryButton>
                </div>
            </div>
        </Modal>

        <!-- Модалка: поступление денег -->
        <Modal :show="showReceipt" @close="showReceipt = false" max-width="lg">
            <div class="p-6">
                <h2 class="mb-1 text-lg font-semibold text-slate-900">{{ $e('Поступление денег') }}</h2>
                <p class="mb-4 text-xs text-slate-400">{{ $e('Откуда пришли деньги и куда легли — в кассу (нал) или на счёт (банк). Остатки на плитках пересчитаются сразу.') }}</p>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">{{ $e('Сумма, ₸ *') }}</label>
                        <input v-model="rForm.amount" type="number" min="0.01" step="0.01" class="w-full rounded-md border-slate-300 text-sm shadow-sm" />
                        <div v-if="rForm.errors.amount" class="mt-1 text-xs text-red-600">{{ rForm.errors.amount }}</div>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">{{ $e('Дата *') }}</label>
                        <input v-model="rForm.date" type="date" class="w-full rounded-md border-slate-300 text-sm shadow-sm" />
                        <div v-if="rForm.errors.date" class="mt-1 text-xs text-red-600">{{ rForm.errors.date }}</div>
                    </div>
                    <div class="sm:col-span-2 flex gap-2">
                        <button type="button" @click="rForm.method = 'cash'"
                            class="rounded-lg border px-3 py-1.5 text-xs font-semibold transition-all"
                            :class="rForm.method === 'cash' ? 'border-emerald-500 bg-emerald-100 text-emerald-700 ring-1 ring-emerald-500' : 'border-slate-200 bg-white text-slate-500'">{{ $e('В кассу (наличные)') }}</button>
                        <button type="button" @click="rForm.method = 'bank'"
                            class="rounded-lg border px-3 py-1.5 text-xs font-semibold transition-all"
                            :class="rForm.method === 'bank' ? 'border-sky-500 bg-sky-100 text-sky-700 ring-1 ring-sky-500' : 'border-slate-200 bg-white text-slate-500'">{{ $e('На счёт (банк)') }}</button>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-xs font-medium text-slate-500">{{ $e('Откуда поступили *') }}</label>
                        <input v-model="rForm.source" type="text" class="w-full rounded-md border-slate-300 text-sm shadow-sm" :placeholder="$e('Клиент / учредитель / кредит / возврат…')" />
                        <div v-if="rForm.errors.source" class="mt-1 text-xs text-red-600">{{ rForm.errors.source }}</div>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-xs font-medium text-slate-500">{{ $e('Комментарий') }}</label>
                        <input v-model="rForm.note" type="text" class="w-full rounded-md border-slate-300 text-sm shadow-sm" />
                    </div>
                </div>
                <div class="mt-6 flex justify-end gap-2">
                    <SecondaryButton @click="showReceipt = false">{{ $e('Отмена') }}</SecondaryButton>
                    <PrimaryButton :disabled="rForm.processing || !(Number(rForm.amount) > 0) || !rForm.source" @click="submitReceipt">{{ $e('Сохранить') }}</PrimaryButton>
                </div>
            </div>
        </Modal>

        <CompanyExpenseModal :show="showCompanyExpense" :categories="categories"
            :cash="Number(summary.cash)" :bank="Number(summary.bank)" @close="showCompanyExpense = false" />
        <ExpenseCategoriesModal :show="showCats" :categories="categories" @close="showCats = false" />
    </AppLayout>
</template>
