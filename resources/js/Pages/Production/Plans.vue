<script setup>
/**
 * «План — факт»: задание цеху на месяц и его выполнение.
 *
 * Две роли смотрят на одну страницу по-разному, поэтому и вид разный:
 * бригадир видит СВОИ планы карточками с одной кнопкой «Сделал» — он стоит у
 * формы с телефоном; руководство видит таблицу всех бригад, где строки можно
 * сравнить глазами.
 *
 * Выполнение считает сервер (ProductionProgressService) — тот же, что в цехе
 * и в сделке. Второго счётчика здесь нет.
 */
import { ref, computed } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Modal from '@/Components/Modal.vue';
import { money } from '@/utils/format';
import { confirmDialog } from '@/composables/useConfirm';
import { useE } from '@/composables/useTranslations';

const tr = useE();

const props = defineProps({
    month: { type: String, default: '' },
    plans: { type: Array, default: () => [] },
    orders: { type: Array, default: () => [] },
    summary: { type: Object, default: () => ({}) },
    canPlan: { type: Boolean, default: false },
    canConfirm: { type: Boolean, default: false },
    isForeman: { type: Boolean, default: false },
    brigades: { type: Array, default: () => [] },
    products: { type: Array, default: () => [] },
    productCategories: { type: Array, default: () => [] },
});

const num = (v) => Number(v ?? 0).toLocaleString('ru-RU');
// Подпись метрики: метры и штуки на этой странице никогда не складываются,
// поэтому у каждого числа должна стоять своя единица.
const measureLabel = (m) => (m === 'm2' ? tr('м²') : tr('штук'));

const month = ref(props.month);
const applyMonth = () => router.get(route('production.plans.index'), { month: month.value || undefined },
    { preserveState: true, preserveScroll: true, replace: true });

// ---- Новый план (директор) ----
const showForm = ref(false);
const form = useForm({ period_month: props.month, brigade_id: '', product_id: '', plan_qty: '', bonus_rate: '', note: '' });
const pickedProduct = computed(() => props.products.find((p) => p.id === Number(form.product_id)) ?? null);

const openForm = () => {
    form.reset();
    form.clearErrors();
    form.period_month = props.month;
    form.brigade_id = props.brigades[0]?.id ?? '';
    showForm.value = true;
};
const submit = () => form.post(route('production.plans.store'), {
    preserveScroll: true, onSuccess: () => (showForm.value = false),
});

const removePlan = async (plan) => {
    if (await confirmDialog({ title: tr('Убрать план'), message: plan.product, confirmText: tr('Убрать'), danger: true })) {
        router.delete(route('production.plans.destroy', plan.id), { preserveScroll: true });
    }
};

// ---- Выработка по плану (бригадир) ----
const reporting = ref(null);
const qty = ref('');
const busy = ref(false);

const openReport = (plan) => {
    reporting.value = plan.id;
    qty.value = plan.left || '';
};
const saveReport = (plan) => {
    if (!(Number(qty.value) > 0) || busy.value) return;
    busy.value = true;
    router.post(route('production.plans.output', plan.id), { qty: qty.value }, {
        preserveScroll: true,
        onFinish: () => { busy.value = false; reporting.value = null; qty.value = ''; },
    });
};

// ---- Подтверждение и отказ (директор, финансист) ----
const confirmOrder = (o) => router.patch(route('production.orders.confirm', o.id), {}, { preserveScroll: true });
const rejectOrder = async (o) => {
    const reason = window.prompt(tr('Что исправить?'));
    if (reason) router.patch(route('production.orders.reject', o.id), { reason }, { preserveScroll: true });
};

const ordersOf = (planId) => props.orders.filter((o) => o.plan_id === planId);

// Блок на бригаду: сплошная таблица не давала увидеть, как идёт КАЖДАЯ, —
// а спрашивают именно так: «что у Бригады №1».
const byBrigade = computed(() => {
    const map = new Map();
    for (const p of props.plans) {
        if (!map.has(p.brigade_id)) {
            map.set(p.brigade_id, { id: p.brigade_id, name: p.brigade, foreman: p.foreman, workshop: p.workshop, rows: [] });
        }
        map.get(p.brigade_id).rows.push(p);
    }
    return [...map.values()].map((b) => ({
        ...b,
        bonus: b.rows.reduce((s, r) => s + Number(r.bonus || 0), 0),
        // Раздельно по метрике: у бригады бывают планы и в м², и в штуках.
        measures: ['m2', 'pcs']
            .map((measure) => {
                const rows = b.rows.filter((r) => r.measure === measure);
                return {
                    measure,
                    rows: rows.length,
                    done: rows.reduce((s, r) => s + Number(r.done || 0), 0),
                    plan: rows.reduce((s, r) => s + Number(r.plan || 0), 0),
                };
            })
            .filter((m) => m.rows > 0),
    }));
});

// Свои бригады: из планов месяца — других у бригадира на этой странице нет.
const myBrigades = computed(() => {
    const seen = new Map();
    for (const p of props.plans) {
        if (!seen.has(p.brigade_id)) seen.set(p.brigade_id, { id: p.brigade_id, name: p.brigade });
    }
    return [...seen.values()];
});

// Выбор товара по категориям: плоский список из двадцати позиций читать
// нечем. Нажал «Плитка» — раскрылись плитки.
const openCategory = ref(null);
const productsOf = (categoryId) => props.products.filter((p) => p.category_id === categoryId);
const pickProduct = (product) => {
    form.product_id = product.id;
    openCategory.value = null;
};
const barClass = (row) => (row.over ? 'bg-amber-400' : row.percent >= 100 ? 'bg-emerald-500' : 'bg-indigo-500');
</script>

<template>
    <Head :title="$e('План — факт')" />
    <AppLayout>
        <template #header>{{ $e('План — факт') }}</template>

        <div class="mx-auto max-w-6xl">
            <!-- Два вида одной страницы: план месяца и журнал смен. Раньше это
                 были два пункта меню, и «Наряды» дублировали «План — факт». -->
            <div class="tab-rail mb-5">
                <span class="tab-soft tab-soft-active">{{ $e('План — факт') }}</span>
                <Link :href="route('production.index', { month })"
                    class="tab-soft">{{ $e('Все наряды') }}</Link>
            </div>

            <!-- Шапка: месяц и одно действие. Больше на этой странице делать нечего. -->
            <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold text-slate-900">{{ $e('Задание цеху на месяц') }}</h2>
                    <p class="mt-0.5 text-xs text-slate-400">{{ $e('план ставит директор → бригада выполняет → подтверждает директор или финансист → бонус') }}</p>
                </div>
                <div class="flex items-center gap-2">
                    <input v-model="month" @change="applyMonth" type="month"
                        class="rounded-lg border-slate-200 py-1.5 text-sm shadow-sm transition focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20" />
                    <button v-if="canPlan" @click="openForm"
                        class="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white transition-colors duration-150 hover:bg-indigo-700">{{ $e('+ План') }}</button>
                </div>
            </div>

            <!-- Итог месяца. Метры и штуки — раздельно: сложить их в одно
                 число значит показать величину, которой не существует. -->
            <div v-if="plans.length" class="mb-6 flex flex-wrap gap-x-10 gap-y-4 border-b border-slate-100 pb-5">
                <div v-for="m in summary.measures" :key="m.measure">
                    <div class="text-[11px] uppercase tracking-wide text-slate-400">
                        {{ $e('Выполнено') }}, {{ measureLabel(m.measure) }}
                    </div>
                    <div class="mt-0.5 flex items-baseline gap-1.5">
                        <span class="text-2xl font-semibold tabular-nums text-slate-900">{{ num(m.done) }}</span>
                        <span class="text-sm tabular-nums text-slate-400">/ {{ num(m.plan) }}</span>
                    </div>
                    <div v-if="m.pending" class="text-xs tabular-nums text-amber-600">+{{ num(m.pending) }} {{ $e('ждёт') }}</div>
                </div>
                <div>
                    <div class="text-[11px] uppercase tracking-wide text-slate-400">{{ $e('Бонус за выполненное') }}</div>
                    <div class="mt-0.5 text-2xl font-semibold tabular-nums text-emerald-600">{{ money(summary.bonus) }}</div>
                </div>
            </div>

            <div v-if="!plans.length" class="rounded-xl border border-dashed border-slate-200 px-6 py-16 text-center">
                <p class="text-sm text-slate-500">{{ isForeman ? $e('На этот месяц вам план не поставили.') : $e('Планов на этот месяц нет.') }}</p>
                <button v-if="canPlan" @click="openForm" class="mt-3 text-sm font-semibold text-indigo-600 hover:underline">{{ $e('Поставить первый план') }}</button>
            </div>

            <!-- ===== Бригадир: свои планы карточками ===== -->
            <div v-else-if="isForeman" class="space-y-3">
                <!-- Своя бригада: состав, смены и начисления. Без этой ссылки
                     бригадир не мог попасть в карточку своей бригады вовсе. -->
                <Link v-for="b in myBrigades" :key="'b' + b.id" :href="route('production.brigade', { brigade: b.id, month })"
                    class="flex items-center gap-2 rounded-xl border border-slate-100 bg-white px-5 py-3 text-sm transition-colors duration-150 hover:bg-slate-50">
                    <span class="font-medium text-slate-800">👷 {{ b.name }}</span>
                    <span class="ml-auto text-xs font-semibold text-indigo-600">{{ $e('моя бригада') }} →</span>
                </Link>
                <div v-for="p in plans" :key="p.id" class="rounded-xl border border-slate-100 bg-white p-5">
                    <div class="flex flex-wrap items-baseline justify-between gap-3">
                        <div class="text-[15px] font-medium text-slate-900">{{ p.product }}</div>
                        <div class="text-sm tabular-nums">
                            <b class="text-lg" :class="p.over ? 'text-amber-600' : 'text-slate-900'">{{ num(p.done) }}</b>
                            <span class="text-slate-400"> / {{ num(p.plan) }} {{ p.unit }}</span>
                        </div>
                    </div>

                    <div class="mt-2.5 h-2 overflow-hidden rounded-full bg-slate-100">
                        <div class="h-full rounded-full transition-all duration-500" :class="barClass(p)"
                            :style="{ width: Math.min(p.percent, 100) + '%' }"></div>
                    </div>

                    <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs tabular-nums text-slate-500">
                        <span>{{ p.percent }}%</span>
                        <span v-if="p.left">{{ $e('осталось') }} <b class="text-slate-700">{{ num(p.left) }} {{ p.unit }}</b></span>
                        <span v-else class="font-semibold text-emerald-600">{{ $e('план закрыт ✓') }}</span>
                        <span v-if="p.pending" class="text-amber-600">{{ $e('ждёт подтверждения') }} {{ num(p.pending) }}</span>
                        <span class="ml-auto text-emerald-600">{{ money(p.bonus) }}</span>
                    </div>

                    <div class="mt-3 flex flex-wrap items-center gap-2 border-t border-slate-100 pt-3">
                        <template v-if="reporting === p.id">
                            <input v-model="qty" type="number" min="0" step="any" autofocus :placeholder="p.unit"
                                class="w-28 rounded-lg border-slate-200 py-1.5 text-sm shadow-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20"
                                @keyup.enter="saveReport(p)" />
                            <button :disabled="busy" @click="saveReport(p)"
                                class="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-700 disabled:opacity-50">{{ $e('Записать') }}</button>
                            <button class="text-xs text-slate-400 hover:text-slate-600" @click="reporting = null">{{ $e('Отмена') }}</button>
                        </template>
                        <button v-else-if="p.status === 'active'" @click="openReport(p)"
                            class="inline-flex items-center gap-1.5 rounded-lg bg-slate-100 px-3 py-1.5 text-xs font-semibold text-slate-700 transition-colors duration-150 hover:bg-slate-200">
                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                            {{ $e('Сделал') }}
                        </button>
                        <span v-else class="text-xs text-slate-400">{{ $e('План закрыт') }}</span>
                    </div>

                    <!-- Свои записи: что приняли, что ждёт, что вернули с причиной. -->
                    <div v-if="ordersOf(p.id).length" class="mt-3 space-y-1 border-t border-slate-100 pt-3 text-xs">
                        <div v-for="o in ordersOf(p.id)" :key="o.id" class="flex flex-wrap items-baseline gap-x-3 gap-y-0.5">
                            <span class="tabular-nums text-slate-400">{{ o.date }}</span>
                            <b class="tabular-nums text-slate-700">{{ num(o.qty) }} {{ o.unit }}</b>
                            <span v-if="o.status === 'confirmed'" class="text-emerald-600">✓ {{ $e('принято') }}</span>
                            <span v-else-if="o.status === 'rejected'" class="text-rose-600">✕ {{ o.reject_reason }}</span>
                            <span v-else class="text-amber-600">⏳ {{ $e('ждёт') }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ===== Руководство: блок на каждую бригаду ===== -->
            <div v-else class="space-y-4">
                <div v-for="b in byBrigade" :key="b.id" class="overflow-hidden rounded-xl border border-slate-100 bg-white">
                    <Link :href="route('production.brigade', { brigade: b.id, month })"
                        class="flex flex-wrap items-baseline justify-between gap-3 border-b border-slate-100 px-5 py-3.5 transition-colors duration-150 hover:bg-slate-50">
                        <div>
                            <span class="text-[15px] font-semibold text-slate-900">👷 {{ b.name }}</span>
                            <span class="ml-2 text-xs text-slate-400">{{ b.foreman || $e('бригадир не назначен') }}<template v-if="b.workshop"> · {{ b.workshop }}</template></span>
                        </div>
                        <div class="flex items-baseline gap-4 text-sm tabular-nums">
                            <span v-for="m in b.measures" :key="m.measure" class="text-slate-500">
                                {{ num(m.done) }} / {{ num(m.plan) }} <span class="text-xs">{{ measureLabel(m.measure) }}</span>
                            </span>
                            <b class="text-emerald-600">{{ money(b.bonus) }}</b>
                            <span class="text-xs text-indigo-600">{{ $e('подробнее') }} →</span>
                        </div>
                    </Link>

                    <div class="divide-y divide-slate-50">
                        <div v-for="p in b.rows" :key="p.id" class="px-5 py-3">
                            <div class="flex flex-wrap items-baseline justify-between gap-3">
                                <span class="text-sm text-slate-700">{{ p.product }}</span>
                                <div class="flex items-baseline gap-3 text-sm tabular-nums">
                                    <span><b :class="p.over ? 'text-amber-600' : 'text-slate-900'">{{ num(p.done) }}</b><span class="text-slate-400"> / {{ num(p.plan) }} {{ p.unit }}</span></span>
                                    <span class="w-12 text-right text-xs text-slate-400">{{ p.percent }}%</span>
                                    <span class="w-24 text-right text-emerald-600">{{ money(p.bonus) }}</span>
                                    <button v-if="canPlan && p.editable" @click="removePlan(p)" :title="$e('Убрать план')"
                                        class="rounded p-1 text-slate-300 transition-colors duration-150 hover:bg-rose-50 hover:text-rose-600">
                                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
                                    </button>
                                </div>
                            </div>
                            <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-slate-100">
                                <div class="h-full rounded-full transition-all duration-500" :class="barClass(p)"
                                    :style="{ width: Math.min(p.percent, 100) + '%' }"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Что ждёт подтверждения — руководству, отдельным блоком. -->
            <div v-if="!isForeman && orders.filter((o) => o.status === 'draft').length" class="mt-6 rounded-xl border border-amber-200 bg-white">
                <div class="border-b border-amber-100 px-5 py-3 text-sm font-semibold text-slate-900">
                    ⏳ {{ $e('Ждут подтверждения') }}
                    <span class="ml-1 rounded-full bg-amber-50 px-2 py-0.5 text-xs font-semibold text-amber-700">{{ summary.waiting }}</span>
                </div>
                <div class="divide-y divide-slate-50">
                    <div v-for="o in orders.filter((x) => x.status === 'draft')" :key="o.id"
                        class="flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-3 text-sm">
                        <span class="tabular-nums text-slate-400">{{ o.date }}</span>
                        <span class="text-slate-700">{{ o.brigade }}</span>
                        <span class="text-slate-500">{{ o.product }}</span>
                        <b class="tabular-nums text-slate-900">{{ num(o.qty) }} {{ o.unit }}</b>
                        <span class="text-xs text-slate-400">{{ $e('внёс') }}: {{ o.created_by }}</span>
                        <div v-if="canConfirm" class="ml-auto flex gap-2">
                            <button @click="confirmOrder(o)"
                                class="rounded-lg bg-emerald-600 px-3 py-1 text-xs font-semibold text-white transition-colors duration-150 hover:bg-emerald-700">{{ $e('Подтвердить') }}</button>
                            <button @click="rejectOrder(o)"
                                class="rounded-lg border border-slate-200 px-3 py-1 text-xs font-semibold text-slate-500 transition-colors duration-150 hover:bg-slate-50">{{ $e('Вернуть') }}</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Новый план -->
        <Modal :show="showForm" @close="showForm = false" max-width="lg">
            <div class="p-6">
                <h2 class="mb-1 text-lg font-semibold text-slate-900">{{ $e('План на месяц') }}</h2>
                <p class="mb-5 text-xs text-slate-400">{{ $e('Один план — один товар. Бригадир увидит его у себя сразу.') }}</p>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">{{ $e('Месяц') }}</label>
                        <input v-model="form.period_month" type="month" class="w-full rounded-lg border-slate-200 text-sm shadow-sm" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">{{ $e('Бригада') }}</label>
                        <select v-model="form.brigade_id" class="w-full rounded-lg border-slate-200 text-sm shadow-sm">
                            <option v-for="b in brigades" :key="b.id" :value="b.id">{{ b.name }}</option>
                        </select>
                    </div>
                    <!-- Товар по категориям: плоский список из двадцати позиций
                         читать нечем. Нажал «Плитка» — раскрылись плитки. -->
                    <div class="col-span-2">
                        <label class="mb-1 block text-xs font-medium text-slate-500">{{ $e('Товар') }}</label>
                        <div v-if="pickedProduct" class="flex items-center gap-2 rounded-lg border border-indigo-200 bg-indigo-50 px-3 py-2 text-sm">
                            <span class="font-medium text-indigo-900">{{ pickedProduct.name }}</span>
                            <span class="text-xs text-indigo-500">{{ pickedProduct.unit }}</span>
                            <button class="ml-auto text-xs font-semibold text-indigo-600 hover:underline" @click="form.product_id = ''">{{ $e('Изменить') }}</button>
                        </div>
                        <div v-else class="max-h-60 overflow-y-auto rounded-lg border border-slate-200">
                            <div v-for="c in productCategories" :key="c.id" class="border-b border-slate-100 last:border-0">
                                <button type="button" @click="openCategory = openCategory === c.id ? null : c.id"
                                    class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm transition-colors duration-150 hover:bg-slate-50">
                                    <svg class="h-3 w-3 shrink-0 text-slate-300 transition-transform duration-200" :class="openCategory === c.id ? 'rotate-90' : ''"
                                        viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
                                    <span class="font-medium text-slate-700">{{ c.name }}</span>
                                    <span class="ml-auto text-xs text-slate-400">{{ productsOf(c.id).length }}</span>
                                </button>
                                <div v-if="openCategory === c.id" class="bg-slate-50/60 pb-1">
                                    <button v-for="p in productsOf(c.id)" :key="p.id" type="button" @click="pickProduct(p)"
                                        class="flex w-full items-baseline gap-2 px-3 py-1.5 pl-8 text-left text-sm transition-colors duration-150 hover:bg-white">
                                        <span class="text-slate-700">{{ p.name }}</span>
                                        <span class="text-xs text-slate-400">{{ p.unit }}</span>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <p v-if="form.errors.product_id" class="mt-1 text-xs text-rose-600">{{ form.errors.product_id }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">
                            {{ $e('Объём') }} <span v-if="pickedProduct" class="text-slate-400">{{ pickedProduct.unit }}</span>
                        </label>
                        <input v-model="form.plan_qty" type="number" min="0" step="any" class="w-full rounded-lg border-slate-200 text-sm shadow-sm" />
                        <p v-if="form.errors.plan_qty" class="mt-1 text-xs text-rose-600">{{ form.errors.plan_qty }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">{{ $e('Ставка бригадира, ₸ за единицу') }}</label>
                        <input v-model="form.bonus_rate" type="number" min="0" step="any" :placeholder="$e('по настройкам')"
                            class="w-full rounded-lg border-slate-200 text-sm shadow-sm" />
                    </div>
                    <div class="col-span-2">
                        <label class="mb-1 block text-xs font-medium text-slate-500">{{ $e('Заметка') }}</label>
                        <input v-model="form.note" type="text" class="w-full rounded-lg border-slate-200 text-sm shadow-sm" />
                    </div>
                </div>

                <div class="mt-6 flex justify-end gap-2">
                    <button @click="showForm = false" class="rounded-lg px-4 py-2 text-sm font-medium text-slate-500 hover:text-slate-700">{{ $e('Отмена') }}</button>
                    <button :disabled="form.processing" @click="submit"
                        class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition-colors duration-150 hover:bg-indigo-700 disabled:opacity-50">{{ $e('Поставить план') }}</button>
                </div>
            </div>
        </Modal>
    </AppLayout>
</template>
