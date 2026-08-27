<script setup>
/**
 * Производство: сменные наряды бригад.
 *
 * Бригадир вводит, кто сколько сделал за смену — в штуках и в м². Мастер
 * подтверждает, и только тогда выработка превращается в бонус: иначе объём
 * можно приписать себе самому.
 */
import { ref, computed } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
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
    plan: { type: Array, default: () => [] },
    planSummary: { type: Object, default: () => ({ m2: {}, pcs: {} }) },
    itemOptions: { type: Array, default: () => [] },
    byPerson: { type: Array, default: () => [] },
    totals: { type: Object, default: () => ({ pcs: 0, m2: 0, amount: 0, waiting: 0 }) },
    brigades: { type: Array, default: () => [] },
    rates: { type: Object, default: () => ({ foreman: { pcs: 0, m2: 0 }, worker: { pcs: 0, m2: 0 } }) },
    canConfirm: { type: Boolean, default: false },
    canManage: { type: Boolean, default: false },
    employees: { type: Array, default: () => [] },
});

// Позиция, по которой заводят наряд: из неё берётся план и остаток. Пока
// цифра остатка не на глазах, бригадир вводит объём «на память».
const pickedItem = computed(() => props.itemOptions.find((i) => i.id === Number(form.deal_item_id)) ?? null);
const measureLabel = (m) => (m === 'm2' ? tr('м²') : tr('штук'));
const num = (v) => Number(v ?? 0).toLocaleString('ru-RU');

// Какая смена раскрыта: состав нужен, когда сверяют начисление.
const open = ref(null);

const month = ref(props.month);
const applyMonth = () => router.get(route('production.index'), { month: month.value || undefined },
    { preserveState: true, preserveScroll: true, replace: true });

// Новый наряд: бригада подставляет своих людей строками — вводить остаётся
// только количество.
const showForm = ref(false);
const form = useForm({
    brigade_id: '', date: new Date().toISOString().slice(0, 10),
    deal_item_id: '', product: '', note: '', lines: [],
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
            <!-- Два вида одной страницы: план месяца и журнал смен. -->
            <div class="tab-rail mb-5">
                <Link :href="route('production.plans.index', { month })"
                    class="tab-soft">{{ $e('План — факт') }}</Link>
                <span class="tab-soft tab-soft-active">{{ $e('Все наряды') }}</span>
            </div>

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

            <!-- План и факт по сделкам: сколько заказано и сколько закрыто.
                 План берётся из позиций сделки, факт — из подтверждённых
                 нарядов по ним. Одно и то же число видят и цех, и продажи. -->
            <div v-if="plan.length" class="mt-6 rounded-2xl border border-slate-100 bg-white shadow-sm">
                <div class="flex flex-wrap items-baseline justify-between gap-3 border-b border-slate-100 px-6 py-4">
                    <div>
                        <h3 class="flex items-center gap-2 text-sm font-semibold text-slate-900">
                            <svg class="h-4 w-4 text-indigo-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="m7 14 4-4 3 3 5-6"/></svg>
                            {{ $e('Сделки в работе: план и факт') }}
                        </h3>
                        <p class="mt-0.5 text-xs text-slate-400">{{ $e('план — из позиций сделки, факт — из подтверждённых нарядов') }}</p>
                    </div>
                    <div class="flex flex-wrap gap-4 text-sm tabular-nums">
                        <template v-for="m in ['m2', 'pcs']" :key="m">
                            <div v-if="planSummary[m]?.items" class="flex items-baseline gap-1.5">
                                <span class="text-xs text-slate-400">{{ measureLabel(m) }}:</span>
                                <b class="text-slate-900">{{ num(planSummary[m].done) }}</b>
                                <span class="text-slate-400">/ {{ num(planSummary[m].plan) }}</span>
                                <span v-if="planSummary[m].left > 0" class="text-xs font-semibold text-amber-600">{{ $e('осталось') }} {{ num(planSummary[m].left) }}</span>
                                <span v-else class="text-xs font-semibold text-emerald-600">{{ $e('закрыто ✓') }}</span>
                            </div>
                        </template>
                    </div>
                </div>
                <div class="divide-y divide-slate-50">
                    <div v-for="row in plan" :key="row.id" class="px-6 py-3">
                        <div class="flex flex-wrap items-baseline justify-between gap-3">
                            <div class="min-w-0">
                                <span class="text-sm font-medium text-slate-800">🧱 {{ row.name }}</span>
                                <span class="ml-2 text-xs text-slate-400">{{ row.deal }} · {{ row.client }}</span>
                            </div>
                            <div class="flex items-baseline gap-2 text-sm tabular-nums">
                                <b :class="row.over ? 'text-amber-600' : 'text-slate-900'">{{ num(row.done) }}</b>
                                <span class="text-slate-400">/ {{ num(row.plan) }} {{ row.unit }}</span>
                                <span v-if="row.pending" class="rounded bg-amber-50 px-1.5 py-0.5 text-[11px] font-semibold text-amber-700"
                                    :title="$e('внесено, но мастер ещё не подтвердил')">+{{ num(row.pending) }} {{ $e('ждёт') }}</span>
                                <span v-if="row.over" class="rounded bg-amber-100 px-1.5 py-0.5 text-[11px] font-semibold text-amber-800">{{ $e('перевыполнение') }}</span>
                            </div>
                        </div>
                        <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-slate-100">
                            <div class="h-full rounded-full transition-all duration-500"
                                :class="row.over ? 'bg-amber-400' : row.percent >= 100 ? 'bg-emerald-500' : 'bg-indigo-500'"
                                :style="{ width: Math.min(row.percent, 100) + '%' }"></div>
                        </div>
                        <!-- Чья это работа: на одном объекте смены ведут разные бригады. -->
                        <div v-if="row.brigades.length" class="mt-1.5 flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-500">
                            <span v-for="(b, i) in row.brigades" :key="i" class="tabular-nums">
                                👷 {{ b.brigade || '—' }}: <b class="text-slate-700">{{ num(row.measure === 'm2' ? b.m2 : b.pcs) }} {{ row.unit }}</b>
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Итог по людям: кто сколько сделал и заработал -->
            <div v-if="byPerson.length" class="mt-6 rounded-2xl border border-slate-100 bg-white shadow-sm">
                <div class="flex flex-wrap items-baseline justify-between gap-3 border-b border-slate-100 px-6 py-4">
                    <h3 class="flex items-center gap-2 text-sm font-semibold text-slate-900">
                        <svg class="h-4 w-4 text-indigo-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/></svg>
                        {{ $e('Кто сколько сделал за месяц') }}
                    </h3>
                    <!-- Рядом с людьми — откуда взялся объём: столько заказано
                         в сделках, столько закрыто нарядами. -->
                    <div class="flex flex-wrap gap-4 text-xs tabular-nums text-slate-500">
                        <template v-for="m in ['m2', 'pcs']" :key="m">
                            <span v-if="planSummary[m]?.items">
                                {{ $e('из сделок') }} <b class="text-slate-700">{{ num(planSummary[m].plan) }}</b> {{ measureLabel(m) }} ·
                                {{ $e('сделано') }} <b class="text-slate-700">{{ num(planSummary[m].done) }}</b>
                            </span>
                        </template>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-400">
                            <tr>
                                <th class="px-6 py-2.5 font-medium">{{ $e('Сотрудник') }}</th>
                                <th class="px-4 py-2.5 text-right font-medium">{{ $e('м²') }}</th>
                                <th class="px-4 py-2.5 text-right font-medium">{{ $e('штук') }}</th>
                                <th class="px-4 py-2.5 text-right font-medium">{{ $e('Начислено') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-50">
                            <tr v-for="p in byPerson" :key="p.name" class="transition-colors duration-150 hover:bg-slate-50/60">
                                <td class="px-6 py-2.5 font-medium text-slate-800">{{ p.name }}</td>
                                <td class="px-4 py-2.5 text-right tabular-nums text-slate-600">{{ p.m2 ? num(p.m2) : '—' }}</td>
                                <td class="px-4 py-2.5 text-right tabular-nums text-slate-600">{{ p.pcs ? num(p.pcs) : '—' }}</td>
                                <td class="px-4 py-2.5 text-right font-semibold tabular-nums text-emerald-600">{{ money(p.amount) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Бригады -->
            <div v-if="canManage && brigades.length" class="mt-6 rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
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
                            <Link :href="route('production.brigade', { brigade: b.id, month })" class="font-semibold text-indigo-600 hover:underline">{{ $e('Подробнее') }}</Link>
                            <button v-if="canManage" @click="openBrigade(b)" class="font-semibold text-slate-500 hover:underline">{{ $e('Изменить') }}</button>
                            <button v-if="canManage" @click="removeBrigade(b)" class="font-semibold text-slate-400 hover:underline">{{ $e('Убрать') }}</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Наряды по сменам — таблицей: одна смена = одна строка.
                 Карточками список уезжал на несколько экранов, а глазами
                 сравнить две смены было нельзя. Состав смены раскрывается по
                 клику: он нужен, когда сверяют начисление, а не всегда. -->
            <div class="mt-6 rounded-2xl border border-slate-100 bg-white shadow-sm">
                <div class="flex flex-wrap items-baseline justify-between gap-3 border-b border-slate-100 px-6 py-4">
                    <h3 class="flex items-center gap-2 text-sm font-semibold text-slate-900">
                        <svg class="h-4 w-4 text-indigo-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M8 2v4M16 2v4M3 10h18"/></svg>
                        {{ $e('Наряды по сменам') }}
                        <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-500">{{ orders.length }}</span>
                    </h3>
                    <span v-if="totals.waiting" class="text-xs font-semibold text-amber-600">⏳ {{ totals.waiting }} {{ $e('ждут подтверждения') }}</span>
                </div>

                <div v-if="!orders.length" class="px-6 py-10 text-center text-sm text-slate-400">
                    {{ $e('За этот месяц нарядов нет.') }}
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-400">
                            <tr>
                                <th class="px-6 py-2.5 font-medium">{{ $e('Дата') }}</th>
                                <th class="px-3 py-2.5 font-medium">{{ $e('Изделие') }}</th>
                                <th class="px-3 py-2.5 font-medium">{{ $e('Бригада') }}</th>
                                <th class="px-3 py-2.5 text-right font-medium">{{ $e('Объём') }}</th>
                                <th class="px-3 py-2.5 text-right font-medium">{{ $e('Начислено') }}</th>
                                <th class="px-3 py-2.5 font-medium">{{ $e('Статус') }}</th>
                                <th class="px-6 py-2.5"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-50">
                            <template v-for="o in orders" :key="o.id">
                                <tr class="cursor-pointer align-top transition-colors duration-150 hover:bg-slate-50/60"
                                    :class="open === o.id ? 'bg-slate-50/60' : ''" @click="open = open === o.id ? null : o.id">
                                    <td class="whitespace-nowrap px-6 py-3 font-medium tabular-nums text-slate-800">
                                        <svg class="mr-1 inline h-3 w-3 text-slate-300 transition-transform duration-200" :class="open === o.id ? 'rotate-90' : ''"
                                            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
                                        {{ formatDate(o.date) }}
                                    </td>
                                    <td class="px-3 py-3">
                                        <div class="text-slate-800">{{ o.item?.name || o.product || $e('без изделия') }}</div>
                                        <div v-if="o.item || o.project" class="text-xs text-slate-400">
                                            <span v-if="o.item">🧾 {{ o.item.deal }}</span>
                                            <span v-if="o.project" class="ml-1.5">🏭 {{ o.project }}</span>
                                        </div>
                                    </td>
                                    <td class="px-3 py-3 text-slate-600">
                                        <div>👷 {{ o.brigade }}</div>
                                        <div v-if="o.workshop" class="text-xs text-slate-400">{{ o.workshop }}</div>
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-3 text-right tabular-nums text-slate-700">
                                        <span v-if="o.totals.m2">{{ num(o.totals.m2) }} {{ $e('м²') }}</span>
                                        <span v-if="o.totals.m2 && o.totals.pcs" class="text-slate-300"> · </span>
                                        <span v-if="o.totals.pcs">{{ num(o.totals.pcs) }} {{ $e('штук') }}</span>
                                        <span v-if="!o.totals.m2 && !o.totals.pcs" class="text-slate-300">—</span>
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-3 text-right font-semibold tabular-nums text-emerald-600">{{ money(o.totals.workers + o.totals.foreman) }}</td>
                                    <td class="whitespace-nowrap px-3 py-3">
                                        <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium"
                                            :class="o.status === 'confirmed' ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'">
                                            {{ o.status === 'confirmed' ? '✓' : '⏳' }}
                                            {{ o.status === 'confirmed' ? $e('подтверждён') : $e('ждёт мастера') }}
                                        </span>
                                    </td>
                                    <td class="whitespace-nowrap px-6 py-3 text-right" @click.stop>
                                        <button v-if="canConfirm && o.status !== 'confirmed'" @click="confirmOrder(o)"
                                            class="rounded-lg bg-indigo-600 px-2.5 py-1 text-xs font-semibold text-white transition-colors duration-150 hover:bg-indigo-700">{{ $e('Подтвердить') }}</button>
                                        <button @click="removeOrder(o)" :title="$e('Удалить')"
                                            class="ml-1 rounded-lg p-1.5 text-slate-300 transition-colors duration-150 hover:bg-rose-50 hover:text-rose-600">
                                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
                                        </button>
                                    </td>
                                </tr>

                                <!-- Состав смены: кто сколько сделал и сколько ему начислено.
                                     Строка бригадира — весь объём смены, это его бонус, а не
                                     второй раз посчитанная выработка. -->
                                <tr v-if="open === o.id" class="bg-slate-50/60">
                                    <td colspan="7" class="px-6 pb-4 pt-0">
                                        <table class="min-w-full text-xs">
                                            <tbody class="divide-y divide-slate-100">
                                                <tr v-for="l in o.lines" :key="l.id">
                                                    <td class="py-1.5 pr-4 text-slate-700">
                                                        {{ l.user || '—' }}
                                                        <span v-if="l.role === 'foreman'" class="ml-1 rounded bg-indigo-50 px-1.5 py-px text-[10px] font-semibold text-indigo-700">{{ $e('бригадир · вся смена') }}</span>
                                                    </td>
                                                    <td class="py-1.5 pr-4 text-right tabular-nums text-slate-500">
                                                        <span v-if="l.qty_m2">{{ num(l.qty_m2) }} {{ $e('м²') }}</span>
                                                        <span v-if="l.qty_m2 && l.qty_pcs" class="text-slate-300"> · </span>
                                                        <span v-if="l.qty_pcs">{{ num(l.qty_pcs) }} {{ $e('штук') }}</span>
                                                    </td>
                                                    <td class="py-1.5 text-right font-semibold tabular-nums text-slate-700">{{ money(l.amount) }}</td>
                                                </tr>
                                            </tbody>
                                        </table>
                                        <div class="mt-2 text-[11px] text-slate-400">
                                            <span v-if="o.created_by">{{ $e('внёс') }}: {{ o.created_by }}</span>
                                            <span v-if="o.confirmed_by" class="ml-3">{{ $e('подтвердил') }}: {{ o.confirmed_by }} · {{ o.confirmed_at }}</span>
                                            <span v-if="o.note" class="ml-3">📝 {{ o.note }}</span>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
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
                        <input v-model="form.product" type="text" class="w-full rounded-md border-slate-300 text-sm shadow-sm"
                            :placeholder="pickedItem ? pickedItem.name : $e('Плитка 300×300…')" />
                    </div>
                </div>

                <!-- По какой позиции сделки работает смена. Отсюда берётся
                     план и остаток — без него объём вводили «на память», и
                     сложить его с заказом было нельзя. -->
                <div class="mt-4">
                    <label class="mb-1 block text-xs font-medium text-slate-500">{{ $e('Позиция сделки') }}</label>
                    <select v-model="form.deal_item_id" class="w-full rounded-md border-slate-300 text-sm shadow-sm">
                        <option value="">{{ $e('— без привязки к сделке —') }}</option>
                        <option v-for="i in itemOptions" :key="i.id" :value="i.id">
                            {{ i.deal }} · {{ i.name }} — {{ $e('осталось') }} {{ num(i.left) }} {{ i.unit }}
                        </option>
                    </select>
                    <div v-if="pickedItem" class="mt-1.5 flex flex-wrap items-baseline gap-x-3 gap-y-1 rounded-lg bg-indigo-50 px-3 py-2 text-xs tabular-nums text-indigo-800">
                        <span>{{ $e('План:') }} <b>{{ num(pickedItem.plan) }} {{ pickedItem.unit }}</b></span>
                        <span>{{ $e('Сделано:') }} <b>{{ num(pickedItem.done) }}</b></span>
                        <span v-if="pickedItem.pending">{{ $e('ждёт подтверждения:') }} <b>{{ num(pickedItem.pending) }}</b></span>
                        <span class="font-semibold">{{ $e('Осталось:') }} {{ num(pickedItem.left) }} {{ pickedItem.unit }}</span>
                    </div>
                    <p v-else class="mt-1 text-[11px] text-slate-400">{{ $e('Без позиции наряд в план по сделке не попадёт') }}</p>
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
