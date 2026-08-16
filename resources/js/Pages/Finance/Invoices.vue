<script setup>
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import FinanceLayout from '@/Layouts/FinanceLayout.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import FinanceTile from '@/Components/FinanceTile.vue';
import { formatDate } from '@/utils/format';
import { useE } from '@/composables/useTranslations';

const tr = useE();
const money = (v) => new Intl.NumberFormat('ru-RU').format(Math.round(v ?? 0)) + ' ₸';

const props = defineProps({
    invoicesToday: { type: Array, default: () => [] },
    invoicesPast: { type: Array, default: () => [] },
    invoicesPastStats: { type: Object, default: () => ({ count: 0, sum: 0 }) },
    invoiceTotals: { type: Object, default: () => ({ invoiced: 0, paid: 0, debt: 0 }) },
    filters: { type: Object, default: () => ({}) },
    canManage: { type: Boolean, default: false },
});

// Прошлые счета: аккордеон + поиск по номеру.
const invPastOpen = ref(!!props.filters?.search);
const invSearch = ref(props.filters?.search ?? '');
const applyInvFilters = () => router.get(route('finance.invoices'),
    { search: invSearch.value || undefined },
    { preserveState: true, preserveScroll: true, replace: true, onSuccess: () => (invPastOpen.value = true) });
const resetInvFilters = () => { invSearch.value = ''; applyInvFilters(); };
</script>

<template>
    <Head :title="$e('Счета')" />
    <FinanceLayout :title="$e('Счета')" :subtitle="$e('выставлено, оплачено, остаток к оплате')" width="max-w-7xl">
            <!-- Плитки: выставлено / оплачено / остаток к оплате -->
            <div class="mb-4 grid gap-3 sm:grid-cols-3">
                <FinanceTile :label="$e('Выставлено')" :value="money(invoiceTotals.invoiced)" />
                <FinanceTile tone="good" :label="$e('Оплачено')" :value="money(invoiceTotals.paid)" />
                <FinanceTile :tone="invoiceTotals.debt > 0 ? 'bad' : 'default'" :label="$e('Осталось оплатить')"
                    :value="money(invoiceTotals.debt)" :hint="$e('отменённые счета сюда не входят')" />
            </div>

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
    </FinanceLayout>
</template>
