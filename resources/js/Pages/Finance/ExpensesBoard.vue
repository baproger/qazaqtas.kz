<script setup>
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Modal from '@/Components/Modal.vue';
import Pagination from '@/Components/Pagination.vue';
import { formatDate, money } from '@/utils/format';
import { useE } from '@/composables/useTranslations';

const tr = useE();

const props = defineProps({
    // Очередь на проверку — за всё время: заявка не должна теряться в месяцах.
    pending: { type: Array, default: () => [] },
    pendingTotal: { type: Number, default: 0 },
    paid: { type: Object, default: () => ({ data: [], links: [] }) },
    paidTotal: { type: Number, default: 0 },
    month: { type: String, default: '' },
    canConfirm: { type: Boolean, default: false },
});

const month = ref(props.month);
const applyMonth = () => router.get(route('expensesBoard.index'), { month: month.value || undefined },
    { preserveState: true, preserveScroll: true, replace: true });

const receiptUrl = (id) => route('expenses.receipt', id);
const methodLabel = (m) => (m === 'cash' ? tr('наличные') : m === 'bank' ? tr('банк') : '—');
const payoutLabel = (p) => (p === 'debt' ? tr('Долг') : tr('Аванс'));
const sourceUrl = (s) => (s.type === 'project' ? route('projects.show', s.id) : route('deals.show', s.id));

// Подтверждение — прежним маршрутом расхода: способ оплаты + чек, если его
// ещё нет. Второго пути к деньгам на борде не заводим.
const confirming = ref(null);
const form = useForm({ payment_method: 'cash', file: null, _method: 'patch' });
const openConfirm = (e) => {
    form.reset();
    form.clearErrors();
    confirming.value = e;
};
const submit = () => form.post(route('expenses.confirm', confirming.value.id), {
    preserveScroll: true,
    onSuccess: () => (confirming.value = null),
});
</script>

<template>
    <Head :title="$e('Расходы')" />
    <AppLayout>
        <template #header>{{ $e('Расходы') }}</template>

        <div class="mx-auto max-w-7xl">
            <!-- ================= Очередь на проверку ================= -->
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <div class="flex items-center gap-3">
                    <h3 class="text-sm font-semibold text-slate-900">{{ $e('Требуют проверки') }}</h3>
                    <span v-if="pending.length" class="rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-medium text-amber-700">
                        {{ pending.length }} · <b class="tabular-nums">{{ money(pendingTotal) }}</b>
                    </span>
                </div>
            </div>

            <div v-if="!pending.length" class="rounded-2xl border border-slate-200 bg-white px-6 py-10 text-center text-sm text-slate-400 shadow-sm">
                {{ $e('Очередь пуста ✓') }}
            </div>

            <TransitionGroup v-else name="card" tag="div" class="grid gap-4 lg:grid-cols-2">
                <div v-for="e in pending" :key="e.id" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="text-2xl font-bold tabular-nums text-slate-900">{{ money(e.amount) }}</div>
                            <div class="mt-1 flex flex-wrap items-center gap-2 text-xs text-slate-400">
                                <span>{{ formatDate(e.date) }}</span>
                                <span v-if="e.category" class="rounded-full bg-slate-100 px-2.5 py-0.5 font-medium text-slate-500">{{ e.category }}</span>
                                <Link v-if="e.source" :href="sourceUrl(e.source)" class="font-medium text-indigo-600 hover:underline">
                                    {{ e.source.number || $e('без номера') }}
                                </Link>
                                <span v-else class="rounded-full bg-indigo-50 px-2.5 py-0.5 font-medium text-indigo-700">{{ $e('Расход компании') }}</span>
                            </div>
                        </div>
                        <div class="text-right text-xs text-slate-400">
                            <div>{{ $e('подал') }}</div>
                            <Link v-if="e.author" :href="route('users.show', e.author.id)" class="font-medium text-slate-600 hover:text-indigo-600">{{ e.author.name }}</Link>
                            <span v-else>—</span>
                        </div>
                    </div>

                    <p class="mt-3 text-sm text-slate-600">{{ e.description || $e('без описания') }}</p>

                    <!-- Чек открыт сразу: бухгалтеру не нужно кликать по каждой карточке. -->
                    <a v-if="e.receipt?.kind === 'image'" :href="receiptUrl(e.id)" target="_blank" class="mt-3 block">
                        <img :src="receiptUrl(e.id)" :alt="$e('Чек')" class="max-h-72 w-full rounded-lg border border-slate-100 object-contain" />
                    </a>
                    <a v-else-if="e.receipt" :href="receiptUrl(e.id)" target="_blank"
                        class="mt-3 inline-flex items-center gap-1.5 rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-medium text-slate-600 transition-colors duration-150 hover:bg-slate-50">
                        📄 {{ $e('Чек PDF') }}
                    </a>
                    <p v-else class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-700">{{ $e('Чека нет — приложите его при подтверждении.') }}</p>

                    <div v-if="canConfirm" class="mt-4 flex justify-end">
                        <button @click="openConfirm(e)"
                            class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition-colors duration-150 hover:bg-indigo-700">{{ $e('Проверил, оплатить') }}</button>
                    </div>
                </div>
            </TransitionGroup>

            <!-- ================= Оплаченные за месяц ================= -->
            <div class="mt-8 rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-6 py-4">
                    <div class="flex items-center gap-3">
                        <h3 class="text-sm font-semibold text-slate-900">{{ $e('Оплаченные за месяц') }}</h3>
                        <span class="rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-700 tabular-nums">{{ money(paidTotal) }}</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="text-xs text-slate-400">{{ $e('Месяц:') }}</span>
                        <input v-model="month" @change="applyMonth" type="month"
                            class="rounded-lg border-slate-300 py-1.5 text-sm shadow-sm transition focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20" />
                    </div>
                </div>

                <div v-if="!paid.data.length" class="px-6 py-10 text-center text-sm text-slate-400">
                    {{ $e('За этот месяц оплаченных расходов нет.') }}
                </div>
                <div v-else class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-400">
                            <tr>
                                <th class="px-6 py-2.5">{{ $e('Дата') }}</th>
                                <th class="px-4 py-2.5 text-right">{{ $e('Сумма') }}</th>
                                <th class="px-4 py-2.5">{{ $e('Категория') }}</th>
                                <th class="px-4 py-2.5">{{ $e('За что') }}</th>
                                <th class="px-4 py-2.5">{{ $e('Кто подал') }}</th>
                                <th class="px-4 py-2.5">{{ $e('Оплачен') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-50">
                            <tr v-for="e in paid.data" :key="e.id" class="group transition-colors duration-150 hover:bg-slate-50/60">
                                <td class="px-6 py-2.5 text-slate-500">{{ formatDate(e.date) }}</td>
                                <td class="px-4 py-2.5 text-right font-semibold tabular-nums text-slate-800">{{ money(e.amount) }}</td>
                                <td class="px-4 py-2.5 text-slate-500">
                                    {{ e.category || '—' }}
                                    <span v-if="e.payout" class="ml-1 rounded-full px-2 py-0.5 text-[11px] font-medium"
                                        :class="e.payout === 'debt' ? 'bg-rose-50 text-rose-600' : 'bg-indigo-50 text-indigo-700'">{{ payoutLabel(e.payout) }}</span>
                                </td>
                                <td class="px-4 py-2.5 text-slate-500">{{ e.description || '—' }}</td>
                                <td class="px-4 py-2.5 text-slate-500">{{ e.author?.name || '—' }}</td>
                                <td class="px-4 py-2.5">
                                    <span class="rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-700">{{ methodLabel(e.payment_method) }}</span>
                                    <a v-if="e.receipt" :href="receiptUrl(e.id)" target="_blank"
                                        class="ml-2 text-xs font-medium text-indigo-600 opacity-0 transition-opacity hover:underline group-hover:opacity-100">{{ $e('чек') }}</a>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-if="paid.data.length" class="border-t border-slate-100 p-4"><Pagination :links="paid.links" /></div>
            </div>
        </div>

        <!-- ================= Подтверждение оплаты ================= -->
        <Modal :show="!!confirming" @close="confirming = null" max-width="lg">
            <div v-if="confirming" class="p-6">
                <h2 class="mb-1 text-lg font-semibold text-slate-900">{{ $e('Проверил, оплатить') }}</h2>
                <p class="mb-4 text-xs text-slate-400">{{ $e('Откуда ушли деньги — из кассы или с банковского счёта. Остатки пересчитаются сразу.') }}</p>
                <div class="mb-4 rounded-lg bg-slate-50 px-4 py-3 text-sm">
                    <span class="font-semibold tabular-nums text-slate-800">{{ money(confirming.amount) }}</span>
                    <span class="text-slate-500"> · {{ confirming.category || $e('без категории') }} · {{ confirming.description || $e('без описания') }}</span>
                </div>
                <div class="flex gap-2">
                    <button type="button" @click="form.payment_method = 'cash'"
                        class="rounded-lg border px-3 py-1.5 text-xs font-semibold transition-colors duration-150"
                        :class="form.payment_method === 'cash' ? 'border-emerald-500 bg-emerald-100 text-emerald-700 ring-1 ring-emerald-500' : 'border-slate-200 bg-white text-slate-500'">{{ $e('Наличные') }}</button>
                    <button type="button" @click="form.payment_method = 'bank'"
                        class="rounded-lg border px-3 py-1.5 text-xs font-semibold transition-colors duration-150"
                        :class="form.payment_method === 'bank' ? 'border-sky-500 bg-sky-100 text-sky-700 ring-1 ring-sky-500' : 'border-slate-200 bg-white text-slate-500'">{{ $e('Банк') }}</button>
                </div>
                <div v-if="form.errors.payment_method" class="mt-1 text-xs text-red-600">{{ form.errors.payment_method }}</div>

                <div class="mt-4">
                    <label class="mb-1 block text-xs font-medium text-slate-500">{{ confirming.receipt ? $e('Заменить чек (необязательно)') : $e('Чек (фото или PDF) *') }}</label>
                    <input type="file" accept="image/*,application/pdf" @input="form.file = $event.target.files[0]"
                        class="w-full text-sm text-slate-500 file:mr-3 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-slate-600" />
                    <div v-if="form.errors.file" class="mt-1 text-xs text-red-600">{{ form.errors.file }}</div>
                </div>

                <div class="mt-6 flex justify-end gap-2">
                    <button type="button" @click="confirming = null"
                        class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-600 transition-colors duration-150 hover:bg-slate-50">{{ $e('Отмена') }}</button>
                    <button type="button" @click="submit" :disabled="form.processing"
                        class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition-colors duration-150 hover:bg-indigo-700 disabled:opacity-50">{{ $e('Оплачено') }}</button>
                </div>
            </div>
        </Modal>
    </AppLayout>
</template>

<style scoped>
/* Карточка уходит из очереди мягко — список не «прыгает» (только transform и opacity). */
.card-leave-active,
.card-move {
    transition: opacity 250ms ease, transform 250ms ease;
}

.card-leave-to {
    opacity: 0;
    transform: translateY(-6px);
}

@media (prefers-reduced-motion: reduce) {
    .card-leave-active,
    .card-move {
        transition: none;
    }
}
</style>
