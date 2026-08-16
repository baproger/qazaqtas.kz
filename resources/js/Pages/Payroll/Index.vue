<script setup>
import { ref, computed } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Avatar from '@/Components/Avatar.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { money, formatDate, formatDateTime } from '@/utils/format';
import { confirmDialog } from '@/composables/useConfirm';
import { useE } from '@/composables/useTranslations';

const tr = useE();

const props = defineProps({ rows: Array, leadership: Boolean, canManage: Boolean, month: String, normHours: Number, deptNorms: { type: [Object, Array], default: () => ({}) }, taxRate: Number, totals: Object });
const me = props.rows[0] ?? null;

// Шкала бонусов — коммерческая информация: видят только отдел продаж
// (менеджеры), финансисты и админ; цеху и прочим сотрудникам не показываем.
const myRoles = usePage().props.auth.user?.roles ?? [];
const seesBonusScale = ['manager', 'financist', 'admin'].some((r) => myRoles.includes(r));
const BONUS_TIERS = [
    { m: 'до 10%', b: 'бонуса нет', muted: true },
    { m: '11% – 15%', b: '5% от остатка' },
    { m: '16% – 20%', b: '7% от остатка' },
    { m: '21% – 30%', b: '10% от остатка' },
    { m: '31% – 40%', b: '13% от остатка' },
    { m: 'от 41%', b: '15% от остатка' },
];

const open = ref(new Set());
const toggle = (uid) => { const s = new Set(open.value); s.has(uid) ? s.delete(uid) : s.add(uid); open.value = s; };

// Живой поиск по сотруднику и отборы-чипы. Фильтрация КЛИЕНТСКАЯ: строки
// ведомости уже загружены, и гонять сервер ради подстроки незачем.
const search = ref('');
const searchInput = ref('');
let searchTimer = null;
const onSearch = (value) => {
    searchInput.value = value;
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => (search.value = value.trim().toLowerCase()), 300);
};
const FILTERS = [
    { key: 'bonus_month', label: tr('с бонусом за месяц'), test: (r) => (r.bonus_month || 0) > 0 },
    { key: 'debt', label: tr('с долгом'), test: (r) => (r.debt?.balance || 0) > 0 },
    { key: 'deductions', label: tr('с удержаниями'), test: (r) => (r.deductions || 0) > 0 },
];
const activeFilters = ref(new Set());
const toggleFilter = (key) => {
    const next = new Set(activeFilters.value);
    next.has(key) ? next.delete(key) : next.add(key);
    activeFilters.value = next;
};
const filtered = computed(() => props.rows.filter((r) => {
    if (search.value && !String(r.user ?? '').toLowerCase().includes(search.value)) return false;

    return FILTERS.every((f) => !activeFilters.value.has(f.key) || f.test(r));
}));
const isFiltering = computed(() => search.value !== '' || activeFilters.value.size > 0);

// Ведомость — раздельными секциями по отделам: отделы с большей выплатой сверху,
// «Без отдела» — как обычная секция; внутри порядок строк серверный (по бонусу).
// Секции строятся по НАЙДЕННЫМ строкам, поэтому и суммы в них — по найденным;
// пустые отделы при отборе просто исчезают.
const groups = computed(() => {
    const map = new Map();
    for (const r of filtered.value) {
        const k = r.department || 'Без отдела';
        if (!map.has(k)) map.set(k, []);
        map.get(k).push(r);
    }
    return [...map.entries()]
        .map(([name, list]) => {
            const id = list[0]?.department_id ?? null;
            const own = id != null ? props.deptNorms?.[id] : null; // своя норма отдела
            return {
                name, list, id,
                norm: own ?? props.normHours,
                override: own != null,
                final: list.reduce((s, r) => s + (r.final || 0), 0),
            };
        })
        .sort((a, b) => b.final - a.final || a.name.localeCompare(b.name, 'ru'));
});
// Своя норма часов отдела: правка в заголовке секции; пусто — сброс на общую.
const editingDeptNorm = ref(null);
const deptNormVal = ref('');
const editDeptNorm = (g) => { editingDeptNorm.value = g.name; deptNormVal.value = g.override ? g.norm : ''; };
const saveDeptNorm = (g) => router.patch(route('payroll.norm'), {
    month: props.month, department_id: g.id,
    norm: deptNormVal.value === '' ? null : Number(deptNormVal.value),
}, { preserveScroll: true, onSuccess: () => (editingDeptNorm.value = null) });
// Свернуть/развернуть секцию отдела кликом по её заголовку (по умолчанию все раскрыты).
const collapsed = ref(new Set());
const toggleDept = (name) => { const s = new Set(collapsed.value); s.has(name) ? s.delete(name) : s.add(name); collapsed.value = s; };

// Месяц корректировок (отгулы/больничные/штрафы) — серверный фильтр.
const monthSel = ref(props.month);
const setMonth = () => router.get(route('payroll.index'), { month: monthSel.value || undefined }, { preserveState: true, preserveScroll: true, replace: true });

const typeLabels = { absence: tr('Отгул'), sick: tr('Больничный'), fine: tr('Штраф'), advance: tr('Аванс'), bonus: tr('Премия') };
// «2026-07» → «июль 2026» для заголовков.
const monthLabel = new Date(props.month + '-01').toLocaleDateString('ru-RU', { month: 'long', year: 'numeric' });
const typeClass = (t) => t === 'bonus' ? 'bg-emerald-100 text-emerald-700' : t === 'fine' ? 'bg-rose-100 text-rose-700' : t === 'advance' ? 'bg-indigo-100 text-indigo-700' : 'bg-amber-100 text-amber-700';

// Оклад: инлайн-правка (бухгалтер/админ).
const editingSalary = ref(null);
const salaryVal = ref('');
const editSalary = (r) => { editingSalary.value = r.uid; salaryVal.value = Number(r.salary) || ''; };
const saveSalary = (r) => router.patch(route('payroll.salary', r.uid), { salary: Number(salaryVal.value) || 0 }, {
    preserveScroll: true, onSuccess: () => (editingSalary.value = null),
});

// Почасовой оклад: норма часов месяца (одна на всех) + отработанные часы сотрудника.
// Ставка/час = оклад ÷ норма; начислено = часы × ставка; без часов — полный оклад.
const normVal = ref(props.normHours || '');
const editingNorm = ref(false);
const editNorm = () => { normVal.value = props.normHours || ''; editingNorm.value = true; };
const saveNorm = () => {
    const n = Number(normVal.value);
    if (!(n > 0)) return;
    if (n === props.normHours) { editingNorm.value = false; return; }
    router.patch(route('payroll.norm'), { month: props.month, norm: n }, { preserveScroll: true, onSuccess: () => (editingNorm.value = false) });
};
const editingHours = ref(null);
const hoursVal = ref('');
const editHours = (r) => { editingHours.value = r.uid; hoursVal.value = r.hours ?? ''; };
const saveHours = (r) => router.patch(route('payroll.hours', r.uid), {
    month: props.month, hours: hoursVal.value === '' ? null : Number(hoursVal.value),
}, { preserveScroll: true, onSuccess: () => (editingHours.value = null) });

// Корректировка: отгул/больничный — днями (сумма авто = оклад/22 × дни) или суммой.
const showAdj = ref(false);
const adjForm = useForm({ user_id: '', type: 'absence', days: '', amount: '', date: new Date().toISOString().slice(0, 10), note: '', payment_method: 'cash' });
const openAdj = (uid = '') => { adjForm.reset(); adjForm.user_id = uid; adjForm.date = new Date().toISOString().slice(0, 10); showAdj.value = true; };
const submitAdj = () => adjForm.post(route('payroll.adjustments.store'), { preserveScroll: true, onSuccess: () => (showAdj.value = false) });
// Долг: выдача из кассы/банка. От аванса отличается тем, что переходит из
// месяца в месяц и гасится только из бонуса — подсказки в обеих модалках.
const showDebt = ref(false);
const debtForm = useForm({ user_id: '', amount: '', monthly_payment: '', payment_method: 'cash', note: '' });
const openDebt = (uid = '') => {
    debtForm.reset();
    debtForm.clearErrors();
    debtForm.user_id = uid;
    showDebt.value = true;
};
const submitDebt = () => debtForm.post(route('payroll.debts.store'), { preserveScroll: true, onSuccess: () => (showDebt.value = false) });
const cancelDebt = async (d) => {
    if (await confirmDialog({
        title: tr('Отменить выдачу долга'),
        message: tr('Долг будет удалён, а деньги вернутся в кассу.'),
        confirmText: tr('Отменить выдачу'),
        danger: true,
    })) {
        router.delete(route('payroll.debts.destroy', d.id), { preserveScroll: true });
    }
};

const delAdj = async (a) => {
    if (await confirmDialog({ title: tr('Удалить корректировку'), message: `«${typeLabels[a.type]} ${money(a.amount)}» будет удалена.`, confirmText: tr('Удалить'), danger: true })) {
        router.delete(route('payroll.adjustments.destroy', a.id), { preserveScroll: true });
    }
};
</script>

<template>
    <Head :title="$e('Зарплата')" />
    <AppLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <span>{{ $t('page.payroll', 'Зарплата и бонусы') }}</span>
                <div class="flex items-center gap-2">
                    <label class="flex items-center gap-1 text-xs font-normal text-slate-400">{{ $e('месяц') }}
                        <input v-model="monthSel" @change="setMonth" type="month" class="rounded-lg border-slate-200 py-1.5 text-xs font-normal shadow-sm" />
                    </label>
                    <button v-if="canManage" @click="openAdj()"
                        class="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-indigo-700">{{ $e('+ Корректировка') }}</button>
                </div>
            </div>
        </template>

        <!-- Manager: слева выплата/корректировки/сделки, справа — шкала бонусов -->
        <div v-if="!leadership" class="grid max-w-5xl grid-cols-1 items-start gap-4 lg:grid-cols-3">
            <div class="space-y-4" :class="seesBonusScale ? 'lg:col-span-2' : 'lg:col-span-3'">
            <div class="rounded-2xl bg-white p-6 shadow-sm border border-slate-200">
                <div class="text-xs uppercase text-slate-400">{{ $e('К выплате ·') }} {{ monthLabel }}</div>
                <div class="mt-1 text-3xl font-bold text-green-600">{{ money(me?.final ?? me?.payout ?? 0) }}</div>
                <div class="mt-4 space-y-2 text-sm">
                    <div class="flex justify-between">
                        <span class="text-slate-500">{{ $e('Оклад') }}<template v-if="me?.hours != null"> · {{ me.hours }} {{ $e('ч ×') }} {{ money(me.hourly_rate) }}{{ $e('/ч') }}</template></span>
                        <span class="font-medium tabular-nums">{{ money(me?.base ?? me?.salary ?? 0) }}</span>
                    </div>
                    <div class="flex justify-between"><span class="text-slate-500">{{ $e('Бонус по марже сделок') }}</span><span class="font-medium tabular-nums text-emerald-600">{{ money(me?.bonus ?? 0) }}</span></div>
                    <div v-if="me?.deductions" class="flex justify-between"><span class="text-slate-500">{{ $e('Удержания (отгул/больничный/штраф/аванс)') }}</span><span class="font-medium tabular-nums text-rose-600">− {{ money(me.deductions) }}</span></div>
                    <div v-if="me?.additions" class="flex justify-between"><span class="text-slate-500">{{ $e('Премии') }}</span><span class="font-medium tabular-nums text-emerald-600">+ {{ money(me.additions) }}</span></div>
                    <div class="flex justify-between"><span class="text-slate-500">{{ $e('Успешных сделок') }}</span><span class="font-medium">{{ me?.closed ?? 0 }}</span></div>
                </div>
            </div>

            <!-- Корректировки за месяц -->
            <div v-if="me?.adjustments?.length" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $e('Корректировки ·') }} {{ monthLabel }}</div>
                <div class="divide-y divide-slate-50 text-sm">
                    <div v-for="a in me.adjustments" :key="a.id" class="flex items-center justify-between gap-2 py-2">
                        <div class="flex items-center gap-2">
                            <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold" :class="typeClass(a.type)">{{ typeLabels[a.type] }}</span>
                            <span class="text-xs text-slate-400">{{ formatDate(a.date) }}<template v-if="a.days"> · {{ a.days }} {{ $e('дн.') }}</template><template v-if="a.note"> · {{ a.note }}</template> {{ $e('· внесено') }} {{ formatDateTime(a.created_at) }}</span>
                        </div>
                        <span class="font-semibold tabular-nums" :class="a.type === 'bonus' ? 'text-emerald-600' : 'text-rose-600'">{{ a.type === 'bonus' ? '+' : '−' }} {{ money(a.amount) }}</span>
                    </div>
                </div>
            </div>

            <div v-if="me?.dealsList?.length" class="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm">
                <table class="min-w-full divide-y divide-slate-100 text-xs">
                    <thead class="bg-slate-50 text-left uppercase tracking-wide text-slate-400">
                        <tr><th class="px-3 py-2">{{ $e('Сделка') }}</th><th class="px-3 py-2">{{ $e('Этап') }}</th><th class="px-3 py-2 text-right">{{ $e('Сумма') }}</th><th class="px-3 py-2 text-right">{{ $e('Оплачено') }}</th><th class="px-3 py-2 text-right text-emerald-600">{{ $e('Бонус') }}</th></tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        <tr v-for="d in me.dealsList" :key="d.id" class="hover:bg-slate-50">
                            <td class="px-3 py-2"><Link :href="route('deals.show', d.id)" class="font-medium text-indigo-600 hover:underline">{{ d.company }}</Link> <span class="text-slate-400">{{ d.number }}</span></td>
                            <td class="px-3 py-2"><span :class="d.is_won ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'" class="rounded-full px-2 py-0.5 text-[11px] font-medium">{{ d.stage }}</span></td>
                            <td class="px-3 py-2 text-right tabular-nums text-slate-700">{{ money(d.budget) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums" :class="d.paid >= d.budget ? 'text-emerald-600' : 'text-slate-500'">{{ money(d.paid) }}</td>
                            <td class="px-3 py-2 text-right font-semibold tabular-nums text-emerald-600">
                                {{ money(d.bonus) }}
                                <span v-if="d.bonus_manual" class="ml-1 rounded bg-amber-100 px-1 py-px text-[9px] font-bold uppercase text-amber-700" :title="$e('Ручной % финансиста: ') + d.bonus_rate + '%'">{{ d.bonus_rate }}%</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            </div>

            <!-- Правая колонка: система бонусов — только отдел продаж/финансист/админ -->
            <div v-if="seesBonusScale" class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm lg:sticky lg:top-4">
                <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $e('Система бонусов — по марже сделки') }}</div>
                <div class="mt-3 space-y-1.5 text-sm">
                    <div v-for="t in BONUS_TIERS" :key="t.m" class="flex items-center justify-between rounded-lg px-3 py-1.5"
                        :class="t.muted ? 'bg-slate-50 text-slate-400' : 'bg-emerald-50/50'">
                        <span :class="t.muted ? '' : 'text-slate-600'">{{ $e('маржа') }} {{ t.m }}</span>
                        <span class="font-semibold tabular-nums" :class="t.muted ? '' : 'text-emerald-700'">{{ t.b }}</span>
                    </div>
                </div>
                <p class="mt-3 text-[11px] text-slate-400">{{ $e('Маржа = (сумма договора − расходы) / сумма договора. Остаток = сумма − налог − расходы.') }}</p>
            </div>
        </div>

        <!-- Leadership: everyone -->
        <template v-else>
            <!-- Норма часов месяца — на виду у финансиста: ставка/час = оклад ÷ норма -->
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-indigo-100 bg-white px-5 py-4 shadow-sm">
                <div class="flex items-center gap-3.5">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-indigo-50 text-indigo-600">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                    </span>
                    <div>
                        <div class="text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ $e('Норма часов ·') }} {{ monthLabel }}</div>
                        <div class="mt-0.5 flex items-baseline gap-2">
                            <template v-if="editingNorm">
                                <input v-model="normVal" type="number" min="1" max="744" class="w-24 rounded-lg border-indigo-300 py-1 text-lg font-bold tabular-nums text-slate-900 focus:border-indigo-500 focus:ring-indigo-500"
                                    @keydown.enter="saveNorm" @keydown.escape="editingNorm = false" />
                                <button class="rounded-lg bg-indigo-600 px-2.5 py-1.5 text-xs font-bold text-white transition hover:bg-indigo-700" @click="saveNorm">✓</button>
                                <button class="rounded-lg px-2 py-1.5 text-xs font-medium text-slate-400 hover:text-slate-600" @click="editingNorm = false">{{ $e('отмена') }}</button>
                            </template>
                            <button v-else-if="canManage" class="group flex items-baseline gap-1.5" :title="$e('Изменить норму часов месяца')" @click="editNorm">
                                <span class="text-2xl font-bold tabular-nums leading-none text-slate-900">{{ normHours }}</span>
                                <span class="text-sm text-slate-400">{{ $e('ч / мес') }}</span>
                                <svg class="h-3.5 w-3.5 self-center text-slate-300 transition-colors group-hover:text-indigo-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>
                            </button>
                            <span v-else class="text-2xl font-bold tabular-nums leading-none text-slate-900">{{ normHours }} <span class="text-sm font-normal text-slate-400">{{ $e('ч / мес') }}</span></span>
                        </div>
                    </div>
                </div>
                <div class="text-right text-[11px] leading-relaxed text-slate-400">
                    <div>{{ $e('ставка за час = оклад ÷') }} <span class="font-semibold text-slate-600">{{ normHours }} {{ $e('ч') }}</span> {{ $e('· начислено = часы × ставка') }}</div>
                    <div>{{ $e('часы не введены — полный оклад · у отдела своя норма — в заголовке его секции') }}</div>
                </div>
            </div>

            <!-- Ведомость — только про ЗП: 4 плитки. Деньги сделок — на Финансах и в Сводном
                 отчёте; здесь по сотруднику они видны при раскрытии строки. -->
            <div class="mb-6 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-5">
                <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <div class="truncate text-[11px] uppercase tracking-wide text-slate-400">{{ $e('Оклады (начислено)') }}</div>
                    <div class="mt-1 whitespace-nowrap text-lg font-semibold tabular-nums text-slate-800 xl:text-xl">{{ money(totals.base) }}</div>
                    <div v-if="totals.base !== totals.salary" class="truncate text-[10px] text-slate-400">{{ $e('по карточкам') }} {{ money(totals.salary) }}</div>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <div class="truncate text-[11px] uppercase tracking-wide text-slate-400">{{ $e('Бонусы (по марже)') }}</div>
                    <div class="mt-1 whitespace-nowrap text-lg font-semibold tabular-nums text-emerald-600 xl:text-xl">{{ money(totals.bonus) }}</div>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <div class="truncate text-[11px] uppercase tracking-wide text-slate-400">{{ $e('Бонус за') }} {{ monthLabel }}</div>
                    <div class="mt-1 whitespace-nowrap text-lg font-semibold tabular-nums text-emerald-600 xl:text-xl">{{ money(totals.bonus_month) }}</div>
                    <div class="truncate text-[10px] text-slate-400">{{ $e('справочно — в «К выплате» не входит') }}</div>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <div class="truncate text-[11px] uppercase tracking-wide text-slate-400">{{ $e('Удержания / премии') }}</div>
                    <div class="mt-1 whitespace-nowrap text-lg font-semibold tabular-nums xl:text-xl" :class="totals.deductions > 0 ? 'text-rose-600' : 'text-slate-300'">
                        <template v-if="totals.deductions > 0">−{{ money(totals.deductions) }}</template>
                        <template v-else>—</template>
                        <span v-if="totals.additions > 0" class="text-sm text-emerald-600"> +{{ money(totals.additions) }}</span>
                    </div>
                </div>
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 shadow-sm">
                    <div class="truncate text-[11px] uppercase tracking-wide text-emerald-600/70">{{ $e('К выплате ·') }} {{ monthLabel }}</div>
                    <div class="mt-1 whitespace-nowrap text-lg font-semibold tabular-nums text-emerald-700 xl:text-xl">{{ money(totals.final) }}</div>
                </div>
            </div>

            <div class="mb-3 flex flex-wrap items-center gap-2">
                <input :value="searchInput" @input="onSearch($event.target.value)" type="search" :placeholder="$e('Поиск по сотруднику…')"
                    class="w-56 rounded-lg border-slate-300 py-1.5 text-sm shadow-sm transition focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20" />
                <button v-for="f in FILTERS" :key="f.key" type="button" @click="toggleFilter(f.key)"
                    class="rounded-full border px-3 py-1 text-xs font-medium transition-colors duration-150"
                    :class="activeFilters.has(f.key) ? 'border-indigo-500 bg-indigo-50 text-indigo-700' : 'border-slate-200 bg-white text-slate-500 hover:bg-slate-50'">{{ f.label }}</button>
                <span v-if="isFiltering" class="text-xs text-slate-400">{{ $e('найдено') }} {{ filtered.length }} {{ $e('· суммы — по найденным') }}</span>
            </div>

            <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm">
                <table class="min-w-full divide-y divide-slate-100 text-sm">
                    <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-400">
                        <tr>
                            <th class="px-4 py-3">{{ $e('Сотрудник') }}</th>
                            <th class="px-4 py-3 text-right" :title="$e('Отработанные часы за месяц. Пусто — полный оклад.')">{{ $e('Часы') }}</th>
                            <th class="px-4 py-3 text-right">{{ $e('Оклад (начислено)') }}</th>
                            <th class="px-4 py-3 text-right">{{ $e('Бонус') }}</th>
                            <th class="px-4 py-3 text-right">{{ $e('Удержания / премии') }}</th>
                            <th class="px-4 py-3 text-right">{{ $e('К выплате') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        <template v-for="g in groups" :key="g.name">
                        <!-- Секция отдела: название, число сотрудников, Σ к выплате; клик — свернуть/развернуть -->
                        <tr class="cursor-pointer select-none bg-slate-100/80 hover:bg-slate-200/70" @click="toggleDept(g.name)">
                            <td colspan="6" class="px-4 py-2">
                                <div class="flex items-center justify-between gap-2">
                                    <span class="flex items-center gap-1.5 text-xs font-bold uppercase tracking-wide text-slate-600">
                                        <svg class="h-3.5 w-3.5 shrink-0 text-slate-400 transition-transform" :class="collapsed.has(g.name) ? '' : 'rotate-90'" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 5l5 5-5 5"/></svg>
                                        ⌂ {{ g.name }}
                                        <span class="font-medium normal-case tracking-normal text-slate-400">{{ g.list.length }} {{ $e('сотр.') }}</span>
                                        <!-- Своя норма часов отдела; пусто при правке — сброс на общую -->
                                        <span v-if="g.id != null" class="normal-case tracking-normal" @click.stop>
                                            <span v-if="editingDeptNorm === g.name" class="flex items-center gap-1">
                                                <input v-model="deptNormVal" type="number" min="1" max="744" :placeholder="normHours"
                                                    class="w-16 rounded-md border-indigo-300 py-0.5 text-right text-xs font-semibold tabular-nums"
                                                    @keydown.enter="saveDeptNorm(g)" @keydown.escape="editingDeptNorm = null" />
                                                <button class="rounded bg-indigo-600 px-1.5 py-0.5 text-[10px] font-bold text-white" @click="saveDeptNorm(g)">✓</button>
                                            </span>
                                            <button v-else-if="canManage" class="group/norm inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-semibold tabular-nums transition-colors"
                                                :class="g.override ? 'bg-indigo-100 text-indigo-700 hover:bg-indigo-200' : 'bg-slate-200/70 text-slate-500 hover:bg-slate-300/70'"
                                                :title="g.override ? $e('Своя норма отдела (пусто — сброс на общую ') + normHours + $e(' ч)') : $e('Общая норма ') + normHours + $e(' ч — нажмите, чтобы задать свою для отдела')"
                                                @click="editDeptNorm(g)">
                                                {{ $e('норма') }} {{ g.norm }} {{ $e('ч') }}
                                                <svg class="h-2.5 w-2.5 opacity-40 group-hover/norm:opacity-100" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>
                                            </button>
                                            <span v-else class="rounded-full px-2 py-0.5 text-[11px] font-semibold tabular-nums" :class="g.override ? 'bg-indigo-100 text-indigo-700' : 'bg-slate-200/70 text-slate-500'">{{ $e('норма') }} {{ g.norm }} {{ $e('ч') }}</span>
                                        </span>
                                    </span>
                                    <span class="text-xs font-semibold tabular-nums text-emerald-700">{{ $e('к выплате') }} {{ money(g.final) }}</span>
                                </div>
                            </td>
                        </tr>
                        <template v-if="!collapsed.has(g.name)">
                        <template v-for="r in g.list" :key="r.uid">
                            <tr class="cursor-pointer hover:bg-slate-50" @click="toggle(r.uid)">
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-2.5">
                                        <svg class="h-4 w-4 shrink-0 text-slate-400 transition-transform" :class="open.has(r.uid) ? 'rotate-90' : ''" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M8 5l5 5-5 5"/></svg>
                                        <Avatar :name="r.user" :src="r.avatar" :size="32" />
                                        <div class="min-w-0 leading-tight">
                                            <div class="truncate font-medium text-slate-900">{{ r.user }}</div>
                                            <div v-if="r.deals > 0" class="text-[11px] text-slate-400">{{ r.deals }} {{ $e('сделок ·') }} {{ r.closed }} {{ $e('успешных') }}</div>
                                        </div>
                                    </div>
                                </td>
                                <!-- Часы за месяц: инлайн-правка бухгалтером/админом; пусто — полный оклад -->
                                <td class="px-4 py-3 text-right tabular-nums" @click.stop>
                                    <div v-if="editingHours === r.uid" class="flex items-center justify-end gap-1">
                                        <input v-model="hoursVal" type="number" min="0" step="0.5" class="w-20 rounded-md border-slate-300 py-1 text-right text-xs"
                                            @keydown.enter="saveHours(r)" @keydown.escape="editingHours = null" />
                                        <button class="rounded bg-emerald-600 px-1.5 py-1 text-[10px] font-bold text-white" @click="saveHours(r)">✓</button>
                                    </div>
                                    <button v-else-if="canManage" class="group inline-flex items-center gap-1 hover:text-indigo-600"
                                        :title="$e('Отработанные часы за ') + monthLabel + $e(' (пусто — полный оклад). Ставка: ') + money(r.hourly_rate ?? 0) + $e('/ч')" @click="editHours(r)">
                                        <span :class="r.hours != null ? 'font-medium text-slate-700' : 'text-slate-300'">{{ r.hours != null ? r.hours + $e(' ч') : '—' }}</span>
                                        <svg class="h-3 w-3 text-slate-300 group-hover:text-indigo-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>
                                    </button>
                                    <span v-else :class="r.hours != null ? 'font-medium text-slate-700' : 'text-slate-300'">{{ r.hours != null ? r.hours + $e(' ч') : '—' }}</span>
                                </td>
                                <!-- Оклад: крупно — начислено; подписью — оклад по карточке и формула часов -->
                                <td class="px-4 py-3 text-right tabular-nums" @click.stop>
                                    <div v-if="editingSalary === r.uid" class="flex items-center justify-end gap-1">
                                        <input v-model="salaryVal" type="number" min="0" class="w-28 rounded-md border-slate-300 py-1 text-right text-xs"
                                            @keydown.enter="saveSalary(r)" @keydown.escape="editingSalary = null" />
                                        <button class="rounded bg-emerald-600 px-1.5 py-1 text-[10px] font-bold text-white" @click="saveSalary(r)">✓</button>
                                    </div>
                                    <template v-else>
                                        <button v-if="canManage" class="group inline-flex items-center gap-1 hover:text-indigo-600" :title="$e('Изменить оклад')" @click="editSalary(r)">
                                            <span :class="r.base > 0 ? 'font-medium text-slate-800' : 'text-slate-300'">{{ r.base > 0 ? money(r.base) : '—' }}</span>
                                            <svg class="h-3 w-3 text-slate-300 group-hover:text-indigo-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>
                                        </button>
                                        <span v-else :class="r.base > 0 ? 'font-medium text-slate-800' : 'text-slate-300'">{{ r.base > 0 ? money(r.base) : '—' }}</span>
                                        <div v-if="r.hours != null" class="text-[10px] text-slate-400">{{ $e('оклад') }} {{ money(r.salary) }} · {{ r.hours }} {{ $e('ч ×') }} {{ money(r.hourly_rate ?? 0) }}</div>
                                    </template>
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums" :class="r.bonus > 0 ? 'font-medium text-emerald-600' : 'text-slate-300'">
                                    {{ r.bonus > 0 ? money(r.bonus) : '—' }}
                                    <div v-if="r.bonus_month > 0" class="text-[10px] text-slate-400">{{ $e('за месяц') }} {{ money(r.bonus_month) }}</div>
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums" :class="r.deductions > 0 ? 'text-rose-600 font-medium' : 'text-slate-300'">
                                    <template v-if="r.deductions > 0">− {{ money(r.deductions) }}</template>
                                    <template v-else>—</template>
                                    <span v-if="r.additions > 0" class="text-emerald-600"> +{{ money(r.additions) }}</span>
                                    <div v-if="r.debt_charge > 0" class="text-[10px] font-medium text-rose-500">{{ $e('долг') }} − {{ money(r.debt_charge) }}</div>
                                </td>
                                <td class="px-4 py-3 text-right font-bold tabular-nums" :class="r.final > 0 ? 'text-emerald-600' : 'text-slate-300'">{{ r.final > 0 ? money(r.final) : '—' }}</td>
                            </tr>
                            <tr v-if="open.has(r.uid)" class="bg-slate-50/60">
                                <td colspan="6" class="px-4 py-3">
                                    <!-- Финансы сделок сотрудника (из колонок убраны — здесь по требованию) -->
                                    <div v-if="r.budget > 0" class="mb-3 flex flex-wrap gap-2 text-[11px]">
                                        <span class="rounded-full bg-white px-2.5 py-1 text-slate-500 ring-1 ring-slate-200">{{ $e('Сумма договоров') }} <span class="font-semibold tabular-nums text-slate-700">{{ money(r.budget) }}</span></span>
                                        <span class="rounded-full bg-white px-2.5 py-1 text-slate-500 ring-1 ring-slate-200">{{ $e('Налог') }} {{ taxRate }}% <span class="font-semibold tabular-nums text-rose-600">− {{ money(r.tax) }}</span></span>
                                        <span class="rounded-full bg-white px-2.5 py-1 text-slate-500 ring-1 ring-slate-200">{{ $e('Расходы') }} <span class="font-semibold tabular-nums text-rose-600">− {{ money(r.expense) }}</span></span>
                                        <span class="rounded-full bg-white px-2.5 py-1 text-slate-500 ring-1 ring-slate-200">{{ $e('Остаток') }} <span class="font-semibold tabular-nums text-slate-700">{{ money(r.remainder) }}</span></span>
                                        <span class="rounded-full bg-white px-2.5 py-1 text-slate-500 ring-1 ring-slate-200">{{ $e('Чистая прибыль') }} <span class="font-semibold tabular-nums" :class="r.company >= 0 ? 'text-slate-900' : 'text-rose-600'">{{ money(r.company) }}</span></span>
                                    </div>
                                    <!-- Корректировки сотрудника за месяц -->
                                    <div class="mb-3 rounded-lg border border-slate-200 bg-white p-3">
                                        <div class="mb-1 flex items-center justify-between">
                                            <span class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{{ $e('Корректировки ·') }} {{ monthLabel }}</span>
                                            <button v-if="canManage" class="text-xs font-medium text-indigo-600 hover:text-indigo-700" @click="openAdj(r.uid)">{{ $e('+ добавить') }}</button>
                                        </div>
                                        <div v-if="r.adjustments?.length" class="divide-y divide-slate-50 text-xs">
                                            <div v-for="a in r.adjustments" :key="a.id" class="flex items-center justify-between gap-2 py-1.5">
                                                <div class="flex items-center gap-2">
                                                    <span class="rounded-full px-2 py-0.5 text-[10px] font-semibold" :class="typeClass(a.type)">{{ typeLabels[a.type] }}</span>
                                                    <span class="text-slate-400">{{ formatDate(a.date) }}<template v-if="a.days"> · {{ a.days }} {{ $e('дн.') }}</template><template v-if="a.note"> · {{ a.note }}</template><template v-if="a.creator"> · {{ a.creator }}</template> {{ $e('· внесено') }} {{ formatDateTime(a.created_at) }}</span>
                                                </div>
                                                <div class="flex items-center gap-2">
                                                    <span class="font-semibold tabular-nums" :class="a.type === 'bonus' ? 'text-emerald-600' : 'text-rose-600'">{{ a.type === 'bonus' ? '+' : '−' }} {{ money(a.amount) }}</span>
                                                    <button v-if="canManage" class="text-slate-300 hover:text-rose-600" :title="$e('Удалить')" @click="delAdj(a)">✕</button>
                                                </div>
                                            </div>
                                        </div>
                                        <div v-else class="py-1 text-xs text-slate-300">{{ $e('Нет корректировок') }}</div>
                                    </div>
                                    <!-- Долги сотрудника: остаток, план удержания этого месяца -->
                                    <div class="mb-3 rounded-lg border border-slate-200 bg-white p-3">
                                        <div class="mb-1 flex items-center justify-between">
                                            <span class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{{ $e('Долг') }}</span>
                                            <button v-if="canManage" class="text-xs font-medium text-indigo-600 hover:text-indigo-700" @click="openDebt(r.uid)">{{ $e('+ выдать долг') }}</button>
                                        </div>
                                        <template v-if="r.debt && r.debt.items.length">
                                            <p class="mb-1.5 text-xs text-slate-600">
                                                {{ $e('Остаток') }} <b class="tabular-nums">{{ money(r.debt.balance) }}</b>
                                                <template v-if="r.debt.charge > 0">
                                                    · {{ $e('удержим') }} <b class="tabular-nums text-rose-600">− {{ money(r.debt.charge) }}</b>
                                                    {{ $e('в этом месяце, останется') }} <b class="tabular-nums">{{ money(r.debt.balance - r.debt.charge) }}</b>
                                                </template>
                                                <template v-else>· {{ $e('в этом месяце удержания нет: бонуса не хватает') }}</template>
                                            </p>
                                            <div class="divide-y divide-slate-50 text-xs">
                                                <div v-for="d in r.debt.items" :key="d.id" class="flex items-center justify-between gap-2 py-1.5">
                                                    <div class="flex items-center gap-2">
                                                        <span class="rounded-full bg-rose-100 px-2 py-0.5 text-[10px] font-semibold text-rose-700">{{ $e('Долг') }}</span>
                                                        <span class="text-slate-400">{{ formatDate(d.date) }} · {{ $e('по') }} {{ money(d.monthly) }} {{ $e('в месяц') }}<template v-if="d.note"> · {{ d.note }}</template></span>
                                                    </div>
                                                    <div class="flex items-center gap-2">
                                                        <span class="font-semibold tabular-nums text-slate-700">{{ money(d.balance) }} {{ $e('из') }} {{ money(d.amount) }}</span>
                                                        <button v-if="canManage && !d.has_payments" class="text-slate-300 hover:text-rose-600" :title="$e('Отменить выдачу')" @click="cancelDebt(d)">✕</button>
                                                    </div>
                                                </div>
                                            </div>
                                        </template>
                                        <div v-else class="py-1 text-xs text-slate-300">{{ $e('Долгов нет') }}</div>
                                    </div>
                                    <div v-if="r.dealsList && r.dealsList.length" class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
                                        <table class="min-w-full divide-y divide-slate-100 text-xs">
                                            <thead class="text-left uppercase tracking-wide text-slate-400">
                                                <tr>
                                                    <th class="px-3 py-2">{{ $e('Сделка') }}</th>
                                                    <th class="px-3 py-2">{{ $e('Этап') }}</th>
                                                    <th class="px-3 py-2 text-right">{{ $e('Сумма') }}</th>
                                                    <th class="px-3 py-2 text-right">{{ $e('Оплачено') }}</th>
                                                    <th class="px-3 py-2 text-right text-rose-600">{{ $e('Расходы') }}</th>
                                                    <th class="px-3 py-2 text-right text-rose-600">{{ $e('Налог') }}</th>
                                                    <th class="px-3 py-2 text-right text-emerald-600">{{ $e('Бонус (ЗП)') }}</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-slate-50">
                                                <tr v-for="d in r.dealsList" :key="d.id" class="hover:bg-slate-50">
                                                    <td class="px-3 py-2">
                                                        <Link :href="route('deals.show', d.id)" class="font-medium text-indigo-600 hover:underline">{{ d.company }}</Link>
                                                        <span class="ml-1 text-slate-400">{{ d.number }}</span>
                                                    </td>
                                                    <td class="px-3 py-2">
                                                        <span :class="d.is_won ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'" class="rounded-full px-2 py-0.5 text-[11px] font-medium">{{ d.stage }}</span>
                                                    </td>
                                                    <td class="px-3 py-2 text-right tabular-nums text-slate-700">{{ money(d.budget) }}</td>
                                                    <td class="px-3 py-2 text-right tabular-nums" :class="d.paid >= d.budget ? 'text-emerald-600' : 'text-slate-500'">{{ money(d.paid) }}</td>
                                                    <td class="px-3 py-2 text-right tabular-nums text-rose-600">{{ money(d.expense) }}</td>
                                                    <td class="px-3 py-2 text-right tabular-nums text-rose-600">{{ money(d.tax) }}</td>
                                                    <td class="px-3 py-2 text-right font-semibold tabular-nums text-emerald-600">
                                                        {{ money(d.bonus) }}
                                                        <span v-if="d.bonus_manual" class="ml-1 rounded bg-amber-100 px-1 py-px text-[9px] font-bold uppercase text-amber-700" :title="$e('Ручной % финансиста: ') + d.bonus_rate + '%'">{{ d.bonus_rate }}%</span>
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                        <p class="px-3 py-2 text-[11px] text-slate-400">{{ $e('🟢 «Оплата успешно» — в ЗП; 🟡 «Акт утверждение» — ожидает оплаты, ещё не в ЗП.') }}</p>
                                    </div>
                                    <div v-else class="py-2 text-center text-xs text-slate-400">{{ $e('Нет сделок на «Оплата успешно» / «Акт утверждение»') }}</div>
                                </td>
                            </tr>
                        </template>
                        </template>
                        </template>
                        <tr v-if="!filtered.length"><td colspan="6" class="px-4 py-8 text-center text-slate-400">{{ isFiltering ? $e('Никого не нашли — измените поиск или отборы.') : $e('Нет данных') }}</td></tr>
                    </tbody>
                </table>
            </div>
            <!-- Шкала бонусов — только отдел продаж/финансист/админ -->
            <p v-if="seesBonusScale" class="mt-3 text-xs text-slate-400">{{ $e('К выплате = оклад + бонус − удержания (отгул/больничный/штраф/аванс) + премии за выбранный месяц. Почасовой оклад: если сотруднику введены отработанные часы за месяц, оклад начисляется как часы × ставка за час (ставка = оклад ÷ норма часов месяца, норма — в шапке страницы); часы не введены — полный оклад. Отгул/больничный днями: удержание = оклад / 22 × дни. Остаток = сумма договора − налог') }} {{ taxRate }}{{ $e('% − расходы. Бонус по марже сделки (остаток/сумма), выплачивается пропорционально оплаченной доле (оплачено/сумма): до 10% — нет; 11–15% — 5%; 16–20% — 7%; 21–30% — 10%; 31–40% — 13%; от 41% — 15% от остатка. Чистая прибыль компании = остаток − бонус.') }}</p>
        </template>

        <!-- Модалка корректировки -->
        <Modal :show="showAdj" @close="showAdj = false" max-width="lg">
            <div class="p-6">
                <h2 class="mb-4 text-lg font-semibold text-slate-900">{{ $e('Корректировка ЗП') }}</h2>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-xs font-medium text-slate-500">{{ $e('Сотрудник *') }}</label>
                        <select v-model="adjForm.user_id" class="w-full rounded-md border-slate-300 text-sm shadow-sm">
                            <option value="">{{ $e('— выберите —') }}</option>
                            <optgroup v-for="g in groups" :key="g.name" :label="g.name">
                                <option v-for="r in g.list" :key="r.uid" :value="r.uid">{{ r.user }}</option>
                            </optgroup>
                        </select>
                        <div v-if="adjForm.errors.user_id" class="mt-1 text-xs text-red-600">{{ adjForm.errors.user_id }}</div>
                    </div>
                    <div class="sm:col-span-2 flex flex-wrap gap-2">
                        <button v-for="(label, t) in typeLabels" :key="t" type="button" @click="adjForm.type = t"
                            class="rounded-lg border px-3 py-1.5 text-xs font-semibold transition-all"
                            :class="adjForm.type === t ? 'border-indigo-500 bg-indigo-50 text-indigo-700 ring-1 ring-indigo-500' : 'border-slate-200 text-slate-500 hover:border-slate-300'">{{ label }}</button>
                    </div>
                    <div v-if="adjForm.type === 'absence' || adjForm.type === 'sick'">
                        <label class="mb-1 block text-xs font-medium text-slate-500">{{ $e('Дней (сумма = оклад / 22 × дни)') }}</label>
                        <input v-model="adjForm.days" type="number" min="0.5" step="0.5" class="w-full rounded-md border-slate-300 text-sm shadow-sm" />
                        <div v-if="adjForm.errors.days" class="mt-1 text-xs text-red-600">{{ adjForm.errors.days }}</div>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">{{ $e('Сумма, ₸') }} {{ adjForm.type === 'absence' || adjForm.type === 'sick' ? $e('(или авто по дням)') : '*' }}</label>
                        <input v-model="adjForm.amount" type="number" min="0" step="0.01" class="w-full rounded-md border-slate-300 text-sm shadow-sm" />
                        <div v-if="adjForm.errors.amount" class="mt-1 text-xs text-red-600">{{ adjForm.errors.amount }}</div>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">{{ $e('Дата *') }}</label>
                        <input v-model="adjForm.date" type="date" class="w-full rounded-md border-slate-300 text-sm shadow-sm" />
                        <div v-if="adjForm.errors.date" class="mt-1 text-xs text-red-600">{{ adjForm.errors.date }}</div>
                    </div>
                    <!-- Аванс — реальные деньги: откуда выданы (уйдёт в Расходы на Финансах) -->
                    <div v-if="adjForm.type === 'advance'">
                        <label class="mb-1 block text-xs font-medium text-slate-500">{{ $e('Откуда выданы деньги *') }}</label>
                        <div class="flex gap-2">
                            <button type="button" @click="adjForm.payment_method = 'cash'"
                                :class="adjForm.payment_method === 'cash' ? 'border-indigo-500 bg-indigo-50 text-indigo-700 ring-1 ring-indigo-500' : 'border-slate-200 text-slate-500 hover:border-slate-300'"
                                class="rounded-lg border px-3 py-1.5 text-sm font-medium">{{ $e('💵 Наличные') }}</button>
                            <button type="button" @click="adjForm.payment_method = 'bank'"
                                :class="adjForm.payment_method === 'bank' ? 'border-indigo-500 bg-indigo-50 text-indigo-700 ring-1 ring-indigo-500' : 'border-slate-200 text-slate-500 hover:border-slate-300'"
                                class="rounded-lg border px-3 py-1.5 text-sm font-medium">{{ $e('🏦 Банк') }}</button>
                        </div>
                        <p class="mt-1 text-[11px] text-slate-400">{{ $e('Аванс автоматически попадёт в Расходы на Финансах (категория «Расходы по сотрудникам»)') }}</p>
                        <p class="mt-1 text-[11px] text-slate-400">{{ $e('Аванс — разовая выдача, удерживается целиком в этом месяце из всей выплаты. Переходящую выдачу оформляют долгом («+ выдать долг»).') }}</p>
                    </div>
                    <div :class="adjForm.type === 'absence' || adjForm.type === 'sick' || adjForm.type === 'advance' ? '' : 'sm:col-span-2'">
                        <label class="mb-1 block text-xs font-medium text-slate-500">{{ $e('Комментарий') }}</label>
                        <input v-model="adjForm.note" type="text" class="w-full rounded-md border-slate-300 text-sm shadow-sm" :placeholder="$e('Причина…')" />
                    </div>
                </div>
                <div class="mt-6 flex justify-end gap-2">
                    <SecondaryButton @click="showAdj = false">{{ $e('Отмена') }}</SecondaryButton>
                    <PrimaryButton :disabled="adjForm.processing || !adjForm.user_id" @click="submitAdj">{{ $e('Сохранить') }}</PrimaryButton>
                </div>
            </div>
        </Modal>

        <!-- Модалка выдачи долга -->
        <Modal :show="showDebt" @close="showDebt = false" max-width="lg">
            <div class="p-6">
                <h2 class="mb-1 text-lg font-semibold text-slate-900">{{ $e('Выдать долг') }}</h2>
                <p class="mb-4 text-xs text-slate-400">{{ $e('Долг — переходящий: гасится помесячно и только из бонуса. Нет бонуса в месяце — удержания нет, остаток едет дальше. Оклад долг не трогает.') }}</p>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-xs font-medium text-slate-500">{{ $e('Сотрудник *') }}</label>
                        <select v-model="debtForm.user_id" class="w-full rounded-md border-slate-300 text-sm shadow-sm">
                            <option value="">{{ $e('— выберите —') }}</option>
                            <optgroup v-for="g in groups" :key="g.name" :label="g.name">
                                <option v-for="r in g.list" :key="r.uid" :value="r.uid">{{ r.user }}</option>
                            </optgroup>
                        </select>
                        <div v-if="debtForm.errors.user_id" class="mt-1 text-xs text-red-600">{{ debtForm.errors.user_id }}</div>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">{{ $e('Сумма долга, ₸ *') }}</label>
                        <input v-model="debtForm.amount" type="number" min="1" step="0.01" class="w-full rounded-md border-slate-300 text-sm shadow-sm" />
                        <div v-if="debtForm.errors.amount" class="mt-1 text-xs text-red-600">{{ debtForm.errors.amount }}</div>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">{{ $e('Удерживать в месяц, ₸ *') }}</label>
                        <input v-model="debtForm.monthly_payment" type="number" min="1" step="0.01" class="w-full rounded-md border-slate-300 text-sm shadow-sm" />
                        <div v-if="debtForm.errors.monthly_payment" class="mt-1 text-xs text-red-600">{{ debtForm.errors.monthly_payment }}</div>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-xs font-medium text-slate-500">{{ $e('Откуда выданы деньги *') }}</label>
                        <div class="flex gap-2">
                            <button type="button" @click="debtForm.payment_method = 'cash'"
                                :class="debtForm.payment_method === 'cash' ? 'border-indigo-500 bg-indigo-50 text-indigo-700 ring-1 ring-indigo-500' : 'border-slate-200 text-slate-500 hover:border-slate-300'"
                                class="rounded-lg border px-3 py-1.5 text-sm font-medium">{{ $e('💵 Наличные') }}</button>
                            <button type="button" @click="debtForm.payment_method = 'bank'"
                                :class="debtForm.payment_method === 'bank' ? 'border-indigo-500 bg-indigo-50 text-indigo-700 ring-1 ring-indigo-500' : 'border-slate-200 text-slate-500 hover:border-slate-300'"
                                class="rounded-lg border px-3 py-1.5 text-sm font-medium">{{ $e('🏦 Банк') }}</button>
                        </div>
                        <div v-if="debtForm.errors.payment_method" class="mt-1 text-xs text-red-600">{{ debtForm.errors.payment_method }}</div>
                        <p class="mt-1 text-[11px] text-slate-400">{{ $e('Выдача сразу уменьшает кассу или банк — это подтверждённый расход компании.') }}</p>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-xs font-medium text-slate-500">{{ $e('Заметка') }}</label>
                        <input v-model="debtForm.note" type="text" class="w-full rounded-md border-slate-300 text-sm shadow-sm" :placeholder="$e('За что…')" />
                        <div v-if="debtForm.errors.note" class="mt-1 text-xs text-red-600">{{ debtForm.errors.note }}</div>
                    </div>
                </div>
                <div class="mt-6 flex justify-end gap-2">
                    <SecondaryButton @click="showDebt = false">{{ $e('Отмена') }}</SecondaryButton>
                    <PrimaryButton :disabled="debtForm.processing || !debtForm.user_id" @click="submitDebt">{{ $e('Выдать') }}</PrimaryButton>
                </div>
            </div>
        </Modal>
    </AppLayout>
</template>
