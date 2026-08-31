<script setup>
import { ref, computed } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import FinanceLayout from '@/Layouts/FinanceLayout.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { formatDate, formatDateTime } from '@/utils/format';
import { confirmDialog } from '@/composables/useConfirm';
import { useE } from '@/composables/useTranslations';

const tr = useE();
const money = (v) => new Intl.NumberFormat('ru-RU').format(Math.round(v ?? 0)) + ' ₸';

const props = defineProps({
    receiptsToday: { type: Array, default: () => [] },
    receiptsPast: { type: Array, default: () => [] },
    receiptsPastStats: { type: Object, default: () => ({ count: 0, sum: 0 }) },
    totals: { type: Object, default: () => ({ cash: 0, bank: 0 }) },
    filters: { type: Object, default: () => ({}) },
    canManage: { type: Boolean, default: false },
});

// Поступление денег (финансист): сумма, нал/банк, откуда, дата, комментарий.
const showReceipt = ref(false);
const rForm = useForm({ amount: '', method: 'bank', source: '', date: new Date().toISOString().slice(0, 10), note: '' });
const openReceipt = () => { rForm.reset(); rForm.date = new Date().toISOString().slice(0, 10); showReceipt.value = true; };
const submitReceipt = () => rForm.post(route('finance.receipts.store'), { preserveScroll: true, onSuccess: () => (showReceipt.value = false) });
const delReceipt = (r) => router.delete(route('finance.receipts.destroy', r.id), { preserveScroll: true });

// Прошлые поступления: аккордеон снизу, фильтр серверный (поиск + период).
const pastOpen = ref(!!(props.filters?.rc_search || props.filters?.rc_from || props.filters?.rc_to));
const rcSearch = ref(props.filters?.rc_search ?? '');
const rcFrom = ref(props.filters?.rc_from ?? '');
const rcTo = ref(props.filters?.rc_to ?? '');
const applyRcFilters = () => router.get(route('finance.receipts'), {
    rc_search: rcSearch.value || undefined,
    rc_from: rcFrom.value || undefined,
    rc_to: rcTo.value || undefined,
}, { preserveState: true, preserveScroll: true, replace: true });
const resetRcFilters = () => { rcSearch.value = ''; rcFrom.value = ''; rcTo.value = ''; applyRcFilters(); };
const todaySum = computed(() => (props.receiptsToday ?? []).reduce((sum, r) => sum + Number(r.amount || 0), 0));
// Итог «всего» на плашке: сумма кассы и счёта за всё время.
const allTime = computed(() => Number(props.totals.cash || 0) + Number(props.totals.bank || 0));
</script>

<template>
    <Head :title="$e('Поступления денег')" />
    <FinanceLayout :title="$e('Поступления денег')" :subtitle="$e('нал и банк: откуда пришли деньги')" width="max-w-7xl">
        <!-- ================= Поступления денег ================= -->
        <div class="mt-6 rounded-2xl border border-slate-100 dark:border-slate-800 bg-white dark:bg-slate-900/70 shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b px-6 py-4">
                <div class="flex items-center gap-3">
                    <h3 class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ $e('Поступления денег') }}</h3>
                    <span class="rounded-full bg-emerald-100 dark:bg-emerald-500/20 px-2.5 py-1 text-xs font-medium text-emerald-700 dark:text-emerald-400">{{ $e('сегодня') }} <b class="tabular-nums whitespace-nowrap">{{ money(todaySum) }}</b></span>
                    <span class="hidden rounded-full bg-slate-100 dark:bg-slate-800/60 px-2.5 py-1 text-xs font-medium text-slate-500 dark:text-slate-400 sm:inline-flex">{{ $e('всего') }} <b class="ml-1 tabular-nums whitespace-nowrap">{{ money(allTime) }}</b></span>
                </div>
                <button v-if="canManage" @click="openReceipt"
                    class="rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-emerald-700">{{ $e('+ Поступление') }}</button>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full whitespace-nowrap divide-y divide-slate-100 dark:divide-slate-800 text-sm">
                    <thead class="bg-slate-50 dark:bg-slate-800/50 text-left text-xs uppercase tracking-wide text-slate-400">
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
                    <tbody class="divide-y divide-slate-50 dark:divide-slate-800">
                        <tr v-for="r in receiptsToday" :key="r.id" class="hover:bg-slate-50 dark:hover:bg-slate-800/60">
                            <td class="px-6 py-3 text-slate-500 dark:text-slate-400">{{ formatDate(r.date) }}<span class="block text-xs text-slate-400">{{ $e('внесено') }} {{ formatDateTime(r.created_at) }}</span></td>
                            <td class="px-4 py-3 text-right font-semibold tabular-nums whitespace-nowrap text-emerald-600 dark:text-emerald-400">+ {{ money(r.amount) }}</td>
                            <td class="px-4 py-3">
                                <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="r.method === 'cash' ? 'bg-emerald-100 dark:bg-emerald-500/20 text-emerald-700 dark:text-emerald-400' : 'bg-sky-100 dark:bg-sky-500/20 text-sky-700 dark:text-sky-400'">{{ r.method === 'cash' ? $e('наличные') : $e('банк (счёт)') }}</span>
                            </td>
                            <td class="max-w-56 truncate px-4 py-3 font-medium text-slate-800 dark:text-slate-200" :title="r.source">{{ r.source }}</td>
                            <td class="max-w-56 truncate px-4 py-3 text-slate-500 dark:text-slate-400" :title="r.note">{{ r.note || '—' }}</td>
                            <td class="px-4 py-3 text-xs text-slate-400">{{ r.creator?.name ?? '—' }}</td>
                            <td v-if="canManage" class="px-4 py-3 text-right">
                                <button class="text-slate-300 dark:text-slate-600 transition hover:text-rose-600" :title="$e('Удалить поступление')" @click="delReceipt(r)">✕</button>
                            </td>
                        </tr>
                        <tr v-if="!receiptsToday.length"><td colspan="7" class="px-6 py-8 text-center text-sm text-slate-400">{{ $e('Сегодня поступлений не было — «+ Поступление»') }}</td></tr>
                    </tbody>
                </table>
            </div>

            <!-- Прошлые поступления: аккордеон с поиском и периодом -->
            <div class="border-t border-slate-100 dark:border-slate-800">
                <button type="button" @click="pastOpen = !pastOpen" class="flex w-full items-center justify-between gap-3 px-6 py-3.5 text-left">
                    <div class="flex min-w-0 items-center gap-2">
                        <svg class="h-4 w-4 flex-shrink-0 text-slate-400 transition-transform" :class="pastOpen ? 'rotate-90' : ''" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M8 5l5 5-5 5"/></svg>
                        <span class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ $e('Прошлые поступления') }}</span>
                    </div>
                    <span class="flex-shrink-0 rounded-full bg-slate-100 dark:bg-slate-800/60 px-2.5 py-1 text-xs font-bold tabular-nums whitespace-nowrap text-slate-600 dark:text-slate-300">{{ receiptsPastStats?.count ?? 0 }} · {{ money(receiptsPastStats?.sum) }}</span>
                </button>
                <div v-show="pastOpen" class="border-t border-slate-100 dark:border-slate-800">
                    <div class="flex flex-wrap items-center gap-2 px-6 py-3">
                        <input v-model="rcSearch" @keyup.enter="applyRcFilters" type="text" :placeholder="$e('Поиск: откуда / комментарий')"
                            class="w-56 rounded-lg border-slate-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500" />
                        <input v-model="rcFrom" type="date" class="rounded-lg border-slate-300 text-sm shadow-sm" :title="$e('Период с')" />
                        <span class="text-xs text-slate-400">—</span>
                        <input v-model="rcTo" type="date" class="rounded-lg border-slate-300 text-sm shadow-sm" :title="$e('Период по')" />
                        <button @click="applyRcFilters" class="rounded-lg bg-slate-800 px-3 py-2 text-xs font-semibold text-white transition hover:bg-slate-900">{{ $e('Найти') }}</button>
                        <button v-if="filters?.rc_search || filters?.rc_from || filters?.rc_to" @click="resetRcFilters"
                            class="rounded-lg px-3 py-2 text-xs font-medium text-slate-500 dark:text-slate-400 transition hover:bg-slate-100 dark:hover:bg-slate-800/60">{{ $e('Сбросить') }}</button>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full whitespace-nowrap divide-y divide-slate-100 dark:divide-slate-800 text-sm">
                            <tbody class="divide-y divide-slate-50 dark:divide-slate-800">
                                <tr v-for="r in receiptsPast" :key="r.id" class="hover:bg-slate-50 dark:hover:bg-slate-800/60">
                                    <td class="px-6 py-3 text-slate-500 dark:text-slate-400">{{ formatDate(r.date) }}<span class="block text-xs text-slate-400">{{ $e('внесено') }} {{ formatDateTime(r.created_at) }}</span></td>
                                    <td class="px-4 py-3 text-right font-semibold tabular-nums whitespace-nowrap text-emerald-600 dark:text-emerald-400">+ {{ money(r.amount) }}</td>
                                    <td class="px-4 py-3">
                                        <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="r.method === 'cash' ? 'bg-emerald-100 dark:bg-emerald-500/20 text-emerald-700 dark:text-emerald-400' : 'bg-sky-100 dark:bg-sky-500/20 text-sky-700 dark:text-sky-400'">{{ r.method === 'cash' ? $e('наличные') : $e('банк (счёт)') }}</span>
                                    </td>
                                    <td class="max-w-56 truncate px-4 py-3 font-medium text-slate-800 dark:text-slate-200" :title="r.source">{{ r.source }}</td>
                                    <td class="max-w-56 truncate px-4 py-3 text-slate-500 dark:text-slate-400" :title="r.note">{{ r.note || '—' }}</td>
                                    <td class="px-4 py-3 text-xs text-slate-400">{{ r.creator?.name ?? '—' }}</td>
                                    <td v-if="canManage" class="px-4 py-3 text-right">
                                        <button class="text-slate-300 dark:text-slate-600 transition hover:text-rose-600" :title="$e('Удалить поступление')" @click="delReceipt(r)">✕</button>
                                    </td>
                                </tr>
                                <tr v-if="!receiptsPast.length"><td colspan="7" class="px-6 py-6 text-center text-sm text-slate-400">{{ $e('Прошлых поступлений не найдено') }}</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>

        <!-- Модалка: поступление денег -->
        <Modal :show="showReceipt" @close="showReceipt = false" max-width="lg">
            <div class="p-6">
                <h2 class="mb-1 text-lg font-semibold text-slate-900 dark:text-slate-100">{{ $e('Поступление денег') }}</h2>
                <p class="mb-4 text-xs text-slate-400">{{ $e('Откуда пришли деньги и куда легли — в кассу (нал) или на счёт (банк). Остатки на плитках пересчитаются сразу.') }}</p>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500 dark:text-slate-400">{{ $e('Сумма, ₸ *') }}</label>
                        <input v-model="rForm.amount" type="number" min="0.01" step="0.01" class="w-full rounded-md border-slate-300 text-sm shadow-sm" />
                        <div v-if="rForm.errors.amount" class="mt-1 text-xs text-red-600 dark:text-rose-400">{{ rForm.errors.amount }}</div>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500 dark:text-slate-400">{{ $e('Дата *') }}</label>
                        <input v-model="rForm.date" type="date" class="w-full rounded-md border-slate-300 text-sm shadow-sm" />
                        <div v-if="rForm.errors.date" class="mt-1 text-xs text-red-600 dark:text-rose-400">{{ rForm.errors.date }}</div>
                    </div>
                    <div class="sm:col-span-2 flex gap-2">
                        <button type="button" @click="rForm.method = 'cash'"
                            class="rounded-lg border px-3 py-1.5 text-xs font-semibold transition-all"
                            :class="rForm.method === 'cash' ? 'border-emerald-500 bg-emerald-100 dark:bg-emerald-500/20 text-emerald-700 dark:text-emerald-400 ring-1 ring-emerald-500' : 'border-slate-200 dark:border-slate-800/80 bg-white dark:bg-slate-900/70 text-slate-500 dark:text-slate-400'">{{ $e('В кассу (наличные)') }}</button>
                        <button type="button" @click="rForm.method = 'bank'"
                            class="rounded-lg border px-3 py-1.5 text-xs font-semibold transition-all"
                            :class="rForm.method === 'bank' ? 'border-sky-500 bg-sky-100 dark:bg-sky-500/20 text-sky-700 dark:text-sky-400 ring-1 ring-sky-500' : 'border-slate-200 dark:border-slate-800/80 bg-white dark:bg-slate-900/70 text-slate-500 dark:text-slate-400'">{{ $e('На счёт (банк)') }}</button>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-xs font-medium text-slate-500 dark:text-slate-400">{{ $e('Откуда поступили *') }}</label>
                        <input v-model="rForm.source" type="text" class="w-full rounded-md border-slate-300 text-sm shadow-sm" :placeholder="$e('Клиент / учредитель / кредит / возврат…')" />
                        <div v-if="rForm.errors.source" class="mt-1 text-xs text-red-600 dark:text-rose-400">{{ rForm.errors.source }}</div>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-xs font-medium text-slate-500 dark:text-slate-400">{{ $e('Комментарий') }}</label>
                        <input v-model="rForm.note" type="text" class="w-full rounded-md border-slate-300 text-sm shadow-sm" />
                    </div>
                </div>
                <div class="mt-6 flex justify-end gap-2">
                    <SecondaryButton @click="showReceipt = false">{{ $e('Отмена') }}</SecondaryButton>
                    <PrimaryButton :disabled="rForm.processing || !(Number(rForm.amount) > 0) || !rForm.source" @click="submitReceipt">{{ $e('Сохранить') }}</PrimaryButton>
                </div>
            </div>
        </Modal>
    </FinanceLayout>
</template>
