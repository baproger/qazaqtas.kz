<script setup>
import { computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import FinanceLayout from '@/Layouts/FinanceLayout.vue';
import { formatDate, money } from '@/utils/format';
import FinanceTile from '@/Components/FinanceTile.vue';
import { useE } from '@/composables/useTranslations';

const tr = useE();

const props = defineProps({
    date: { type: String, default: '' },
    mode: { type: String, default: 'cash' },
    rows: { type: Array, default: () => [] },
    totals: { type: Object, default: () => ({ opening: 0, income: 0, outcome: 0, closing: 0 }) },
    cashCorrection: { type: Number, default: 0 },
});

const go = (params) => router.get(route('cashBook.index'), { date: props.date, mode: props.mode, ...params },
    { preserveState: true, preserveScroll: true, replace: true });

const shiftDay = (days) => {
    const d = new Date(props.date);
    d.setDate(d.getDate() + days);
    go({ date: d.toISOString().slice(0, 10) });
};
const today = () => go({ date: new Date().toISOString().slice(0, 10) });
const isToday = computed(() => props.date === new Date().toISOString().slice(0, 10));

const modes = [
    { key: 'cash', label: tr('Наличные') },
    { key: 'bank', label: tr('Банк') },
    { key: 'all', label: tr('Общее') },
];

const typeLabel = (r) => (r.type === 'invoice' ? tr('Счёт') : r.type === 'receipt' ? tr('Поступление') : tr('Расход'));
const typeClass = (r) => (r.type === 'expense' ? 'bg-rose-50 text-rose-600' : r.type === 'receipt' ? 'bg-emerald-50 text-emerald-700' : 'bg-sky-50 text-sky-700');
const payoutLabel = (p) => (p === 'debt' ? tr('Долг') : tr('Аванс'));
const kindLabel = (k) => (k === 'cash' ? tr('наличные') : tr('банк'));
const time = (at) => (at ? String(at).slice(11, 16) : '');
const printPage = () => window.print();
</script>

<template>
    <Head :title="$e('Касса')" />
    <FinanceLayout :title="$e('Касса')" :subtitle="$e('кассовая книга за день: начало → операции → конец')" width="max-w-7xl">
            <!-- Панель дня: печать снимает её вместе с меню (см. print-стили). -->
            <div class="no-print mb-4 flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-1">
                    <button @click="shiftDay(-1)" class="rounded-lg border border-slate-200 px-2.5 py-1.5 text-sm text-slate-600 transition-colors duration-150 hover:bg-slate-50">←</button>
                    <input :value="date" @change="go({ date: $event.target.value })" type="date"
                        class="rounded-lg border-slate-300 py-1.5 text-sm shadow-sm transition focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20" />
                    <button @click="shiftDay(1)" class="rounded-lg border border-slate-200 px-2.5 py-1.5 text-sm text-slate-600 transition-colors duration-150 hover:bg-slate-50">→</button>
                    <button v-if="!isToday" @click="today" class="ml-1 rounded-lg px-3 py-1.5 text-xs font-medium text-slate-500 transition-colors duration-150 hover:bg-slate-100">{{ $e('Сегодня') }}</button>
                </div>
                <div class="flex items-center gap-2">
                    <div class="inline-flex rounded-lg border border-slate-200 bg-white p-0.5">
                        <button v-for="m in modes" :key="m.key" @click="go({ mode: m.key })"
                            class="rounded-md px-3 py-1.5 text-xs font-semibold transition-colors duration-150"
                            :class="mode === m.key ? 'bg-indigo-600 text-white' : 'text-slate-500 hover:bg-slate-50'">{{ m.label }}</button>
                    </div>
                    <button @click="printPage"
                        class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-600 transition-colors duration-150 hover:bg-slate-50">🖨 {{ $e('Печать') }}</button>
                </div>
            </div>

            <!-- Шапка печатной формы: на экране не нужна, на бумаге обязательна. -->
            <div class="print-only mb-4">
                <div class="text-lg font-bold">{{ $e('Отчёт кассира') }}</div>
                <div class="text-sm">{{ formatDate(date) }} · {{ modes.find((m) => m.key === mode)?.label }}</div>
            </div>

            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <FinanceTile :label="$e('Остаток на начало дня')" :value="money(totals.opening)"
                    :hint="cashCorrection ? $e('· скорректировано') : ''" />
                <FinanceTile tone="good" :label="$e('Приход за день')" :value="'+' + money(totals.income)" />
                <FinanceTile tone="bad" :label="$e('Расход за день')" :value="'−' + money(totals.outcome)" />
                <FinanceTile tone="dark" :label="$e('Доступно сейчас')" :value="money(totals.closing)" />
            </div>

            <div class="mt-6 rounded-2xl border border-slate-100 bg-white shadow-sm">
                <div class="flex items-center justify-between border-b border-slate-100 px-6 py-4">
                    <h3 class="text-sm font-semibold text-slate-900">{{ $e('Операции за день') }}</h3>
                    <span class="no-print text-xs text-slate-400">{{ formatDate(date) }}</span>
                </div>

                <div v-if="!rows.length" class="px-6 py-10 text-center text-sm text-slate-400">
                    {{ $e('В этот день денег не двигали.') }}
                </div>
                <div v-else class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-400">
                            <tr>
                                <th class="px-6 py-2.5">{{ $e('Время') }}</th>
                                <th class="px-4 py-2.5">{{ $e('Операция') }}</th>
                                <th class="px-4 py-2.5">{{ $e('Контрагент') }}</th>
                                <th class="px-4 py-2.5 text-right">{{ $e('Сумма') }}</th>
                                <th class="px-4 py-2.5 text-right">{{ $e('Остаток') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-50">
                            <tr v-for="r in rows" :key="r.id" class="transition-colors duration-150 hover:bg-slate-50/60">
                                <td class="px-6 py-2.5 text-slate-400 tabular-nums">{{ time(r.at) }}</td>
                                <td class="px-4 py-2.5">
                                    <span class="rounded-full px-2.5 py-0.5 text-xs font-medium" :class="typeClass(r)">{{ typeLabel(r) }}</span>
                                    <span v-if="r.payout" class="ml-1 rounded-full px-2 py-0.5 text-[11px] font-medium"
                                        :class="r.payout === 'debt' ? 'bg-rose-50 text-rose-600' : 'bg-indigo-50 text-indigo-700'">{{ payoutLabel(r.payout) }}</span>
                                    <span v-if="mode === 'all'" class="ml-1 text-[11px] text-slate-400">{{ kindLabel(r.kind) }}</span>
                                    <div class="mt-0.5 text-slate-600">
                                        <Link v-if="r.link" :href="r.link" class="hover:text-indigo-600 hover:underline">{{ r.title }}</Link>
                                        <template v-else>{{ r.title }}</template>
                                    </div>
                                </td>
                                <td class="px-4 py-2.5 text-slate-500">
                                    <Link v-if="r.employee" :href="route('users.show', r.employee.id)" class="hover:text-indigo-600 hover:underline">{{ r.employee.name }}</Link>
                                    <template v-else>{{ r.party || '—' }}</template>
                                </td>
                                <td class="px-4 py-2.5 text-right font-semibold tabular-nums"
                                    :class="r.sign > 0 ? 'text-emerald-600' : 'text-rose-600'">{{ r.sign > 0 ? '+' : '−' }}{{ money(r.amount) }}</td>
                                <td class="px-4 py-2.5 text-right tabular-nums text-slate-500">{{ money(r.balance) }}</td>
                            </tr>
                        </tbody>
                        <tfoot class="border-t border-slate-200 bg-slate-50 text-sm font-semibold">
                            <tr>
                                <td class="px-6 py-3 text-slate-500" colspan="3">{{ $e('Остаток на конец дня') }}</td>
                                <td class="px-4 py-3 text-right tabular-nums text-slate-400">
                                    +{{ money(totals.income) }} · −{{ money(totals.outcome) }}
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums text-slate-900">{{ money(totals.closing) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <!-- Подписи кассира и бухгалтера — так выглядит бумажный отчёт. -->
            <div class="print-only mt-8 grid grid-cols-2 gap-8 text-sm">
                <div>{{ $e('Кассир') }} ______________________</div>
                <div>{{ $e('Бухгалтер') }} ______________________</div>
            </div>
    </FinanceLayout>
</template>

<style>
/* Печать: страница заменяет бумажный «Отчёт кассира», поэтому с листа
   уходят меню, шапка и кнопки, а лента остаётся чёрно-белой с итогами.
   Стили НЕ scoped намеренно: они прячут AppLayout (aside/header), который
   этому компоненту не принадлежит. Классы `.no-print` / `.print-only`
   становятся общим правилом печати для всей ERP — печатать меню не нужно
   нигде. */
.print-only {
    display: none;
}

@media print {
    aside,
    header,
    .no-print {
        display: none !important;
    }

    .print-only {
        display: block;
    }

    main {
        padding: 0 !important;
    }

    body {
        background: #fff;
    }

    table {
        font-size: 11pt;
    }
}
</style>
