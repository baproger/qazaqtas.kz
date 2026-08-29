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
import FinanceTile from '@/Components/FinanceTile.vue';
import { money } from '@/utils/format';
import { confirmDialog } from '@/composables/useConfirm';
import { useE } from '@/composables/useTranslations';

const tr = useE();

const props = defineProps({
    month: { type: String, default: '' },
    plans: { type: Array, default: () => [] },
    orders: { type: Array, default: () => [] },
    brigadeOutput: { type: Array, default: () => [] },
    queue: { type: Array, default: () => [] },
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

// Записи по плану свёрнуты: их открывают, когда цифра не сходится, а не всегда.
const openRecords = ref(null);

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

/*
 * Блок на бригаду — ОДИН для обеих ролей.
 *
 * Раньше бригадир видел плоский список планов, а над ним пустую полоску с
 * названием бригады: одни и те же цифры лежали и здесь, и в карточке бригады,
 * а чья это работа — приходилось догадываться. Теперь план всегда внутри
 * своей бригады, и разница между ролями только в кнопках: «Сделал» у
 * бригадира, «убрать план» у директора.
 *
 * Шапка блока несёт сводку: сделано/план по каждой метрике, процент, сколько
 * ждёт мастера и бонус. Метры и штуки не складываются — единица плана решает,
 * что считать (§4 «Производство»).
 */
const groupByBrigade = (plans) => {
    const map = new Map();
    for (const p of plans) {
        if (!map.has(p.brigade_id)) {
            map.set(p.brigade_id, { id: p.brigade_id, name: p.brigade, foreman: p.foreman, workshop: p.workshop, rows: [] });
        }
        map.get(p.brigade_id).rows.push(p);
    }
    return [...map.values()].map((b) => {
        const measures = ['m2', 'pcs']
            .map((measure) => {
                const rows = b.rows.filter((r) => r.measure === measure);
                const done = rows.reduce((s, r) => s + Number(r.done || 0), 0);
                const plan = rows.reduce((s, r) => s + Number(r.plan || 0), 0);

                return {
                    measure,
                    rows: rows.length,
                    done,
                    plan,
                    pending: rows.reduce((s, r) => s + Number(r.pending || 0), 0),
                    // Процент считаем по сумме бригады, а не средним по планам:
                    // среднее уравняло бы план на 10 штук с планом на 1000.
                    percent: plan > 0 ? Math.round((done / plan) * 100) : 0,
                };
            })
            .filter((m) => m.rows > 0);

        return {
            ...b,
            measures,
            plans: b.rows.length,
            bonus: b.rows.reduce((s, r) => s + Number(r.bonus || 0), 0),
            pending: measures.reduce((s, m) => s + m.pending, 0),
        };
    });
};

/*
 * Два блока вместо одного списка: «в работе» и «выполнено».
 *
 * Невыполненные идут ПЕРВЫМИ — это то, чем цех занят сегодня; закрытые планы
 * читают, когда сверяют месяц. В общей куче закрытый план на 100% выглядел
 * так же, как начатый, и найти незакрытое можно было только глазами.
 *
 * Признак «выполнен» считает СЕРВЕР (`plan.done`): посчитай его здесь — и
 * деление на блоки разошлось бы с подытогами, которые считает он же.
 */
const activePlans = computed(() => props.plans.filter((p) => !p.done));
const donePlans = computed(() => props.plans.filter((p) => p.done));
const activeByBrigade = computed(() => groupByBrigade(activePlans.value));
const doneByBrigade = computed(() => groupByBrigade(donePlans.value));

const showDone = ref(false);

// Очередь: объём пришёл из сделки, бригады ещё нет. Пока строка здесь, цех о
// ней знает, но никто за неё не отвечает — поэтому блок стоит ПЕРВЫМ.
const assigning = ref(null);
const assignTo = ref('');
const assignBusy = ref(false);
const openAssign = (row) => { assigning.value = row.id; assignTo.value = props.brigades[0]?.id ?? ''; };
const saveAssign = (row) => {
    if (!assignTo.value || assignBusy.value) return;
    assignBusy.value = true;
    router.put(route('production.plans.assign', row.id), { brigade_id: assignTo.value }, {
        preserveScroll: true,
        onFinish: () => { assignBusy.value = false; assigning.value = null; },
    });
};

// Секции как данные: разметка блока одна на обе. Скопируй её второй раз, и
// правка в «в работе» однажды не доедет до «выполнено».
const sections = computed(() => [
    {
        key: 'active',
        title: tr('В работе'),
        hint: tr('чем цех занят сейчас'),
        groups: activeByBrigade.value,
        sum: props.summary.active,
        tone: 'indigo',
        collapsible: false,
    },
    {
        key: 'done',
        title: tr('Выполнено'),
        hint: tr('план закрыт — читают, когда сверяют месяц'),
        groups: doneByBrigade.value,
        sum: props.summary.done,
        tone: 'emerald',
        collapsible: true,
    },
]);
const sectionOpen = (section) => !section.collapsible || showDone.value;

// Итог таблицы выработки: складывать её в шаблоне значит считать в браузере
// то, что уже посчитано по строкам, — и однажды разойтись с ними.
const outputTotals = computed(() => props.brigadeOutput.reduce((acc, b) => ({
    shifts: acc.shifts + Number(b.shifts || 0),
    m2: acc.m2 + Number(b.m2 || 0),
    pcs: acc.pcs + Number(b.pcs || 0),
    amount: acc.amount + Number(b.amount || 0),
    plan_amount: acc.plan_amount + Number(b.plan_amount || 0),
}), { shifts: 0, m2: 0, pcs: 0, amount: 0, plan_amount: 0 }));

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

        <div class="mx-auto max-w-7xl">
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

            <!-- Итог месяца — теми же плитками, что на «Всех нарядах»:
                 две вкладки одной страницы должны читаться одинаково, иначе
                 при переключении глазу приходится заново искать цифры.
                 Метры и штуки раздельно: сложить их значит показать величину,
                 которой не существует. -->
            <div v-if="plans.length" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <FinanceTile v-for="m in summary.measures" :key="m.measure"
                    :label="$e('Выполнено') + ', ' + measureLabel(m.measure)"
                    :value="num(m.done) + ' / ' + num(m.plan)"
                    :hint="m.pending ? '+' + num(m.pending) + ' ' + $e('ждёт мастера') : ''"
                    :tone="m.pending ? 'warn' : 'default'" />
                <FinanceTile tone="good" :label="$e('Бонус за выполненное')" :value="money(summary.bonus)"
                    :hint="$e('идёт в бонусы сотрудников')" />
            </div>

            <div v-if="!plans.length && !queue.length" class="mt-6 rounded-2xl border border-dashed border-slate-200 px-6 py-16 text-center">
                <p class="text-sm text-slate-500">{{ isForeman ? $e('На этот месяц вам план не поставили.') : $e('Планов на этот месяц нет.') }}</p>
                <button v-if="canPlan" @click="openForm" class="mt-3 text-sm font-semibold text-indigo-600 hover:underline">{{ $e('Поставить первый план') }}</button>
            </div>

            <!-- ===== Блок на каждую бригаду: план внутри своей бригады =====
                 Один и тот же блок у бригадира и у руководства. Разница только
                 в кнопках: «Сделал» пишет выработку, корзина убирает план. -->
            <div v-else class="mt-6 space-y-6">
              <!-- ===== Очередь: пришло из сделок, бригада не назначена =====
                   Стоит первой и подсвечена: пока строка здесь, за объём
                   никто не отвечает. Назначил бригаду — уехала в «В работе». -->
              <section v-if="queue.length">
                  <div class="mb-3 flex flex-wrap items-baseline gap-x-3">
                      <span class="text-sm font-semibold text-amber-800">{{ $e('Ждёт бригаду') }}</span>
                      <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold tabular-nums text-amber-800">{{ queue.length }}</span>
                      <span class="text-xs text-slate-400">{{ $e('пришло из сделок — назначьте, кто делает') }}</span>
                  </div>
                  <div class="overflow-hidden rounded-2xl border border-amber-200 bg-white shadow-soft">
                      <div class="divide-y divide-amber-100/70">
                          <div v-for="row in queue" :key="row.id" class="flex flex-wrap items-center gap-x-4 gap-y-2 px-6 py-3.5">
                              <div class="min-w-0 flex-1">
                                  <div class="text-sm font-medium text-slate-900">🧱 {{ row.product }}</div>
                                  <div v-if="row.deal" class="text-xs text-slate-400">
                                      <Link :href="route('deals.show', row.deal.id)" class="font-semibold text-indigo-600 hover:underline">{{ row.deal.number }}</Link>
                                      · {{ row.deal.client }}
                                      <span v-if="row.deal.deadline"> · {{ $e('срок') }} {{ row.deal.deadline }}</span>
                                  </div>
                              </div>
                              <span class="text-sm font-semibold tabular-nums text-slate-900">{{ num(row.qty) }} {{ row.unit }}</span>

                              <template v-if="canPlan">
                                  <template v-if="assigning === row.id">
                                      <select v-model="assignTo" class="w-40 rounded-lg border-slate-200 py-1 text-xs shadow-sm">
                                          <option v-for="b in brigades" :key="b.id" :value="b.id">{{ b.name }}</option>
                                      </select>
                                      <button :disabled="assignBusy" @click="saveAssign(row)"
                                          class="rounded-lg bg-amber-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-amber-700 disabled:opacity-50">{{ $e('Назначить') }}</button>
                                      <button class="text-xs text-slate-400 hover:text-slate-600" @click="assigning = null">{{ $e('Отмена') }}</button>
                                  </template>
                                  <button v-else @click="openAssign(row)"
                                      class="rounded-lg border border-amber-300 px-3 py-1.5 text-xs font-semibold text-amber-800 transition-colors duration-150 hover:bg-amber-50">
                                      {{ $e('Назначить бригаду') }}
                                  </button>
                              </template>
                              <span v-else class="text-xs text-slate-400">{{ $e('бригаду назначает начальник производства') }}</span>
                          </div>
                      </div>
                  </div>
              </section>

              <section v-for="section in sections" :key="section.key" v-show="section.groups.length">
                <!-- Шапка секции с ПОДЫТОГОМ: сумма «в работе» и «выполнено»
                     обязана сойтись с плитками сверху, и это должно быть
                     видно глазами, а не на веру. -->
                <button type="button" class="mb-3 flex w-full flex-wrap items-baseline gap-x-3 gap-y-1 text-left"
                    :disabled="!section.collapsible" @click="section.collapsible && (showDone = !showDone)">
                    <span class="flex items-center gap-1.5 text-sm font-semibold"
                        :class="section.tone === 'emerald' ? 'text-emerald-700' : 'text-slate-900'">
                        <svg v-if="section.collapsible" class="h-3 w-3 transition-transform duration-200" :class="sectionOpen(section) ? 'rotate-90' : ''"
                            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
                        {{ section.title }}
                    </span>
                    <span class="rounded-full px-2 py-0.5 text-xs font-semibold tabular-nums"
                        :class="section.tone === 'emerald' ? 'bg-emerald-50 text-emerald-700' : 'bg-indigo-50 text-indigo-700'">
                        {{ section.sum?.count ?? 0 }}
                    </span>
                    <span class="text-xs text-slate-400">{{ section.hint }}</span>
                    <span class="ml-auto flex flex-wrap items-baseline gap-x-3 text-xs tabular-nums text-slate-500">
                        <span v-for="m in section.sum?.measures ?? []" :key="m.measure">
                            {{ num(m.done) }} / {{ num(m.plan) }} {{ measureLabel(m.measure) }}
                        </span>
                        <b class="text-emerald-600">{{ money(section.sum?.bonus ?? 0) }}</b>
                    </span>
                </button>

                <div v-show="sectionOpen(section)" class="space-y-4">
                <div v-for="b in section.groups" :key="section.key + '-' + b.id" class="overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-soft">
                    <!-- Шапка бригады: слева кто и где, справа как идёт.
                         Пустой полосы с одним названием больше нет — на неё
                         смотрели и не понимали, что она сообщает. -->
                    <Link :href="route('production.brigade', { brigade: b.id, month })"
                        class="flex flex-wrap items-center justify-between gap-x-6 gap-y-2 border-b border-slate-100 px-6 py-4 transition-colors duration-150 hover:bg-slate-50">
                        <div class="min-w-0">
                            <span class="text-base font-semibold text-slate-900">👷 {{ b.name }}</span>
                            <span class="ml-2 text-xs text-slate-400">
                                {{ b.foreman || $e('бригадир не назначен') }}<template v-if="b.workshop"> · {{ b.workshop }}</template>
                                · {{ b.plans }} {{ b.plans === 1 ? $e('план') : $e('планов') }}
                            </span>
                        </div>
                        <div class="flex flex-wrap items-baseline gap-x-4 gap-y-1 text-sm tabular-nums">
                            <span v-for="m in b.measures" :key="m.measure" class="text-slate-500">
                                <b :class="m.percent >= 100 ? 'text-emerald-600' : 'text-slate-900'">{{ num(m.done) }}</b>
                                / {{ num(m.plan) }} <span class="text-xs">{{ measureLabel(m.measure) }}</span>
                                <span class="ml-1 text-xs" :class="m.percent >= 100 ? 'text-emerald-600' : 'text-slate-400'">{{ m.percent }}%</span>
                            </span>
                            <!-- Ждёт мастера: пока не подтвердил — это ещё не бонус. -->
                            <span v-if="b.pending" class="rounded bg-amber-50 px-1.5 py-0.5 text-xs font-semibold text-amber-700"
                                :title="$e('внесено, но мастер ещё не подтвердил')">+{{ num(b.pending) }} {{ $e('ждёт') }}</span>
                            <b class="text-emerald-600">{{ money(b.bonus) }}</b>
                            <span class="text-xs font-semibold text-indigo-600">{{ isForeman ? $e('моя бригада') : $e('подробнее') }} →</span>
                        </div>
                    </Link>

                    <div class="divide-y divide-slate-50">
                        <div v-for="p in b.rows" :key="p.id" class="px-6 py-3.5">
                            <div class="flex flex-wrap items-baseline justify-between gap-3">
                                <span class="text-sm font-medium text-slate-800">{{ p.product }}</span>
                                <div class="flex items-baseline gap-3 text-sm tabular-nums">
                                    <span><b :class="p.over ? 'text-amber-600' : 'text-slate-900'">{{ num(p.done) }}</b><span class="text-slate-400"> / {{ num(p.plan) }} {{ p.unit }}</span></span>
                                    <span class="w-12 text-right text-xs text-slate-400">{{ p.percent }}%</span>
                                    <span class="w-24 text-right text-emerald-600">{{ money(p.bonus) }}</span>
                                    <button v-if="canPlan && p.editable" @click.stop="removePlan(p)" :title="$e('Убрать план')"
                                        class="rounded p-1 text-slate-300 transition-colors duration-150 hover:bg-rose-50 hover:text-rose-600">
                                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
                                    </button>
                                </div>
                            </div>

                            <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-slate-100">
                                <div class="h-full rounded-full transition-all duration-500" :class="barClass(p)"
                                    :style="{ width: Math.min(p.percent, 100) + '%' }"></div>
                            </div>

                            <div class="mt-1.5 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs tabular-nums text-slate-500">
                                <span v-if="p.left">{{ $e('осталось') }} <b class="text-slate-700">{{ num(p.left) }} {{ p.unit }}</b></span>
                                <span v-else class="font-semibold text-emerald-600">{{ $e('план закрыт ✓') }}</span>
                                <span v-if="p.pending" class="text-amber-600">{{ $e('ждёт подтверждения') }} {{ num(p.pending) }}</span>
                            </div>

                            <!-- «Сделал» — только бригадиру: он вводит выработку,
                                 стоя у формы с телефоном, и лишний переход ему дорог. -->
                            <div v-if="isForeman" class="mt-3 flex flex-wrap items-center gap-2 border-t border-slate-100 pt-3">
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

                                <!-- Свои записи по этому плану: что приняли, что
                                     вернули и с какой причиной. Свёрнуты — их
                                     смотрят, когда цифра не сходится. -->
                                <button v-if="ordersOf(p.id).length" type="button" @click="openRecords = openRecords === p.id ? null : p.id"
                                    class="ml-auto inline-flex items-center gap-1 text-xs font-medium text-slate-400 transition-colors duration-150 hover:text-indigo-600">
                                    {{ ordersOf(p.id).length }} {{ $e('записей') }}
                                    <svg class="h-3 w-3 transition-transform duration-200" :class="openRecords === p.id ? 'rotate-90' : ''"
                                        viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
                                </button>
                            </div>

                            <div v-if="isForeman && openRecords === p.id" class="mt-2 space-y-1 border-t border-slate-100 pt-2 text-xs">
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
                </div>
                </div>
              </section>

                <!-- ===== Выработка бригад за месяц =====
                     Сколько каждая бригада сделала и на сколько. Берём ВСЕ
                     подтверждённые наряды, а не только по планам: бригада
                     работает и под заказ клиента. Но колонка «по плану»
                     выделена — она обязана сойтись с подытогами выше, иначе
                     понять, какая цифра верная, было бы нечем. -->
                <section v-if="brigadeOutput.length">
                    <div class="mb-3 flex flex-wrap items-baseline gap-x-3">
                        <span class="text-sm font-semibold text-slate-900">{{ $e('Выработка бригад за месяц') }}</span>
                        <span class="text-xs text-slate-400">{{ $e('только подтверждённые смены — неподтверждённое ещё не деньги') }}</span>
                    </div>
                    <div class="overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-soft">
                        <div class="overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-400">
                                    <tr>
                                        <th class="px-6 py-2.5 text-left font-medium">{{ $e('Бригада') }}</th>
                                        <th class="px-4 py-2.5 text-right font-medium">{{ $e('Смен') }}</th>
                                        <th class="px-4 py-2.5 text-right font-medium">{{ $e('м²') }}</th>
                                        <th class="px-4 py-2.5 text-right font-medium">{{ $e('штук') }}</th>
                                        <th class="px-4 py-2.5 text-right font-medium">{{ $e('Из них по плану') }}</th>
                                        <th class="px-6 py-2.5 text-right font-medium">{{ $e('Начислено') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-50">
                                    <tr v-for="b in brigadeOutput" :key="b.id" class="transition-colors duration-150 hover:bg-slate-50/60">
                                        <td class="px-6 py-2.5">
                                            <Link :href="route('production.brigade', { brigade: b.id, month })" class="font-medium text-slate-800 hover:text-indigo-600">👷 {{ b.name }}</Link>
                                            <span v-if="b.workshop" class="ml-1.5 text-xs text-slate-400">{{ b.workshop }}</span>
                                        </td>
                                        <td class="px-4 py-2.5 text-right tabular-nums text-slate-500">{{ b.shifts }}</td>
                                        <td class="px-4 py-2.5 text-right tabular-nums text-slate-600">{{ b.m2 ? num(b.m2) : '—' }}</td>
                                        <td class="px-4 py-2.5 text-right tabular-nums text-slate-600">{{ b.pcs ? num(b.pcs) : '—' }}</td>
                                        <td class="px-4 py-2.5 text-right tabular-nums text-slate-500">{{ money(b.plan_amount) }}</td>
                                        <td class="px-6 py-2.5 text-right font-semibold tabular-nums text-emerald-600">{{ money(b.amount) }}</td>
                                    </tr>
                                </tbody>
                                <tfoot class="border-t border-slate-100 bg-slate-50/60 text-xs">
                                    <tr>
                                        <td class="px-6 py-2.5 font-medium text-slate-500">{{ $e('Итого') }}</td>
                                        <td class="px-4 py-2.5 text-right tabular-nums text-slate-500">{{ outputTotals.shifts }}</td>
                                        <td class="px-4 py-2.5 text-right tabular-nums text-slate-600">{{ outputTotals.m2 ? num(outputTotals.m2) : '—' }}</td>
                                        <td class="px-4 py-2.5 text-right tabular-nums text-slate-600">{{ outputTotals.pcs ? num(outputTotals.pcs) : '—' }}</td>
                                        <td class="px-4 py-2.5 text-right tabular-nums text-slate-500">{{ money(outputTotals.plan_amount) }}</td>
                                        <td class="px-6 py-2.5 text-right font-semibold tabular-nums text-emerald-600">{{ money(outputTotals.amount) }}</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </section>
            </div>

            <!-- Что ждёт подтверждения — руководству, отдельным блоком. -->
            <div v-if="!isForeman && orders.filter((o) => o.status === 'draft').length" class="mt-6 overflow-hidden rounded-2xl border border-amber-200 bg-white shadow-soft">
                <div class="border-b border-amber-100 px-6 py-4 text-sm font-semibold text-slate-900">
                    ⏳ {{ $e('Ждут подтверждения') }}
                    <span class="ml-1 rounded-full bg-amber-50 px-2 py-0.5 text-xs font-semibold text-amber-700">{{ summary.waiting }}</span>
                </div>
                <div class="divide-y divide-slate-50">
                    <div v-for="o in orders.filter((x) => x.status === 'draft')" :key="o.id"
                        class="flex flex-wrap items-center gap-x-4 gap-y-2 px-6 py-3 text-sm">
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
