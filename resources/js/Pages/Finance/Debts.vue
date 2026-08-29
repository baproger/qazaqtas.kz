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
    debts: { type: Object, default: () => ({ receivables: [], payables: [] }) },
    totals: { type: Object, default: () => ({ invoices: 0, receivablesManual: 0, receivablesTotal: 0, payables: 0 }) },
    canManage: { type: Boolean, default: false },
    // Незакрытые счета сделок: кто, по какому счёту, сколько осталось.
    invoiceDebts: { type: Array, default: () => [] },
});
const invoicesOpen = ref(true);
const overdueCount = computed(() => props.invoiceDebts.filter((i) => i.overdue).length);

// Дебиторка (нам должны) / кредиторка (мы должны): ручные строки поверх
// автоматического долга по счетам сделок.
const debtOpen = ref({ receivable: true, payable: false });
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
    if (debtEditing.value) dForm.put(route('finance.debts.update', debtEditing.value.id), opts);
    else dForm.post(route('finance.debts.store'), opts);
};
const delDebt = async (d) => {
    if (!(await confirmDialog({
        title: tr('Удалить запись задолженности'),
        message: `«${d.counterparty}» на ${money(d.amount)} — СЕО и директор получат уведомление.`,
        confirmText: tr('Удалить'),
        danger: true,
    }))) return;
    router.delete(route('finance.debts.destroy', d.id), { preserveScroll: true });
};
</script>

<template>
    <Head :title="$e('Задолженности')" />
    <FinanceLayout :title="$e('Задолженности')" :subtitle="$e('кто нам должен: счета сделок и записи вручную')">
        <!-- ================= Задолженности (аккордеоны) =================
             Кредиторка скрыта по просьбе владельца (24.07.2026) — вернуть:
             добавить обратно строку { type: 'payable', … } в массив. -->
        <div class="grid grid-cols-1 items-start gap-4">
            <div v-for="acc in [
                    { type: 'receivable', title: $e('Дебиторская задолженность — кто нам должен'), list: debts.receivables, total: totals.receivablesTotal, color: 'rose' },
                ]" :key="acc.type" class="rounded-2xl border border-slate-100 bg-white shadow-sm">
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
                    <div v-if="acc.type === 'receivable'" class="mb-3 overflow-hidden rounded-xl border border-slate-100">
                        <button type="button" @click="invoicesOpen = !invoicesOpen"
                            class="flex w-full items-center justify-between gap-3 bg-slate-50 px-3 py-2 text-left text-sm">
                            <span class="flex items-center gap-2 text-slate-600">
                                <svg class="h-3.5 w-3.5 text-slate-400 transition-transform" :class="invoicesOpen ? 'rotate-90' : ''" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M8 5l5 5-5 5"/></svg>
                                {{ $e('По счетам сделок (автоматически)') }}
                                <span class="rounded-full bg-white px-2 py-0.5 text-xs text-slate-500 ring-1 ring-slate-200">{{ invoiceDebts.length }}</span>
                                <span v-if="overdueCount" class="rounded-full bg-rose-100 px-2 py-0.5 text-xs font-semibold text-rose-700">{{ $e('просрочено:') }} {{ overdueCount }}</span>
                            </span>
                            <span class="font-semibold tabular-nums text-slate-700">{{ money(totals.invoices) }}</span>
                        </button>
                        <div v-show="invoicesOpen" class="overflow-x-auto">
                            <table v-if="invoiceDebts.length" class="min-w-full divide-y divide-slate-100 text-sm">
                                <thead class="bg-white text-left text-xs uppercase tracking-wide text-slate-400">
                                    <tr>
                                        <th class="px-3 py-2">{{ $e('Сделка') }}</th>
                                        <th class="px-3 py-2">{{ $e('Заказчик') }}</th>
                                        <th class="px-3 py-2">{{ $e('Счёт') }}</th>
                                        <th class="px-3 py-2 text-right">{{ $e('Выставлено') }}</th>
                                        <th class="px-3 py-2 text-right">{{ $e('Оплачено') }}</th>
                                        <th class="px-3 py-2 text-right">{{ $e('Остаток') }}</th>
                                        <th class="px-3 py-2">{{ $e('Срок оплаты') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-50">
                                    <tr v-for="i in invoiceDebts" :key="i.id" class="hover:bg-slate-50/60">
                                        <td class="whitespace-nowrap px-3 py-2">
                                            <Link v-if="i.deal?.id" :href="route('deals.show', i.deal.id)" class="font-semibold text-indigo-600 hover:underline">{{ i.deal.number }}</Link>
                                            <span v-else class="text-slate-400">{{ i.deal?.number ?? '—' }}</span>
                                        </td>
                                        <td class="max-w-64 px-3 py-2">
                                            <div class="truncate font-medium text-slate-800">{{ i.deal?.company ?? '—' }}</div>
                                            <div v-if="i.deal?.client" class="truncate text-xs text-slate-400">{{ i.deal.client }}</div>
                                        </td>
                                        <td class="whitespace-nowrap px-3 py-2 text-slate-500">{{ i.number }}<span class="block text-xs text-slate-400">{{ formatDate(i.issue_date) }}</span></td>
                                        <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums text-slate-600">{{ money(i.amount) }}</td>
                                        <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums text-emerald-600">{{ money(i.paid) }}</td>
                                        <td class="whitespace-nowrap px-3 py-2 text-right font-semibold tabular-nums text-rose-600">{{ money(i.left) }}</td>
                                        <td class="whitespace-nowrap px-3 py-2">
                                            <span v-if="i.overdue" class="rounded-full bg-rose-100 px-2 py-0.5 text-xs font-semibold text-rose-700">{{ formatDate(i.due_date) }} · {{ i.days_overdue }} {{ $e('дн.') }}</span>
                                            <span v-else-if="i.due_date" class="text-slate-500">{{ formatDate(i.due_date) }}</span>
                                            <span v-else class="text-slate-300">—</span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                            <div v-else class="py-3 text-center text-xs text-slate-300">{{ $e('Незакрытых счетов нет') }}</div>
                        </div>
                    </div>
                    <div class="px-1 pb-1 text-xs font-medium uppercase tracking-wide text-slate-400">{{ $e('Записи вручную') }}</div>
                    <div class="divide-y divide-slate-50">
                        <div v-for="d in acc.list" :key="d.id" class="flex items-center justify-between gap-3 py-2.5 text-sm">
                            <div class="min-w-0">
                                <div class="truncate font-medium text-slate-800">{{ d.counterparty }}</div>
                                <div class="text-xs text-slate-400">
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

        <!-- Модалка: запись задолженности -->
        <Modal :show="showDebt" @close="showDebt = false" max-width="lg">
            <div class="p-6">
                <h2 class="mb-4 text-lg font-semibold text-slate-900">
                    {{ debtEditing ? $e('Изменить запись') : $e('Новая запись задолженности') }}
                </h2>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-xs font-medium text-slate-500">{{ $e('Контрагент *') }}</label>
                        <input v-model="dForm.counterparty" type="text" class="w-full rounded-md border-slate-300 text-sm shadow-sm" />
                        <div v-if="dForm.errors.counterparty" class="mt-1 text-xs text-red-600">{{ dForm.errors.counterparty }}</div>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">{{ $e('Сумма, ₸ *') }}</label>
                        <input v-model="dForm.amount" type="number" min="0.01" step="0.01" class="w-full rounded-md border-slate-300 text-sm shadow-sm" />
                        <div v-if="dForm.errors.amount" class="mt-1 text-xs text-red-600">{{ dForm.errors.amount }}</div>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">{{ $e('Дата') }}</label>
                        <input v-model="dForm.date" type="date" class="w-full rounded-md border-slate-300 text-sm shadow-sm" />
                    </div>
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-xs font-medium text-slate-500">{{ $e('Комментарий') }}</label>
                        <input v-model="dForm.note" type="text" class="w-full rounded-md border-slate-300 text-sm shadow-sm" />
                    </div>
                </div>
                <div class="mt-6 flex justify-end gap-2">
                    <SecondaryButton @click="showDebt = false">{{ $e('Отмена') }}</SecondaryButton>
                    <PrimaryButton :disabled="dForm.processing" @click="submitDebt">{{ $e('Сохранить') }}</PrimaryButton>
                </div>
            </div>
        </Modal>
    </FinanceLayout>
</template>
