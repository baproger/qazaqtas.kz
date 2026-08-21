<script setup>
/**
 * Производство: сменные наряды бригад.
 *
 * Бригадир вводит, кто сколько сделал за смену — в штуках и в м². Мастер
 * подтверждает, и только тогда выработка превращается в бонус: иначе объём
 * можно приписать себе самому.
 */
import { ref, computed } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import FinanceTile from '@/Components/FinanceTile.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { money, formatDate } from '@/utils/format';
import { confirmDialog } from '@/composables/useConfirm';
import { useE } from '@/composables/useTranslations';

const tr = useE();

const props = defineProps({
    month: { type: String, default: '' },
    orders: { type: Array, default: () => [] },
    byPerson: { type: Array, default: () => [] },
    totals: { type: Object, default: () => ({ pcs: 0, m2: 0, amount: 0, waiting: 0 }) },
    brigades: { type: Array, default: () => [] },
    rates: { type: Object, default: () => ({ foreman: { pcs: 0, m2: 0 }, worker: { pcs: 0, m2: 0 } }) },
    canConfirm: { type: Boolean, default: false },
    canManage: { type: Boolean, default: false },
    employees: { type: Array, default: () => [] },
});

const month = ref(props.month);
const applyMonth = () => router.get(route('production.index'), { month: month.value || undefined },
    { preserveState: true, preserveScroll: true, replace: true });

// Новый наряд: бригада подставляет своих людей строками — вводить остаётся
// только количество.
const showForm = ref(false);
const form = useForm({
    brigade_id: '', date: new Date().toISOString().slice(0, 10),
    product: '', note: '', lines: [],
});
const openForm = () => {
    form.reset();
    form.clearErrors();
    form.date = new Date().toISOString().slice(0, 10);
    form.brigade_id = props.brigades[0]?.id ?? '';
    fillMembers();
    showForm.value = true;
};
const fillMembers = () => {
    const brigade = props.brigades.find((b) => b.id === Number(form.brigade_id));
    form.lines = (brigade?.members ?? []).map((m) => ({ user_id: m.id, name: m.name, qty_pcs: '', qty_m2: '' }));
};
const lineAmount = (line) => Number(line.qty_pcs || 0) * props.rates.worker.pcs
    + Number(line.qty_m2 || 0) * props.rates.worker.m2;
const shiftTotals = computed(() => {
    const pcs = form.lines.reduce((s, l) => s + Number(l.qty_pcs || 0), 0);
    const m2 = form.lines.reduce((s, l) => s + Number(l.qty_m2 || 0), 0);

    return {
        pcs,
        m2,
        workers: form.lines.reduce((s, l) => s + lineAmount(l), 0),
        // Бригадир получает за весь объём смены — своей ставкой.
        foreman: pcs * props.rates.foreman.pcs + m2 * props.rates.foreman.m2,
    };
});
const submit = () => form.post(route('production.orders.store'), {
    preserveScroll: true,
    onSuccess: () => (showForm.value = false),
});

// Бригады: состав правит только руководство.
const showBrigade = ref(false);
const brigadeForm = useForm({ id: null, name: '', workshop: '', foreman_id: '', members: [], is_active: true });
const openBrigade = (brigade = null) => {
    brigadeForm.clearErrors();
    brigadeForm.id = brigade?.id ?? null;
    brigadeForm.name = brigade?.name ?? '';
    brigadeForm.workshop = brigade?.workshop ?? '';
    brigadeForm.foreman_id = brigade?.foreman_id ?? '';
    brigadeForm.members = (brigade?.members ?? []).map((m) => m.id);
    brigadeForm.is_active = brigade?.is_active ?? true;
    showBrigade.value = true;
};
const saveBrigade = () => {
    const done = { preserveScroll: true, onSuccess: () => (showBrigade.value = false) };
    brigadeForm.id
        ? brigadeForm.patch(route('production.brigades.update', brigadeForm.id), done)
        : brigadeForm.post(route('production.brigades.store'), done);
};
const removeBrigade = async (brigade) => {
    if (!(await confirmDialog({
        title: tr('Убрать бригаду'),
        message: brigade.name,
        confirmText: tr('Убрать'),
        danger: true,
    }))) return;
    router.delete(route('production.brigades.destroy', brigade.id), { preserveScroll: true });
};

const confirmOrder = (order) => router.patch(route('production.orders.confirm', order.id), {}, { preserveScroll: true });
const removeOrder = async (order) => {
    if (!(await confirmDialog({
        title: tr('Удалить наряд'),
        message: `${formatDate(order.date)} · ${order.brigade}`,
        confirmText: tr('Удалить'),
        danger: true,
    }))) return;
    router.delete(route('production.orders.destroy', order.id), { preserveScroll: true });
};
</script>

<template>
    <Head :title="$e('Производство')" />
    <AppLayout>
        <template #header>{{ $e('Производство') }}</template>

        <div class="mx-auto max-w-7xl">
            <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold text-slate-900">{{ $e('Выработка бригад') }}</h2>
                    <p class="mt-0.5 text-xs text-slate-400">{{ $e('смена → кто сколько сделал → подтверждение мастера → бонус') }}</p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-xs text-slate-400">{{ $e('Месяц:') }}</span>
                    <input v-model="month" @change="applyMonth" type="month"
                        class="rounded-lg border-slate-300 py-1.5 text-sm shadow-sm transition focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20" />
                    <button v-if="canManage" @click="openBrigade()"
                        class="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-600 transition-colors duration-150 hover:bg-slate-50">{{ $e('+ Бригада') }}</button>
                    <button v-if="brigades.length" @click="openForm"
                        class="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white transition-colors duration-150 hover:bg-indigo-700">{{ $e('+ Наряд') }}</button>
                </div>
            </div>

            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <FinanceTile :label="$e('Сделано, м²')" :value="String(totals.m2)" />
                <FinanceTile :label="$e('Сделано, штук')" :value="String(totals.pcs)" />
                <FinanceTile tone="good" :label="$e('Начислено за месяц')" :value="money(totals.amount)"
                    :hint="$e('идёт в бонусы сотрудников')" />
                <FinanceTile :tone="totals.waiting ? 'warn' : 'default'" :label="$e('Ждут подтверждения')"
                    :value="String(totals.waiting)" :hint="$e('без него бонус не начисляется')" />
            </div>

            <!-- Итог по людям: кто сколько сделал и заработал -->
            <div v-if="byPerson.length" class="mt-6 rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-100 px-6 py-4">
                    <h3 class="text-sm font-semibold text-slate-900">{{ $e('Кто сколько сделал за месяц') }}</h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-400">
                            <tr>
                                <th class="px-6 py-2.5">{{ $e('Сотрудник') }}</th>
                                <th class="px-4 py-2.5 text-right">{{ $e('м²') }}</th>
                                <th class="px-4 py-2.5 text-right">{{ $e('штук') }}</th>
                                <th class="px-4 py-2.5 text-right">{{ $e('Начислено') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-50">
                            <tr v-for="p in byPerson" :key="p.name" class="transition-colors duration-150 hover:bg-slate-50/60">
                                <td class="px-6 py-2.5 font-medium text-slate-800">{{ p.name }}</td>
                                <td class="px-4 py-2.5 text-right tabular-nums text-slate-600">{{ p.m2 || '—' }}</td>
                                <td class="px-4 py-2.5 text-right tabular-nums text-slate-600">{{ p.pcs || '—' }}</td>
                                <td class="px-4 py-2.5 text-right font-semibold tabular-nums text-emerald-600">{{ money(p.amount) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Бригады -->
            <div v-if="canManage && brigades.length" class="mt-6 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <h3 class="mb-3 text-sm font-semibold text-slate-900">{{ $e('Бригады') }}</h3>
                <div class="flex flex-wrap gap-2">
                    <div v-for="b in brigades" :key="b.id"
                        class="rounded-lg border px-3 py-2 text-xs"
                        :class="b.is_active ? 'border-slate-200' : 'border-dashed border-slate-200 opacity-60'">
                        <div class="font-semibold text-slate-800">
                            {{ b.name }}
                            <span v-if="!b.is_active" class="font-normal text-slate-400">· {{ $e('скрыта') }}</span>
                        </div>
                        <div class="mt-0.5 text-slate-400">
                            {{ b.foreman || $e('бригадир не назначен') }} · {{ b.members.length }} {{ $e('чел.') }}
                            <span v-if="b.workshop">· {{ b.workshop }}</span>
                        </div>
                        <div class="mt-1.5 flex gap-2">
                            <button @click="openBrigade(b)" class="font-semibold text-indigo-600 hover:underline">{{ $e('Изменить') }}</button>
                            <button @click="removeBrigade(b)" class="font-semibold text-slate-400 hover:underline">{{ $e('Убрать') }}</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Наряды по сменам -->
            <div class="mt-6 space-y-3">
                <div v-if="!orders.length" class="rounded-xl border border-slate-200 bg-white px-6 py-10 text-center text-sm text-slate-400 shadow-sm">
                    {{ $e('За этот месяц нарядов нет.') }}
                </div>

                <div v-for="o in orders" :key="o.id" class="rounded-xl border bg-white p-4 shadow-sm"
                    :class="o.status === 'confirmed' ? 'border-slate-200' : 'border-amber-200'">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-semibold text-slate-900">{{ formatDate(o.date) }}</span>
                                <span class="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-600">{{ o.brigade }}</span>
                                <span v-if="o.workshop" class="text-xs text-slate-400">{{ o.workshop }}</span>
                                <span class="rounded-full px-2.5 py-0.5 text-xs font-medium"
                                    :class="o.status === 'confirmed' ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'">
                                    {{ o.status === 'confirmed' ? $e('подтверждён') : $e('ждёт мастера') }}
                                </span>
                            </div>
                            <p class="mt-1 text-sm text-slate-500">
                                {{ o.product || $e('без изделия') }}
                                <span v-if="o.project" class="text-slate-400">· {{ o.project }}</span>
                            </p>
                        </div>
                        <div class="text-right text-xs text-slate-400">
                            <div class="tabular-nums">{{ o.totals.m2 }} {{ $e('м²') }} · {{ o.totals.pcs }} {{ $e('штук') }}</div>
                            <div class="font-semibold text-slate-700">{{ money(o.totals.workers + o.totals.foreman) }}</div>
                        </div>
                    </div>

                    <div class="mt-3 flex flex-wrap gap-1.5">
                        <span v-for="l in o.lines" :key="l.id"
                            class="rounded-lg border px-2.5 py-1 text-xs"
                            :class="l.role === 'foreman' ? 'border-indigo-200 bg-indigo-50 text-indigo-700' : 'border-slate-200 text-slate-600'">
                            {{ l.user || '—' }}<template v-if="l.role === 'foreman'"> · {{ $e('бригадир') }}</template>:
                            <template v-if="l.qty_m2">{{ l.qty_m2 }} {{ $e('м²') }} </template>
                            <template v-if="l.qty_pcs">{{ l.qty_pcs }} {{ $e('штук') }}</template>
                            <b class="ml-1 tabular-nums">{{ money(l.amount) }}</b>
                        </span>
                    </div>

                    <div class="mt-3 flex justify-end gap-2">
                        <button v-if="canConfirm && o.status !== 'confirmed'" @click="confirmOrder(o)"
                            class="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white transition-colors duration-150 hover:bg-indigo-700">{{ $e('Подтвердить') }}</button>
                        <button @click="removeOrder(o)"
                            class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-500 transition-colors duration-150 hover:bg-slate-50">{{ $e('Удалить') }}</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Новый наряд -->
        <Modal :show="showForm" @close="showForm = false" max-width="2xl">
            <div class="p-6">
                <h2 class="mb-1 text-lg font-semibold text-slate-900">{{ $e('Сменный наряд') }}</h2>
                <p class="mb-4 text-xs text-slate-400">{{ $e('Кто сколько сделал за смену. Бонус начислится после подтверждения мастера.') }}</p>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">{{ $e('Бригада *') }}</label>
                        <select v-model="form.brigade_id" @change="fillMembers" class="w-full rounded-md border-slate-300 text-sm shadow-sm">
                            <option v-for="b in brigades" :key="b.id" :value="b.id">{{ b.name }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">{{ $e('Дата *') }}</label>
                        <input v-model="form.date" type="date" class="w-full rounded-md border-slate-300 text-sm shadow-sm" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">{{ $e('Изделие') }}</label>
                        <input v-model="form.product" type="text" class="w-full rounded-md border-slate-300 text-sm shadow-sm" :placeholder="$e('Плитка 300×300…')" />
                    </div>
                </div>

                <div class="mt-4 space-y-2">
                    <div v-for="(line, i) in form.lines" :key="line.user_id ?? i"
                        class="flex flex-wrap items-center gap-2 rounded-lg border border-slate-200 px-3 py-2">
                        <span class="min-w-0 flex-1 truncate text-sm text-slate-700">{{ line.name }}</span>
                        <label class="flex items-center gap-1 text-xs text-slate-400">
                            <input v-model="line.qty_m2" type="number" min="0" step="any" class="w-24 rounded-md border-slate-300 py-1 text-sm shadow-sm" />
                            {{ $e('м²') }}
                        </label>
                        <label class="flex items-center gap-1 text-xs text-slate-400">
                            <input v-model="line.qty_pcs" type="number" min="0" step="any" class="w-24 rounded-md border-slate-300 py-1 text-sm shadow-sm" />
                            {{ $e('штук') }}
                        </label>
                        <span class="w-24 text-right text-sm tabular-nums text-slate-500">{{ money(lineAmount(line)) }}</span>
                    </div>
                    <p v-if="!form.lines.length" class="text-xs text-slate-400">{{ $e('В бригаде нет рабочих — добавьте их в состав бригады.') }}</p>
                    <div v-if="form.errors.lines" class="text-xs text-red-600">{{ form.errors.lines }}</div>
                </div>

                <div class="mt-3 rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-500">
                    {{ $e('Смена:') }} <b class="tabular-nums">{{ shiftTotals.m2 }}</b> {{ $e('м²') }} ·
                    <b class="tabular-nums">{{ shiftTotals.pcs }}</b> {{ $e('штук') }} ·
                    {{ $e('рабочим') }} <b class="tabular-nums">{{ money(shiftTotals.workers) }}</b> ·
                    {{ $e('бригадиру') }} <b class="tabular-nums">{{ money(shiftTotals.foreman) }}</b>
                </div>

                <div class="mt-5 flex justify-end gap-2">
                    <SecondaryButton @click="showForm = false">{{ $e('Отмена') }}</SecondaryButton>
                    <PrimaryButton :disabled="form.processing || !form.lines.length" @click="submit">{{ $e('Создать наряд') }}</PrimaryButton>
                </div>
            </div>
        </Modal>
        <!-- Бригада -->
        <Modal :show="showBrigade" @close="showBrigade = false" max-width="lg">
            <div class="p-6">
                <h2 class="mb-4 text-lg font-semibold text-slate-900">{{ brigadeForm.id ? $e('Бригада') : $e('Новая бригада') }}</h2>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">{{ $e('Название *') }}</label>
                        <input v-model="brigadeForm.name" type="text" class="w-full rounded-md border-slate-300 text-sm shadow-sm" />
                        <div v-if="brigadeForm.errors.name" class="mt-1 text-xs text-red-600">{{ brigadeForm.errors.name }}</div>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">{{ $e('Цех') }}</label>
                        <input v-model="brigadeForm.workshop" type="text" class="w-full rounded-md border-slate-300 text-sm shadow-sm" />
                    </div>
                </div>

                <div class="mt-4">
                    <label class="mb-1 block text-xs font-medium text-slate-500">{{ $e('Бригадир') }}</label>
                    <select v-model="brigadeForm.foreman_id" class="w-full rounded-md border-slate-300 text-sm shadow-sm">
                        <option value="">{{ $e('— не назначен —') }}</option>
                        <option v-for="u in employees" :key="u.id" :value="u.id">{{ u.name }}</option>
                    </select>
                    <p class="mt-1 text-xs text-slate-400">{{ $e('Бригадиру идёт бонус за весь объём смены.') }}</p>
                </div>

                <div class="mt-4">
                    <label class="mb-1 block text-xs font-medium text-slate-500">{{ $e('Состав бригады') }}</label>
                    <div class="max-h-52 space-y-1 overflow-y-auto rounded-lg border border-slate-200 p-2">
                        <label v-for="u in employees" :key="u.id" class="flex items-center gap-2 rounded px-1.5 py-1 text-sm text-slate-700 hover:bg-slate-50">
                            <input v-model="brigadeForm.members" :value="u.id" type="checkbox" class="rounded border-slate-300 text-indigo-600" />
                            {{ u.name }}
                        </label>
                    </div>
                </div>

                <label v-if="brigadeForm.id" class="mt-3 flex items-center gap-2 text-sm text-slate-600">
                    <input v-model="brigadeForm.is_active" type="checkbox" class="rounded border-slate-300 text-indigo-600" />
                    {{ $e('Бригада работает') }}
                </label>

                <div class="mt-5 flex justify-end gap-2">
                    <SecondaryButton @click="showBrigade = false">{{ $e('Отмена') }}</SecondaryButton>
                    <PrimaryButton :disabled="brigadeForm.processing" @click="saveBrigade">{{ $e('Сохранить') }}</PrimaryButton>
                </div>
            </div>
        </Modal>
    </AppLayout>
</template>
