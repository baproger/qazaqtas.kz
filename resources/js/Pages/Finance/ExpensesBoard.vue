<script setup>
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Modal from '@/Components/Modal.vue';
import Pagination from '@/Components/Pagination.vue';
import CompanyExpenseModal from '@/Components/CompanyExpenseModal.vue';
import ExpenseCategoriesModal from '@/Components/ExpenseCategoriesModal.vue';
import { formatDate, money } from '@/utils/format';
import { confirmDialog } from '@/composables/useConfirm';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
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
    categories: { type: Array, default: () => [] },
    balances: { type: Object, default: () => ({ cash: 0, bank: 0 }) },
    filters: { type: Object, default: () => ({}) },
});

// Фильтры оплаченных: вид (материалы/прочие) и способ (нал/банк) — переехали
// со страницы Финансов вместе с таблицей.
const kind = ref(props.filters?.kind ?? '');
const method = ref(props.filters?.method ?? '');
const applyFilters = () => router.get(route('expensesBoard.index'), {
    month: month.value || undefined,
    kind: kind.value || undefined,
    method: method.value || undefined,
}, { preserveState: true, preserveScroll: true, replace: true });
const setKind = (k) => { kind.value = kind.value === k ? '' : k; applyFilters(); };
const setMethod = (m) => { method.value = method.value === m ? '' : m; applyFilters(); };

// Правка и удаление расхода (бухгалтер/админ). Способ оплаты через update не
// меняется — это правило сервера; сумма материального списания производная.
const editing = ref(null);
const eForm = useForm({ amount: '', date: '', description: '', category_id: '' });
const openEdit = (e) => {
    editing.value = e;
    eForm.amount = Number(e.amount);
    eForm.date = (e.date ?? '').slice(0, 10);
    eForm.description = e.description ?? '';
    eForm.category_id = e.category_id ?? '';
    eForm.clearErrors();
};
const submitEdit = () => eForm.put(route('expenses.update', editing.value.id), {
    preserveScroll: true, onSuccess: () => (editing.value = null),
});
const removeExpense = async (e) => {
    if (!(await confirmDialog({
        title: tr('Удалить расход'),
        message: `${money(e.amount)}${e.material ? tr(' — остаток вернётся на склад') : ''}.`,
        confirmText: tr('Удалить'),
        danger: true,
    }))) return;
    router.delete(route('expenses.destroy', e.id), { preserveScroll: true });
};

// Расход компании и категории заводятся прямо здесь: бухгалтер работает с
// расходами на этой странице, и уходить за ними на Финансы незачем.
const showCompanyExpense = ref(false);
const showCats = ref(false);

const month = ref(props.month);
const applyMonth = () => applyFilters();

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
                <div v-if="canConfirm" class="flex items-center gap-2">
                    <button @click="showCats = true"
                        class="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-600 transition-colors duration-150 hover:bg-slate-50">{{ $e('⚙ Категории') }}</button>
                    <button @click="showCompanyExpense = true"
                        class="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white transition-colors duration-150 hover:bg-indigo-700">{{ $e('+ Расход компании') }}</button>
                </div>
            </div>

            <div v-if="!pending.length" class="rounded-xl border border-slate-200 bg-white px-6 py-8 text-center text-sm text-slate-400 shadow-sm">
                {{ $e('Очередь пуста ✓') }}
            </div>

            <TransitionGroup v-else name="card" tag="div" class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                <div v-for="e in pending" :key="e.id" class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div class="min-w-0">
                            <div class="text-xl font-bold tabular-nums text-slate-900">{{ money(e.amount) }}</div>
                            <div class="mt-0.5 flex flex-wrap items-center gap-1.5 text-[11px] text-slate-400">
                                <span>{{ formatDate(e.date) }}</span>
                                <span v-if="e.category" class="rounded-full bg-slate-100 px-2.5 py-0.5 font-medium text-slate-500">{{ e.category }}</span>
                                <Link v-if="e.source" :href="sourceUrl(e.source)" class="font-medium text-indigo-600 hover:underline">
                                    {{ e.source.number || $e('без номера') }}<template v-if="e.source.title"> · {{ e.source.title }}</template>
                                </Link>
                                <span v-else class="rounded-full bg-indigo-50 px-2.5 py-0.5 font-medium text-indigo-700">{{ $e('Расход компании') }}</span>
                            </div>
                        </div>
                        <div class="text-right text-[11px] text-slate-400">
                            {{ $e('подал') }}
                            <Link v-if="e.author" :href="route('users.show', e.author.id)" class="font-medium text-slate-600 hover:text-indigo-600">{{ e.author.name }}</Link>
                            <span v-else>—</span>
                        </div>
                    </div>

                    <p class="mt-2 text-sm text-slate-600">{{ e.description || $e('без описания') }}</p>

                    <!-- Чек открыт сразу: бухгалтеру не нужно кликать по каждой карточке. -->
                    <!-- Чек виден сразу, но карточку не растягивает: без чека
                         вместо пустой рамки на пол-экрана — короткая пометка. -->
                    <a v-if="e.receipt?.kind === 'image'" :href="receiptUrl(e.id)" target="_blank" class="mt-2 block">
                        <img :src="receiptUrl(e.id)" :alt="$e('Чек')" class="max-h-44 w-full rounded-lg border border-slate-100 object-contain" />
                    </a>

                    <div class="mt-3 flex items-center justify-between gap-2">
                        <a v-if="e.receipt?.kind === 'pdf'" :href="receiptUrl(e.id)" target="_blank"
                            class="text-xs font-medium text-indigo-600 hover:underline">📄 {{ $e('Чек PDF') }}</a>
                        <span v-else-if="!e.receipt" class="rounded-full bg-amber-50 px-2 py-0.5 text-[11px] font-medium text-amber-700">{{ $e('без чека') }}</span>
                        <span v-else></span>
                        <button v-if="canConfirm" @click="openConfirm(e)"
                            class="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white transition-colors duration-150 hover:bg-indigo-700">{{ $e('Проверил, оплатить') }}</button>
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
                    <div class="flex flex-wrap items-center gap-2">
                        <button v-for="f in [{ k: 'kind', v: 'material', l: $e('материалы') }, { k: 'kind', v: 'other', l: $e('прочие') }, { k: 'method', v: 'cash', l: $e('наличные') }, { k: 'method', v: 'bank', l: $e('банк') }]"
                            :key="f.k + f.v" type="button" @click="f.k === 'kind' ? setKind(f.v) : setMethod(f.v)"
                            class="rounded-full border px-3 py-1 text-xs font-medium transition-colors duration-150"
                            :class="(f.k === 'kind' ? kind : method) === f.v ? 'border-indigo-500 bg-indigo-50 text-indigo-700' : 'border-slate-200 bg-white text-slate-500 hover:bg-slate-50'">{{ f.l }}</button>
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
                                <th class="px-4 py-2.5">{{ $e('Сделка / заказ') }}</th>
                                <th class="px-4 py-2.5">{{ $e('Кто подал') }}</th>
                                <th class="px-4 py-2.5">{{ $e('Оплачен') }}</th>
                                <th v-if="canConfirm" class="px-4 py-2.5 text-right">{{ $e('Действия') }}</th>
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
                                <!-- Откуда расход: по номеру сделки его находят
                                     в карточке, где видны и остальные траты. -->
                                <td class="px-4 py-2.5">
                                    <Link v-if="e.source" :href="sourceUrl(e.source)" class="font-medium text-indigo-600 hover:underline">
                                        {{ e.source.number || $e('без номера') }}
                                        <span v-if="e.source.title" class="text-slate-400">· {{ e.source.title }}</span>
                                    </Link>
                                    <span v-else class="text-xs text-slate-400">{{ $e('Расход компании') }}</span>
                                </td>
                                <td class="px-4 py-2.5 text-slate-500">{{ e.author?.name || '—' }}</td>
                                <td class="px-4 py-2.5">
                                    <span class="rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-700">{{ methodLabel(e.payment_method) }}</span>
                                    <a v-if="e.receipt" :href="receiptUrl(e.id)" target="_blank"
                                        class="ml-2 text-xs font-medium text-indigo-600 opacity-0 transition-opacity hover:underline group-hover:opacity-100">{{ $e('чек') }}</a>
                                </td>
                                <td v-if="canConfirm" class="whitespace-nowrap px-4 py-2.5 text-right">
                                    <button class="rounded p-1 text-slate-300 transition-colors hover:text-indigo-600" :title="$e('Редактировать расход')" @click="openEdit(e)">
                                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>
                                    </button>
                                    <button class="rounded p-1 text-slate-300 transition-colors hover:text-rose-600" :title="$e('Удалить расход')" @click="removeExpense(e)">
                                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
                                    </button>
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
        <!-- Правка расхода: сумма, дата, категория, «за что». Способ оплаты и
             материал через update не меняются — правило сервера. -->
        <Modal :show="!!editing" @close="editing = null" max-width="lg">
            <div v-if="editing" class="p-6">
                <h2 class="mb-4 text-lg font-semibold text-slate-900">{{ $e('Редактировать расход') }}</h2>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">{{ $e('Сумма, ₸') }}</label>
                        <input v-model="eForm.amount" type="number" min="0" step="0.01" :disabled="!!editing.material"
                            class="w-full rounded-md border-slate-300 text-sm shadow-sm disabled:bg-slate-100" />
                        <p v-if="editing.material" class="mt-1 text-[11px] text-slate-400">{{ $e('Сумма списания считается автоматически (кол-во × цена).') }}</p>
                        <div v-if="eForm.errors.amount" class="mt-1 text-xs text-red-600">{{ eForm.errors.amount }}</div>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">{{ $e('Дата') }}</label>
                        <input v-model="eForm.date" type="date" class="w-full rounded-md border-slate-300 text-sm shadow-sm" />
                    </div>
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-xs font-medium text-slate-500">{{ $e('Категория') }}</label>
                        <select v-model="eForm.category_id" class="w-full rounded-md border-slate-300 text-sm shadow-sm">
                            <option value="">{{ $e('— без категории —') }}</option>
                            <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
                        </select>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-xs font-medium text-slate-500">{{ $e('За что') }}</label>
                        <input v-model="eForm.description" type="text" class="w-full rounded-md border-slate-300 text-sm shadow-sm" />
                    </div>
                </div>
                <div class="mt-6 flex justify-end gap-2">
                    <SecondaryButton @click="editing = null">{{ $e('Отмена') }}</SecondaryButton>
                    <PrimaryButton :disabled="eForm.processing" @click="submitEdit">{{ $e('Сохранить') }}</PrimaryButton>
                </div>
            </div>
        </Modal>

        <CompanyExpenseModal :show="showCompanyExpense" :categories="categories"
            :cash="Number(balances.cash)" :bank="Number(balances.bank)" @close="showCompanyExpense = false" />
        <ExpenseCategoriesModal :show="showCats" :categories="categories" @close="showCats = false" />
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
