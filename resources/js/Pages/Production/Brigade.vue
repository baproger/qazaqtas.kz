<script setup>
/**
 * Карточка бригады: всё о ней за месяц в одном месте.
 *
 * На общей странице бригада — одна строка. Здесь: состав, планы с
 * выполнением, все смены (и по плану, и под заказ клиента) и кто сколько
 * заработал. Спрашивают именно так — «что у Бригады №1», — а раньше ответ
 * приходилось собирать по трём страницам.
 */
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { formatDate, money } from '@/utils/format';

const props = defineProps({
    month: { type: String, default: '' },
    brigade: { type: Object, default: () => ({}) },
    plans: { type: Array, default: () => [] },
    orders: { type: Array, default: () => [] },
    byPerson: { type: Array, default: () => [] },
    canConfirm: { type: Boolean, default: false },
});

const num = (v) => Number(v ?? 0).toLocaleString('ru-RU');
const month = ref(props.month);
const applyMonth = () => router.get(route('production.brigade', props.brigade.id), { month: month.value || undefined },
    { preserveState: true, preserveScroll: true, replace: true });

const open = ref(null);
const confirmOrder = (o) => router.patch(route('production.orders.confirm', o.id), {}, { preserveScroll: true });

const statusText = (s) => (s === 'confirmed' ? '✓' : s === 'rejected' ? '✕' : '⏳');
const statusClass = (s) => (s === 'confirmed' ? 'text-emerald-600' : s === 'rejected' ? 'text-rose-600' : 'text-amber-600');
</script>

<template>
    <Head :title="brigade.name" />
    <AppLayout>
        <template #header>
            <div class="flex min-w-0 items-center gap-3">
                <Link :href="route('production.plans.index', { month })" class="flex-shrink-0 text-sm font-medium text-slate-400 hover:text-slate-600">← {{ $e('План — факт') }}</Link>
                <span class="min-w-0 truncate">{{ brigade.name }}</span>
                <span v-if="!brigade.is_active" class="flex-shrink-0 rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-500">{{ $e('скрыта') }}</span>
            </div>
        </template>

        <div class="mx-auto max-w-5xl">
            <!-- Кто в бригаде: без этого «Ержан 55 м²» ниже ни о чём не говорит. -->
            <div class="mb-5 flex flex-wrap items-start justify-between gap-4 border-b border-slate-100 pb-5">
                <div class="min-w-0">
                    <div class="text-[11px] uppercase tracking-wide text-slate-400">{{ $e('Бригадир') }}</div>
                    <div class="mt-0.5 font-medium text-slate-900">{{ brigade.foreman || '—' }}</div>
                    <div class="mt-3 text-[11px] uppercase tracking-wide text-slate-400">{{ $e('Состав') }}</div>
                    <div class="mt-1 flex flex-wrap gap-1.5">
                        <span v-for="m in brigade.members" :key="m.id" class="rounded-lg bg-slate-100 px-2.5 py-1 text-xs text-slate-700">{{ m.name }}</span>
                        <span v-if="!brigade.members?.length" class="text-xs text-slate-400">{{ $e('рабочие не назначены') }}</span>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <span v-if="brigade.workshop" class="rounded-lg bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-600">🏭 {{ brigade.workshop }}</span>
                    <input v-model="month" @change="applyMonth" type="month"
                        class="rounded-lg border-slate-200 py-1.5 text-sm shadow-sm transition focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20" />
                </div>
            </div>

            <!-- Планы месяца -->
            <h3 class="mb-2 text-sm font-semibold text-slate-900">{{ $e('Планы месяца') }}</h3>
            <div v-if="!plans.length" class="mb-6 rounded-xl border border-dashed border-slate-200 px-5 py-8 text-center text-sm text-slate-500">
                {{ $e('На этот месяц плана нет.') }}
            </div>
            <div v-else class="mb-6 divide-y divide-slate-50 rounded-xl border border-slate-200 bg-white">
                <div v-for="p in plans" :key="p.id" class="px-5 py-3">
                    <div class="flex flex-wrap items-baseline justify-between gap-3">
                        <span class="text-sm font-medium text-slate-800">{{ p.product }}</span>
                        <div class="flex items-baseline gap-3 text-sm tabular-nums">
                            <span><b :class="p.over ? 'text-amber-600' : 'text-slate-900'">{{ num(p.done) }}</b><span class="text-slate-400"> / {{ num(p.plan) }} {{ p.unit }}</span></span>
                            <span v-if="p.pending" class="text-xs text-amber-600">+{{ num(p.pending) }} {{ $e('ждёт') }}</span>
                            <span class="w-12 text-right text-xs text-slate-400">{{ p.percent }}%</span>
                            <span class="w-24 text-right text-emerald-600">{{ money(p.bonus) }}</span>
                        </div>
                    </div>
                    <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-slate-100">
                        <div class="h-full rounded-full transition-all duration-500"
                            :class="p.over ? 'bg-amber-400' : p.percent >= 100 ? 'bg-emerald-500' : 'bg-indigo-500'"
                            :style="{ width: Math.min(p.percent, 100) + '%' }"></div>
                    </div>
                </div>
            </div>

            <!-- Кто сколько заработал: только по подтверждённым сменам. -->
            <template v-if="byPerson.length">
                <h3 class="mb-2 text-sm font-semibold text-slate-900">{{ $e('Заработали за месяц') }}</h3>
                <div class="mb-6 overflow-x-auto rounded-xl border border-slate-200 bg-white">
                    <table class="min-w-full text-sm">
                        <thead class="border-b border-slate-100 text-left text-[11px] uppercase tracking-wide text-slate-400">
                            <tr>
                                <th class="px-5 py-2.5 font-medium">{{ $e('Сотрудник') }}</th>
                                <th class="px-3 py-2.5 text-right font-medium">{{ $e('м²') }}</th>
                                <th class="px-3 py-2.5 text-right font-medium">{{ $e('штук') }}</th>
                                <th class="px-5 py-2.5 text-right font-medium">{{ $e('Начислено') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-50">
                            <tr v-for="p in byPerson" :key="p.name">
                                <td class="px-5 py-2.5 text-slate-800">
                                    {{ p.name }}
                                    <span v-if="p.role === 'foreman'" class="ml-1.5 rounded bg-indigo-50 px-1.5 py-px text-[10px] font-semibold text-indigo-700">{{ $e('бригадир') }}</span>
                                </td>
                                <td class="px-3 py-2.5 text-right tabular-nums text-slate-600">{{ p.m2 ? num(p.m2) : '—' }}</td>
                                <td class="px-3 py-2.5 text-right tabular-nums text-slate-600">{{ p.pcs ? num(p.pcs) : '—' }}</td>
                                <td class="px-5 py-2.5 text-right font-semibold tabular-nums text-emerald-600">{{ money(p.amount) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </template>

            <!-- Смены месяца: и по плану, и под заказ клиента. -->
            <h3 class="mb-2 text-sm font-semibold text-slate-900">
                {{ $e('Смены') }}
                <span class="ml-1 rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-500">{{ orders.length }}</span>
            </h3>
            <div v-if="!orders.length" class="rounded-xl border border-dashed border-slate-200 px-5 py-8 text-center text-sm text-slate-500">
                {{ $e('Смен в этом месяце не было.') }}
            </div>
            <div v-else class="overflow-hidden rounded-xl border border-slate-200 bg-white">
                <div v-for="o in orders" :key="o.id" class="border-b border-slate-50 last:border-0">
                    <div class="flex cursor-pointer flex-wrap items-center gap-x-4 gap-y-1 px-5 py-3 text-sm transition-colors duration-150 hover:bg-slate-50/60"
                        @click="open = open === o.id ? null : o.id">
                        <span class="tabular-nums text-slate-400">{{ formatDate(o.date) }}</span>
                        <span class="text-slate-800">{{ o.source.name || $e('без изделия') }}</span>
                        <span v-if="o.source.kind === 'deal'" class="text-xs text-slate-400">🧾 {{ o.source.deal }}</span>
                        <span v-else-if="o.source.kind === 'plan'" class="rounded bg-indigo-50 px-1.5 py-0.5 text-[10px] font-semibold text-indigo-700">{{ $e('по плану') }}</span>
                        <span class="tabular-nums text-slate-700">
                            <template v-if="o.totals.m2">{{ num(o.totals.m2) }} {{ $e('м²') }}</template>
                            <template v-if="o.totals.m2 && o.totals.pcs"> · </template>
                            <template v-if="o.totals.pcs">{{ num(o.totals.pcs) }} {{ $e('штук') }}</template>
                        </span>
                        <span class="ml-auto tabular-nums text-emerald-600">{{ money(o.totals.workers + o.totals.foreman) }}</span>
                        <span :class="statusClass(o.status)">{{ statusText(o.status) }}</span>
                        <button v-if="canConfirm && o.status === 'draft'" @click.stop="confirmOrder(o)"
                            class="rounded-lg bg-emerald-600 px-2.5 py-1 text-xs font-semibold text-white transition-colors duration-150 hover:bg-emerald-700">{{ $e('Подтвердить') }}</button>
                    </div>
                    <div v-if="open === o.id" class="bg-slate-50/60 px-5 pb-3 text-xs">
                        <div v-if="o.reject_reason" class="mb-1.5 text-rose-600">✕ {{ o.reject_reason }}</div>
                        <div v-for="l in o.lines" :key="l.id" class="flex items-baseline gap-3 py-0.5">
                            <span class="text-slate-700">{{ l.user || '—' }}</span>
                            <span v-if="l.role === 'foreman'" class="rounded bg-indigo-50 px-1.5 py-px text-[10px] font-semibold text-indigo-700">{{ $e('вся смена') }}</span>
                            <span class="tabular-nums text-slate-500">
                                <template v-if="l.qty_m2">{{ num(l.qty_m2) }} {{ $e('м²') }}</template>
                                <template v-if="l.qty_pcs">{{ num(l.qty_pcs) }} {{ $e('штук') }}</template>
                            </span>
                            <span class="ml-auto tabular-nums font-semibold text-slate-700">{{ money(l.amount) }}</span>
                        </div>
                        <div class="mt-1.5 text-[11px] text-slate-400">
                            <span v-if="o.created_by">{{ $e('внёс') }}: {{ o.created_by }}</span>
                            <span v-if="o.confirmed_by" class="ml-3">{{ $e('подтвердил') }}: {{ o.confirmed_by }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
