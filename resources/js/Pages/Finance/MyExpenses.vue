<script setup>
import { ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Modal from '@/Components/Modal.vue';
import { formatDate, money } from '@/utils/format';
import { useE } from '@/composables/useTranslations';

const tr = useE();

const props = defineProps({
    // Заявки, ждущие бухгалтера, — за всё время: деньги сотрудника не должны
    // теряться при переключении месяца.
    pending: { type: Array, default: () => [] },
    paid: { type: Array, default: () => [] },
    payouts: { type: Array, default: () => [] },
    totals: { type: Object, default: () => ({ pending: 0, paid: 0, payouts: 0 }) },
    month: { type: String, default: '' },
    categories: { type: Array, default: () => [] },
});

const month = ref(props.month);
const applyMonth = () => router.get(route('myExpenses.index'), { month: month.value || undefined },
    { preserveState: true, preserveScroll: true, replace: true });

// Способ оплаты выбирает бухгалтер — в заявке его нет; здесь только показываем.
const methodLabel = (m) => (m === 'cash' ? tr('наличные') : m === 'bank' ? tr('банк') : '—');
const payoutLabel = (p) => (p === 'debt' ? tr('Долг') : tr('Аванс'));
const receiptUrl = (id) => route('expenses.receipt', id);

// Новая заявка: способа оплаты в форме НЕТ — сервер всё равно поставит
// «ждёт бухгалтера» (см. ExpenseController::store).
const showForm = ref(false);
const form = useForm({ category_id: '', amount: '', date: new Date().toISOString().slice(0, 10), description: '', file: null });
const openForm = () => {
    form.reset();
    form.clearErrors();
    form.date = new Date().toISOString().slice(0, 10);
    showForm.value = true;
};
const submit = () => form.post(route('expenses.store'), {
    preserveScroll: true,
    onSuccess: () => (showForm.value = false),
});
</script>

<template>
    <Head :title="$e('Мои расходы')" />
    <AppLayout>
        <template #header>{{ $e('Мои расходы') }}</template>

        <div class="mx-auto max-w-5xl">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
                <div class="flex items-center gap-2">
                    <span class="text-xs text-slate-400">{{ $e('Месяц:') }}</span>
                    <input v-model="month" @change="applyMonth" type="month"
                        class="rounded-lg border-slate-300 py-1.5 text-sm shadow-sm transition focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20" />
                </div>
                <button @click="openForm"
                    class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition-colors duration-150 hover:bg-indigo-700">{{ $e('+ Заявка') }}</button>
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <div class="rounded-2xl border border-amber-200 bg-amber-50 p-5 shadow-sm">
                    <div class="text-2xl font-bold tabular-nums text-amber-700">{{ money(totals.pending) }}</div>
                    <div class="mt-1 text-xs text-amber-600">{{ $e('Ждёт бухгалтера') }}</div>
                    <div class="mt-0.5 text-[11px] text-amber-500">{{ $e('за всё время — заявки не теряются') }}</div>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="text-2xl font-bold tabular-nums text-emerald-600">{{ money(totals.paid) }}</div>
                    <div class="mt-1 text-xs text-slate-400">{{ $e('Оплачено за месяц') }}</div>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="text-2xl font-bold tabular-nums text-slate-800">{{ money(totals.payouts) }}</div>
                    <div class="mt-1 text-xs text-slate-400">{{ $e('Мне выдано за месяц') }}</div>
                </div>
            </div>

            <!-- ================= Мои заявки ================= -->
            <div class="mt-6 rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-6 py-4">
                    <h3 class="text-sm font-semibold text-slate-900">{{ $e('Мои заявки') }}</h3>
                    <span v-if="pending.length" class="rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-medium text-amber-700">
                        {{ $e('ждут оплаты:') }} <b class="tabular-nums">{{ pending.length }}</b>
                    </span>
                </div>

                <div v-if="!pending.length && !paid.length" class="px-6 py-10 text-center text-sm text-slate-400">
                    {{ $e('Заявок пока нет — нажмите «+ Заявка».') }}
                </div>

                <ul v-else class="divide-y divide-slate-50">
                    <li v-for="e in [...pending, ...paid]" :key="e.id"
                        class="group flex flex-wrap items-center gap-x-4 gap-y-1 px-6 py-3 transition-colors duration-150 hover:bg-slate-50/60">
                        <span class="w-24 shrink-0 text-xs text-slate-400">{{ formatDate(e.date) }}</span>
                        <span class="w-28 shrink-0 text-right text-sm font-semibold tabular-nums text-slate-800">{{ money(e.amount) }}</span>
                        <span v-if="e.category" class="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-500">{{ e.category }}</span>
                        <span class="min-w-0 flex-1 truncate text-sm text-slate-500">{{ e.description || '—' }}</span>
                        <a v-if="e.has_receipt" :href="receiptUrl(e.id)" target="_blank"
                            class="text-xs font-medium text-indigo-600 opacity-0 transition-opacity hover:underline group-hover:opacity-100">{{ $e('Посмотреть чек') }}</a>
                        <span v-if="e.status === 'confirmed'" class="rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-700">
                            {{ $e('Оплачен') }} · {{ methodLabel(e.payment_method) }}
                        </span>
                        <span v-else class="rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-medium text-amber-700">{{ $e('Ждёт бухгалтера') }}</span>
                    </li>
                </ul>
            </div>

            <!-- ================= Мне выдано (аванс, долг) ================= -->
            <div class="mt-6 rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-100 px-6 py-4">
                    <h3 class="text-sm font-semibold text-slate-900">{{ $e('Мне выдано') }}</h3>
                    <p class="mt-0.5 text-xs text-slate-400">{{ $e('Авансы и долги, выданные бухгалтерией за выбранный месяц.') }}</p>
                </div>
                <div v-if="!payouts.length" class="px-6 py-10 text-center text-sm text-slate-400">
                    {{ $e('За этот месяц выдач не было.') }}
                </div>
                <div v-else class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-400">
                            <tr>
                                <th class="px-6 py-2.5">{{ $e('Дата') }}</th>
                                <th class="px-4 py-2.5">{{ $e('Вид') }}</th>
                                <th class="px-4 py-2.5 text-right">{{ $e('Сумма') }}</th>
                                <th class="px-4 py-2.5">{{ $e('Как выдано') }}</th>
                                <th class="px-4 py-2.5">{{ $e('За что') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-50">
                            <tr v-for="p in payouts" :key="p.id" class="transition-colors duration-150 hover:bg-slate-50/60">
                                <td class="px-6 py-2.5 text-slate-500">{{ formatDate(p.date) }}</td>
                                <td class="px-4 py-2.5">
                                    <span class="rounded-full px-2.5 py-0.5 text-xs font-medium"
                                        :class="p.payout === 'debt' ? 'bg-rose-50 text-rose-600' : 'bg-indigo-50 text-indigo-700'">{{ payoutLabel(p.payout) }}</span>
                                </td>
                                <td class="px-4 py-2.5 text-right font-semibold tabular-nums text-slate-800">{{ money(p.amount) }}</td>
                                <td class="px-4 py-2.5 text-slate-500">{{ methodLabel(p.payment_method) }}</td>
                                <td class="px-4 py-2.5 text-slate-500">{{ p.description || '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ================= Новая заявка ================= -->
        <Modal :show="showForm" @close="showForm = false" max-width="lg">
            <div class="p-6">
                <h2 class="mb-1 text-lg font-semibold text-slate-900">{{ $e('Заявка на расход') }}</h2>
                <p class="mb-4 text-xs text-slate-400">{{ $e('Счёт бухгалтеру на оплату: он проверит и оплатит.') }}</p>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-xs font-medium text-slate-500">{{ $e('Категория *') }}</label>
                        <select v-model="form.category_id" class="w-full rounded-lg border-slate-300 text-sm shadow-sm">
                            <option value="">{{ $e('— выберите —') }}</option>
                            <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
                        </select>
                        <div v-if="form.errors.category_id" class="mt-1 text-xs text-red-600">{{ form.errors.category_id }}</div>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">{{ $e('Сумма, ₸ *') }}</label>
                        <input v-model="form.amount" type="number" min="0.01" step="0.01" class="w-full rounded-lg border-slate-300 text-sm shadow-sm" />
                        <div v-if="form.errors.amount" class="mt-1 text-xs text-red-600">{{ form.errors.amount }}</div>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">{{ $e('Дата *') }}</label>
                        <input v-model="form.date" type="date" class="w-full rounded-lg border-slate-300 text-sm shadow-sm" />
                        <div v-if="form.errors.date" class="mt-1 text-xs text-red-600">{{ form.errors.date }}</div>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-xs font-medium text-slate-500">{{ $e('За что') }}</label>
                        <input v-model="form.description" type="text" class="w-full rounded-lg border-slate-300 text-sm shadow-sm" :placeholder="$e('Бензин, канцтовары, ремонт…')" />
                        <div v-if="form.errors.description" class="mt-1 text-xs text-red-600">{{ form.errors.description }}</div>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-xs font-medium text-slate-500">{{ $e('Чек (фото или PDF)') }}</label>
                        <input type="file" accept="image/*,application/pdf" @input="form.file = $event.target.files[0]"
                            class="w-full text-sm text-slate-500 file:mr-3 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-slate-600" />
                        <div v-if="form.errors.file" class="mt-1 text-xs text-red-600">{{ form.errors.file }}</div>
                    </div>
                </div>
                <div class="mt-6 flex justify-end gap-2">
                    <button type="button" @click="showForm = false"
                        class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-600 transition-colors duration-150 hover:bg-slate-50">{{ $e('Отмена') }}</button>
                    <button type="button" @click="submit" :disabled="form.processing"
                        class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition-colors duration-150 hover:bg-indigo-700 disabled:opacity-50">{{ $e('Отправить бухгалтеру') }}</button>
                </div>
            </div>
        </Modal>
    </AppLayout>
</template>
