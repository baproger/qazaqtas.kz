<script setup>
import { ref, computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import FinanceLayout from '@/Layouts/FinanceLayout.vue';
import DdsPanel from '@/Components/DdsPanel.vue';
import CompanyExpenseModal from '@/Components/CompanyExpenseModal.vue';
import ExpenseCategoriesModal from '@/Components/ExpenseCategoriesModal.vue';

// Обзор Финансов: картина целиком. Работа с записями — в разделах
// (Счета, Поступления, Расходы, Задолженности), каждый своей страницей.
const props = defineProps({
    invoiceTotals: { type: Object, default: () => ({ invoiced: 0, paid: 0, debt: 0 }) },
    filters: { type: Object, default: () => ({}) },
    summary: { type: Object, default: () => ({}) },
    categories: { type: Array, default: () => [] },
    canManage: { type: Boolean, default: false },
    isAdmin: { type: Boolean, default: false },
    dds: { type: Object, default: () => ({ accounts: [], debts: [], date: '' }) },
});
const money = (v) => new Intl.NumberFormat('ru-RU').format(Math.round(v ?? 0)) + ' ₸';

// Корректировка кассы (инвентаризация): финансист задаёт фактический остаток.
const cashFixOpen = ref(false);
const cashFixInput = ref('');
const openCashFix = () => { cashFixInput.value = String(props.summary?.cash ?? 0); cashFixOpen.value = true; };
const saveCashFix = () => router.post(route('finance.cashCorrection'), { actual: cashFixInput.value }, {
    preserveScroll: true, onSuccess: () => (cashFixOpen.value = false),
});

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

</script>

<template>
    <Head :title="$e('Финансы')" />
    <FinanceLayout :title="$e('Финансы — обзор')" :subtitle="$e('картина целиком; записи ведутся в разделах')">

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

        <!-- Разделы Финансов — отдельными страницами. Раньше всё это жило
             на одной, и она прокручивалась на четыре экрана. -->
        <div class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <Link :href="route('finance.invoices')" class="group rounded-2xl border border-slate-200 bg-white p-5 shadow-sm transition-shadow hover:shadow-md">
                <div class="text-sm font-semibold text-slate-900">{{ $e('Счета') }} →</div>
                <div class="mt-1 text-xs text-slate-400">{{ $e('выставлено, оплачено, остаток') }}</div>
                <div class="mt-2 text-lg font-bold tabular-nums text-slate-700">{{ money(invoiceTotals.invoiced) }}</div>
            </Link>
            <Link :href="route('finance.receipts')" class="group rounded-2xl border border-slate-200 bg-white p-5 shadow-sm transition-shadow hover:shadow-md">
                <div class="text-sm font-semibold text-slate-900">{{ $e('Поступления денег') }} →</div>
                <div class="mt-1 text-xs text-slate-400">{{ $e('нал и банк, откуда пришли') }}</div>
                <div class="mt-2 text-lg font-bold tabular-nums text-emerald-600">{{ money(summary.incomeManual) }}</div>
            </Link>
            <Link :href="route('expensesBoard.index')" class="group rounded-2xl border border-slate-200 bg-white p-5 shadow-sm transition-shadow hover:shadow-md">
                <div class="text-sm font-semibold text-slate-900">{{ $e('Расходы') }} →</div>
                <div class="mt-1 text-xs text-slate-400">{{ $e('очередь на проверку и оплаченные') }}</div>
                <div class="mt-2 text-lg font-bold tabular-nums text-rose-600">{{ money(summary.expensesTotal) }}</div>
            </Link>
            <Link :href="route('finance.debts')" class="group rounded-2xl border border-slate-200 bg-white p-5 shadow-sm transition-shadow hover:shadow-md">
                <div class="text-sm font-semibold text-slate-900">{{ $e('Задолженности') }} →</div>
                <div class="mt-1 text-xs text-slate-400">{{ $e('кто нам должен') }}</div>
                <div class="mt-2 text-lg font-bold tabular-nums" :class="summary.receivablesTotal > 0 ? 'text-rose-600' : 'text-slate-700'">{{ money(summary.receivablesTotal) }}</div>
            </Link>
        </div>

        <CompanyExpenseModal :show="showCompanyExpense" :categories="categories"
            :cash="Number(summary.cash)" :bank="Number(summary.bank)" @close="showCompanyExpense = false" />
        <ExpenseCategoriesModal :show="showCats" :categories="categories" @close="showCats = false" />
    </FinanceLayout>
</template>
