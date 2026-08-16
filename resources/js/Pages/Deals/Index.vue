<script setup>
import { ref, computed } from 'vue';
import { Head, Link, useForm, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import Pagination from '@/Components/Pagination.vue';
import SearchSelect from '@/Components/SearchSelect.vue';
import ManagerPicker from '@/Components/ManagerPicker.vue';
import { deadlineClass } from '@/utils/deadline';
import { UNITS, SOURCES } from '@/utils/dealOptions';
import { formatDate, money } from '@/utils/format';
import { confirmDialog } from '@/composables/useConfirm';
import { useE } from '@/composables/useTranslations';

const tr = useE();

const props = defineProps({ deals: [Array, Object], stages: Array, view: String, filters: Object, users: Array, can: Object, isLeadership: Boolean, companies: { type: Array, default: () => [] }, currentCompanyId: Number, workshopsByCompany: { type: Object, default: () => ({}) }, branches: { type: Array, default: () => [] }, branchCounts: { type: Object, default: () => ({}) }, catalog: { type: Array, default: () => [] } });

const list = computed(() => Array.isArray(props.deals) ? props.deals : props.deals.data);
const byStage = (id) => list.value.filter((d) => d.deal_stage_id === id);
const stageTotal = (id) => byStage(id).reduce((s, d) => s + Number(d.budget), 0);
const lastStageId = computed(() => props.stages[props.stages.length - 1]?.id);
const firstStageId = computed(() => props.stages[0]?.id); // «Заключение договора»
// Спец-этапы определяет СИСТЕМНЫЙ ТИП, назначенный в админке (Настройки →
// Этапы), а не название: этап переименовывают и переставляют, и кнопка
// уезжала следом. Раньше «В цех» искалась по слову «закуп», а если такого
// этапа не было — вставала на «третий с конца», то есть на случайный этап.
// Название осталось запасным вариантом для воронок, где тип ещё не проставлен.
// В режиме «Все компании» воронок две, поэтому работаем с МАССИВАМИ id.
const matchIds = (needle) => props.stages.filter((s) => s.name?.toLowerCase().includes(needle)).map((s) => s.id);
const typeIds = (type) => props.stages.filter((s) => s.stage_type === type).map((s) => s.id);
const idsFor = (type, needle) => { const typed = typeIds(type); return typed.length ? typed : matchIds(needle); };
const workshopIds = computed(() => idsFor('shop_gate', tr('закуп')));
const actIds = computed(() => idsFor('act', tr('акт')));
const esfIds = computed(() => idsFor('esf', tr('эсф')));
const wonIds = computed(() => { const ids = props.stages.filter((s) => s.is_won).map((s) => s.id); return ids.length ? ids : [lastStageId.value].filter(Boolean); });
const preWonIds = computed(() => (esfIds.value.length ? esfIds.value : actIds.value));
// Этапы АКТ/ЭСФ/Оплата двигает только бухгалтер (financist) или админ.
const canAccounting = computed(() => (usePage().props.auth.user?.roles ?? []).some((r) => ['admin', 'financist'].includes(r)));
const postActIds = computed(() => [...actIds.value, ...esfIds.value, ...wonIds.value]);

const draggingId = ref(null);
const onDrop = async (stage) => {
    const id = draggingId.value; draggingId.value = null;
    if (!id) return;
    const deal = list.value.find((d) => d.id === id);
    if (!deal || deal.deal_stage_id === stage.id) return;
    // Не бухгалтер/админ: сделку на АКТ/ЭСФ/Оплате не двигает; на ЭСФ/Оплату не переводит.
    if (!canAccounting.value && postActIds.value.includes(deal.deal_stage_id)) return;
    if (!canAccounting.value && postActIds.value.includes(stage.id) && !actIds.value.includes(stage.id)) return;
    // «ЭСФ» — только после «Акта»; «Оплата» — только после «ЭСФ».
    if (esfIds.value.includes(stage.id) && !actIds.value.includes(deal.deal_stage_id)) return;
    if (wonIds.value.includes(stage.id) && !preWonIds.value.includes(deal.deal_stage_id)) return;
    // Leaving the «Оплата успешно» stage needs confirmation.
    if (wonIds.value.includes(deal.deal_stage_id)
        && ! (await confirmDialog({ title: tr('Сделка уже успешна'), message: tr('Сделка на этапе «Оплата успешно». Точно перевести её на другой этап?'), confirmText: tr('Перевести'), danger: true }))) return;
    router.patch(route('deals.stage', id), { deal_stage_id: stage.id }, { preserveScroll: true, preserveState: false });
};
const advance = (deal) => router.patch(route('deals.advance', deal.id), {}, { preserveScroll: true, preserveState: false });
// ⏱ Сколько сделка на текущем этапе (как тайминг у заказов цеха).
const stageTime = (deal) => {
    if (!deal.stage_entered_at) return null;
    const s = Math.max(0, (Date.now() - new Date(deal.stage_entered_at)) / 1000);
    const d = Math.floor(s / 86400), h = Math.floor((s % 86400) / 3600), m = Math.floor((s % 3600) / 60);
    if (d) return `${d}д ${h}ч`;
    if (h) return `${h}ч ${m}м`;
    return `${m}м`;
};
// Если цехов несколько — сначала модалка выбора; при одном — сразу.
const workshopPickDeal = ref(null);
const workshopOptions = computed(() => workshopPickDeal.value ? (props.workshopsByCompany[workshopPickDeal.value.company_id] ?? []) : []);
const toWorkshop = (deal, workshop = null) => {
    const options = props.workshopsByCompany[deal.company_id] ?? [];
    if (!workshop && options.length > 1) { workshopPickDeal.value = deal; return; }
    router.post(route('deals.toWorkshop', deal.id), { workshop: workshop ?? options[0] ?? null },
        { preserveScroll: true, preserveState: false, onSuccess: () => (workshopPickDeal.value = null) });
};
const switchView = (v) => router.get(route('deals.index'), { ...props.filters, view: v }, { preserveState: true });

// Серверные фильтры: поиск, менеджер, этап, срок с—по. Один набор параметров
// для всех контролов — состояние не «разъезжается» между поиском и фильтрами.
const search = ref(props.filters?.search ?? '');
const fResponsible = ref(props.filters?.responsible ?? '');
const fStage = ref(props.filters?.stage ?? '');
const fFrom = ref(props.filters?.date_from ?? '');
const fTo = ref(props.filters?.date_to ?? '');
const fContractFrom = ref(props.filters?.contract_from ?? '');
const fContractTo = ref(props.filters?.contract_to ?? '');

/*
 * Филиалы — вкладками над списком: у каждой площадки свои сделки.
 *
 * Значение уходит тем же фильтром, что и остальные, поэтому работает и в
 * канбане, и в списке, и вместе с поиском. «Без филиала» — не пустая
 * вкладка, а отдельный отбор: сделки, которым площадку ещё не назначили,
 * иначе они не попадали бы никуда и терялись.
 */
const NO_BRANCH = '__none';
const fBranch = ref(props.filters?.branch ?? '');
const branchTabs = computed(() => {
    const counts = props.branchCounts ?? {};
    const total = Object.values(counts).reduce((sum, n) => sum + n, 0);
    const tabs = [
        { key: '', label: tr('Все филиалы'), count: total },
        ...props.branches.map((b) => ({ key: b, label: b, count: counts[b] ?? 0 })),
    ];

    // Вкладку «Без филиала» показываем, только когда такие сделки есть:
    // на заполненной базе она была бы вечным пустым хвостом.
    if (counts[NO_BRANCH]) tabs.push({ key: NO_BRANCH, label: tr('Без филиала'), count: counts[NO_BRANCH] });

    return tabs;
});
const pickBranch = (key) => { fBranch.value = key; applyFilters(); };
const applyFilters = () => router.get(route('deals.index'), {
    view: props.view,
    search: search.value || undefined,
    responsible: fResponsible.value || undefined,
    stage: fStage.value || undefined,
    branch: fBranch.value || undefined,
    date_from: fFrom.value || undefined,
    date_to: fTo.value || undefined,
    contract_from: fContractFrom.value || undefined,
    contract_to: fContractTo.value || undefined,
}, { preserveState: true, preserveScroll: true, replace: true });
let searchTimer = null;
const onSearch = () => { clearTimeout(searchTimer); searchTimer = setTimeout(applyFilters, 350); };
const hasFilters = computed(() => search.value || fResponsible.value || fStage.value || fBranch.value || fFrom.value || fTo.value || fContractFrom.value || fContractTo.value);
// При фильтре по этапу канбан показывает ТОЛЬКО выбранную колонку —
// остальные этапы скрываются (а не пустеют).
const visibleStages = computed(() => fStage.value ? props.stages.filter((s) => String(s.id) === String(fStage.value)) : props.stages);
const resetFilters = () => { search.value = ''; fResponsible.value = ''; fStage.value = ''; fBranch.value = ''; fFrom.value = ''; fTo.value = ''; fContractFrom.value = ''; fContractTo.value = ''; applyFilters(); };

// Массовое удаление (вид «Список», только admin): чекбоксы + подтверждение.
const selected = ref(new Set());
const toggleSel = (id) => { const s = new Set(selected.value); s.has(id) ? s.delete(id) : s.add(id); selected.value = s; };
const allSelected = computed(() => list.value.length > 0 && list.value.every((d) => selected.value.has(d.id)));
const toggleAllSel = () => { selected.value = allSelected.value ? new Set() : new Set(list.value.map((d) => d.id)); };
const bulkDelete = async () => {
    const ids = [...selected.value];
    if (!ids.length) return;
    if (await confirmDialog({ title: tr('Удалить сделки'), message: `Будет удалено сделок: ${ids.length}. Это действие необратимо.`, confirmText: tr('Удалить'), danger: true })) {
        router.delete(route('deals.bulkDestroy'), { data: { ids }, preserveScroll: true, onSuccess: () => (selected.value = new Set()) });
    }
};

const showModal = ref(false);
const form = useForm({ company_id: props.currentCompanyId || props.companies[0]?.id || '', branch: '', company_name: '', address: '', bin: '', contract_date: '', client_name: '', product_id: '', lot_number: '', unit: '', area_m2: '', source: '', responsible_user_id: '', budget: 0, partner_pct: '', deadline: '', description: '', note: '' });

// Товар выбирается из каталога: подставляем название и единицу измерения,
// чтобы менеджер не вводил их руками и не расходился с прайсом.
const pickProduct = (id) => {
    const product = props.catalog.find((p) => p.id === Number(id));
    if (!product) return;
    form.client_name = product.name;
    if (product.unit) form.unit = product.unit;
};
const openCreate = () => { form.reset(); form.company_id = props.currentCompanyId || props.companies[0]?.id || ''; binMatch.value = null; showBinModal.value = false; showModal.value = true; };
const submit = () => form.post(route('deals.store'), { preserveScroll: true, onSuccess: () => (showModal.value = false) });

// БИН lookup: if the entered БИН already exists, offer to copy its company data.
const binMatch = ref(null);
const binHistory = ref([]);
const showBinModal = ref(false);
const showBinHistory = ref(false);
const checkBin = async () => {
    const bin = String(form.bin || '').trim();
    if (!bin) return;
    try {
        const res = await fetch(`${route('deals.binLookup')}?bin=${encodeURIComponent(bin)}`, { headers: { Accept: 'application/json' } });
        const data = await res.json();
        binHistory.value = data.history || [];
        showBinHistory.value = false;
        if (data.match) { binMatch.value = data.match; showBinModal.value = true; }
    } catch (e) { /* ignore lookup errors */ }
};
const applyBinMatch = () => {
    if (binMatch.value) {
        form.company_name = binMatch.value.company_name;
        form.bin = binMatch.value.bin;
        if (binMatch.value.address) form.address = binMatch.value.address;
    }
    showBinModal.value = false;
};
</script>

<template>
    <Head :title="$e('Сделки')" />
    <AppLayout>
        <template #header>{{ $t('page.deals', 'Сделки') }}</template>

        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div class="inline-flex rounded-xl bg-white shadow-sm border border-slate-200">
                <button :class="view === 'kanban' ? 'bg-indigo-600 text-white' : 'text-slate-600'" class="rounded-l-lg px-4 py-1.5 text-sm transition-colors" @click="switchView('kanban')">{{ $e('Канбан') }}</button>
                <button :class="view === 'list' ? 'bg-indigo-600 text-white' : 'text-slate-600'" class="rounded-r-lg px-4 py-1.5 text-sm transition-colors" @click="switchView('list')">{{ $e('Список') }}</button>
            </div>
            <button class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow transition-transform hover:scale-[1.02] hover:bg-indigo-700 active:scale-95" @click="openCreate">{{ $e('+ Новая сделка') }}</button>
        </div>

        <!-- Филиалы: у каждой площадки свои сделки -->
        <div v-if="branches.length" class="mb-4 flex flex-wrap items-center gap-1.5">
            <button
                v-for="tab in branchTabs"
                :key="tab.key || 'all'"
                type="button"
                class="inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-sm transition-colors"
                :class="String(fBranch) === String(tab.key)
                    ? 'border-indigo-600 bg-indigo-600 text-white shadow-sm'
                    : 'border-slate-200 bg-white text-slate-600 hover:border-indigo-300 hover:text-indigo-700'"
                @click="pickBranch(tab.key)"
            >
                <span v-if="tab.key && tab.key !== '__none'">🏭</span>
                {{ tab.label }}
                <span
                    class="rounded px-1.5 text-xs font-semibold"
                    :class="String(fBranch) === String(tab.key) ? 'bg-white/20' : 'bg-slate-100 text-slate-500'"
                >{{ tab.count }}</span>
            </button>
        </div>

        <!-- Единый фильтр-бар: поиск, менеджер (руководству), этап, срок с—по -->
        <div class="mb-4 flex flex-wrap items-center gap-2 rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
            <div class="relative w-full sm:w-60">
                <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
                <input v-model="search" @input="onSearch" type="text" :placeholder="$e('Поиск: компания, №, изделие, договор…')"
                    class="w-full rounded-lg border-slate-200 py-1.5 pl-9 pr-3 text-sm shadow-sm transition focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20" />
            </div>
            <!-- Менеджеры сверху, остальные — по отделам (свёрнуты) -->
            <ManagerPicker v-if="isLeadership" v-model="fResponsible" :users="users" width="w-full sm:w-48" @change="applyFilters" />
            <SearchSelect v-model="fStage" :options="stages" :placeholder="$e('Все этапы')" width="w-full sm:w-52" @change="applyFilters" />
            <label class="flex items-center gap-1 text-xs text-slate-400">{{ $e('срок с') }}
                <input v-model="fFrom" @change="applyFilters" type="date" class="rounded-lg border-slate-200 py-1.5 text-xs shadow-sm" />
            </label>
            <label class="flex items-center gap-1 text-xs text-slate-400">{{ $e('по') }}
                <input v-model="fTo" @change="applyFilters" type="date" class="rounded-lg border-slate-200 py-1.5 text-xs shadow-sm" />
            </label>
            <label class="flex items-center gap-1 text-xs text-slate-400">{{ $e('договор с') }}
                <input v-model="fContractFrom" @change="applyFilters" type="date" class="rounded-lg border-slate-200 py-1.5 text-xs shadow-sm" />
            </label>
            <label class="flex items-center gap-1 text-xs text-slate-400">{{ $e('по') }}
                <input v-model="fContractTo" @change="applyFilters" type="date" class="rounded-lg border-slate-200 py-1.5 text-xs shadow-sm" />
            </label>
            <button v-if="hasFilters" type="button" @click="resetFilters"
                class="rounded-lg px-2.5 py-1.5 text-xs font-medium text-slate-400 transition hover:bg-slate-100 hover:text-slate-600">{{ $e('Сбросить ✕') }}</button>
            <span class="ml-auto hidden text-[11px] tabular-nums text-slate-300 lg:block">{{ $e('найдено:') }} {{ Array.isArray(deals) ? list.length : deals.total ?? list.length }}</span>
        </div>

        <!-- KANBAN -->
        <div v-if="view === 'kanban'" class="flex gap-3 overflow-x-auto pb-4">
            <div v-for="stage in visibleStages" :key="stage.id" class="flex w-64 flex-shrink-0 flex-col rounded-xl bg-slate-100/80" :class="fStage ? 'w-80' : ''" @dragover.prevent @drop="onDrop(stage)">
                <div class="px-3 py-2">
                    <div class="flex items-center gap-2">
                        <span class="h-2 w-2 shrink-0 rounded-full" :style="{ backgroundColor: stage.color }"></span>
                        <span class="truncate text-sm font-semibold text-slate-700">{{ stage.name }}</span>
                        <span class="shrink-0 text-xs text-slate-400">{{ byStage(stage.id).length }}</span>
                    </div>
                    <div class="mt-0.5 pl-4 text-[11px] font-medium tabular-nums text-slate-400">{{ money(stageTotal(stage.id)) }}</div>
                </div>
                <div class="flex-1 space-y-2 px-2 pb-2">
                    <!-- Кнопка создания всегда СВЕРХУ колонки «Заключение договора» -->
                    <button v-if="stage.id === firstStageId && can.create" @click="openCreate"
                        class="flex w-full items-center justify-center gap-1 rounded-lg border border-dashed border-indigo-300 py-2 text-xs font-medium text-indigo-600 transition-colors hover:border-indigo-400 hover:bg-indigo-50">
                        {{ $e('+ Новая сделка') }}
                    </button>
                    <div v-for="deal in byStage(stage.id)" :key="deal.id" draggable="true" @dragstart="draggingId = deal.id"
                        class="cursor-move rounded-lg bg-white p-2.5 border border-slate-200 shadow-sm transition-all hover:-translate-y-0.5 hover:shadow-md hover:ring-indigo-200">
                        <Link :href="route('deals.show', deal.id)" class="block">
                            <!-- Кто и сколько -->
                            <div class="flex items-start justify-between gap-2">
                                <div class="truncate text-sm font-bold text-slate-900">{{ deal.company_name || deal.name }}</div>
                                <!-- Номер сделки виден ВСЕГДА, «Просрочена» — дополнительным бейджем -->
                                <span class="flex shrink-0 flex-col items-end gap-0.5">
                                    <span class="text-[10px] text-slate-300">{{ deal.number }}</span>
                                    <span v-if="deal.overdue_count" class="rounded-full bg-red-100 px-2 py-0.5 text-[10px] font-bold text-red-600">{{ $e('ПРОСРОЧЕНА') }}</span>
                                </span>
                            </div>
                            <div class="text-base font-bold leading-tight text-indigo-600">{{ money(deal.budget) }}</div>
                            <!-- Куда и что -->
                            <div class="mt-1.5 space-y-0.5 text-[11px] leading-4 text-slate-500">
                                <div v-if="deal.address" class="truncate">📍 {{ deal.address }}</div>
                                <div class="truncate">📦 {{ deal.client_name || '—' }}<template v-if="deal.lot_number"> · {{ deal.lot_number }} {{ deal.unit || '' }}</template><template v-if="deal.area_m2"> · {{ Number(deal.area_m2) }} {{ $e('м²') }}</template></div>
                                <div v-if="deal.branch" class="truncate text-[11px] text-slate-400">🏭 {{ deal.branch }}</div>
                            </div>
                            <!-- Когда и кто ведёт -->
                            <div class="mt-1.5 flex items-center justify-between gap-2">
                                <span class="flex min-w-0 items-center gap-1.5">
                                    <span class="flex h-5 w-5 shrink-0 items-center justify-center overflow-hidden rounded-full bg-indigo-500 text-[10px] font-bold text-white">
                                        <img v-if="deal.responsible?.avatar" :src="deal.responsible.avatar" class="h-full w-full object-cover" />
                                        <template v-else>{{ deal.responsible?.name?.charAt(0) ?? '—' }}</template>
                                    </span>
                                    <span class="truncate text-[11px] text-slate-600">{{ deal.responsible?.name ?? $e('не назначен') }}</span>
                                </span>
                                <span v-if="deal.deadline" class="shrink-0 text-[11px]" :class="deadlineClass(deal.deadline, deal.status==='closed') || 'text-slate-400'">⏰ {{ formatDate(deal.deadline) }}</span>
                            </div>
                        </Link>
                        <div class="mt-2 flex items-center justify-between border-t pt-1.5">
                            <Link :href="route('deals.show', deal.id)" class="text-[11px] text-slate-400 hover:text-indigo-600">{{ $e('+ Дело') }}</Link>
                            <span v-if="stageTime(deal)" :title="$e('Время на текущем этапе')" class="text-[10px] tabular-nums text-slate-400">⏱ {{ stageTime(deal) }}</span>
                            <button v-if="workshopIds.includes(deal.deal_stage_id)" @click="toWorkshop(deal)" class="rounded bg-emerald-600 px-2.5 py-1 text-[11px] font-semibold text-white transition-colors hover:bg-emerald-700">{{ $e('📦 В цех') }}</button>
                            <button v-else-if="!wonIds.includes(deal.deal_stage_id) && (canAccounting || !postActIds.includes(deal.deal_stage_id))" @click="advance(deal)" class="rounded bg-slate-100 px-2.5 py-1 text-[11px] text-slate-600 transition-colors hover:bg-indigo-100 hover:text-indigo-700">{{ $e('Далее →') }}</button>
                        </div>
                    </div>
                    <div v-if="!byStage(stage.id).length" class="py-5 text-center text-[11px] text-slate-400">{{ $e('Пусто') }}</div>
                </div>
            </div>
        </div>

        <!-- LIST -->
        <div v-else class="overflow-x-auto rounded-xl bg-white border border-slate-200 shadow-sm">
            <!-- Панель массовых действий (admin): появляется при выборе -->
            <div v-if="can.delete && selected.size" class="flex items-center justify-between gap-3 border-b border-rose-100 bg-rose-50/60 px-4 py-2.5">
                <span class="text-sm font-medium text-rose-700">{{ $e('Выбрано:') }} {{ selected.size }}</span>
                <div class="flex items-center gap-2">
                    <button type="button" @click="selected = new Set()" class="rounded-lg px-2.5 py-1.5 text-xs font-medium text-slate-500 transition hover:bg-white">{{ $e('Снять выбор') }}</button>
                    <button type="button" @click="bulkDelete"
                        class="rounded-lg bg-rose-600 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-rose-700">{{ $e('Удалить выбранные') }}</button>
                </div>
            </div>
            <table class="min-w-full whitespace-nowrap divide-y divide-slate-100 text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase text-slate-500">
                    <tr>
                        <th v-if="can.delete" class="w-10 px-4 py-3">
                            <input type="checkbox" :checked="allSelected" @change="toggleAllSel"
                                class="rounded border-slate-300 text-rose-600 focus:ring-rose-500" :title="$e('Выбрать все на странице')" />
                        </th>
                        <th class="px-4 py-3">{{ $e('Номер') }}</th><th class="px-4 py-3">{{ $e('Компания') }}</th><th class="px-4 py-3">{{ $e('Филиал') }}</th><th class="px-4 py-3">{{ $e('Товар') }}</th><th class="px-4 py-3">{{ $e('Этап') }}</th><th class="px-4 py-3">{{ $e('Сумма') }}</th><th class="px-4 py-3">{{ $e('Завершение') }}</th><th class="px-4 py-3">{{ $e('Ответственный') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="deal in deals.data" :key="deal.id" class="cursor-pointer transition-colors"
                        :class="selected.has(deal.id) ? 'bg-rose-50/50' : 'hover:bg-slate-50'"
                        @click="router.get(route('deals.show', deal.id))">
                        <td v-if="can.delete" class="px-4 py-3" @click.stop>
                            <input type="checkbox" :checked="selected.has(deal.id)" @change="toggleSel(deal.id)"
                                class="rounded border-slate-300 text-rose-600 focus:ring-rose-500" />
                        </td>
                        <td class="px-4 py-3 text-slate-400">{{ deal.number }}</td>
                        <td class="px-4 py-3">
                            <div class="line-clamp-2 max-w-md font-medium leading-snug text-slate-900" :title="deal.company_name || deal.name">{{ deal.company_name || deal.name }}</div>
                        </td>
                        <td class="px-4 py-3 text-slate-500">{{ deal.branch || '—' }}</td>
                        <td class="px-4 py-3"><div class="max-w-40 truncate text-slate-500" :title="deal.client_name || deal.client?.name">{{ deal.client_name || deal.client?.name || '—' }}</div></td>
                        <td class="px-4 py-3"><StatusBadge :status="deal.stage?.name" :color="deal.stage?.color" /></td>
                        <td class="px-4 py-3">{{ money(deal.budget) }}</td>
                        <td class="px-4 py-3" :class="deadlineClass(deal.deadline, deal.status==='closed')">{{ formatDate(deal.deadline) }}</td>
                        <td class="px-4 py-3 text-slate-500">{{ deal.responsible?.name ?? '—' }}</td>
                    </tr>
                </tbody>
            </table>
            <div class="p-4"><Pagination :links="deals.links" /></div>
        </div>

        <!-- CREATE MODAL -->
        <Modal :show="showModal" @close="showModal = false" max-width="2xl">
            <div class="p-6">
                <h2 class="mb-4 text-lg font-semibold">{{ $e('Новая сделка') }}</h2>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div v-if="companies.length" class="sm:col-span-2">
                        <InputLabel :value="$e('Компания (нумерация сделки)')" />
                        <div class="mt-1 flex gap-2">
                            <button v-for="c in companies" :key="c.id" type="button" @click="form.company_id = c.id"
                                class="rounded-lg border px-4 py-2 text-sm font-semibold transition-all"
                                :class="form.company_id === c.id ? 'border-emerald-500 bg-emerald-50 text-emerald-700 ring-1 ring-emerald-500' : 'border-slate-200 bg-white text-slate-500 hover:border-slate-300'">
                                {{ c.name }} <span class="font-normal text-slate-400">({{ c.code }}-…)</span>
                            </button>
                        </div>
                    </div>
                    <div><InputLabel :value="$e('Название компании *')" /><TextInput v-model="form.company_name" class="mt-1 w-full" /><InputError :message="form.errors.company_name" class="mt-1" /></div>
                    <div><InputLabel :value="$e('Номер договора')" /><TextInput v-model="form.bin" class="mt-1 w-full" @blur="checkBin" /><InputError :message="form.errors.bin" class="mt-1" /></div>
                    <div class="sm:col-span-2"><InputLabel :value="$e('Адрес *')" /><TextInput v-model="form.address" class="mt-1 w-full" :placeholder="$e('Город, улица, дом')" /><InputError :message="form.errors.address" class="mt-1" /></div>
                    <div><InputLabel :value="$e('Дата договора')" /><TextInput v-model="form.contract_date" type="date" class="mt-1 w-full" /><InputError :message="form.errors.contract_date" class="mt-1" /></div>
                    <div>
                        <InputLabel :value="$e('Филиал')" />
                        <select v-model="form.branch" class="mt-1 w-full rounded-md border-slate-300 shadow-sm">
                            <option value="">—</option>
                            <option v-for="b in branches" :key="b" :value="b">{{ b }}</option>
                        </select>
                        <InputError :message="form.errors.branch" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel :value="$e('Источник (портал)')" />
                        <select v-model="form.source" class="mt-1 w-full rounded-md border-slate-300 shadow-sm">
                            <option value="">—</option>
                            <option v-for="s in SOURCES" :key="s" :value="s">{{ s }}</option>
                        </select>
                        <InputError :message="form.errors.source" class="mt-1" />
                    </div>
                    <div class="sm:col-span-2">
                        <InputLabel :value="$e('Товар из каталога')" />
                        <select v-model="form.product_id" class="mt-1 w-full rounded-md border-slate-300 shadow-sm" @change="pickProduct(form.product_id)">
                            <option value="">{{ $e('— выбрать из каталога —') }}</option>
                            <option v-for="p in catalog" :key="p.id" :value="p.id">{{ p.name }}</option>
                        </select>
                        <p class="mt-1 text-[11px] text-slate-400">{{ $e('Название и единица подставятся сами; поле ниже можно поправить руками.') }}</p>
                    </div>
                    <div><InputLabel :value="$e('Наименование товара *')" /><TextInput v-model="form.client_name" class="mt-1 w-full" /><InputError :message="form.errors.client_name" class="mt-1" /></div>
                    <div>
                        <InputLabel :value="$e('Количество')" />
                        <div class="mt-1 flex gap-2">
                            <TextInput v-model="form.lot_number" type="number" min="0" step="any" class="w-1/2" />
                            <select v-model="form.unit" class="w-1/2 rounded-md border-slate-300 shadow-sm">
                                <option value="">{{ $e('ед. изм.') }}</option>
                                <option v-for="u in UNITS" :key="u" :value="u">{{ u }}</option>
                            </select>
                        </div>
                        <InputError :message="form.errors.unit || form.errors.lot_number" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel :value="$e('Площадь, м²')" />
                        <TextInput v-model="form.area_m2" type="number" min="0" step="any" class="mt-1 w-full" />
                        <InputError :message="form.errors.area_m2" class="mt-1" />
                    </div>
                    <div v-if="isLeadership">
                        <InputLabel :value="$e('Ответственный')" />
                        <select v-model="form.responsible_user_id" class="mt-1 w-full rounded-md border-slate-300 shadow-sm">
                            <option value="">—</option>
                            <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option>
                        </select>
                    </div>
                    <div><InputLabel :value="$e('Сумма договора *')" /><TextInput v-model="form.budget" type="number" step="0.01" class="mt-1 w-full" /><InputError :message="form.errors.budget" class="mt-1" /></div>
                    <div>
                        <InputLabel :value="$e('Доля партнёра, %')" /><TextInput v-model="form.partner_pct" type="number" min="0" max="100" step="0.01" class="mt-1 w-full" placeholder="0" />
                        <p v-if="Number(form.partner_pct) > 0 && Number(form.budget) > 0" class="mt-1 text-[11px] text-slate-400">= {{ money(Number(form.budget) * Number(form.partner_pct) / 100) }} {{ $e('партнёру (вычитается из остатка)') }}</p>
                        <InputError :message="form.errors.partner_pct" class="mt-1" />
                    </div>
                    <div><InputLabel :value="$e('Срок')" /><TextInput v-model="form.deadline" type="date" class="mt-1 w-full" /></div>
                    <div class="sm:col-span-2"><InputLabel :value="$e('Описание')" /><textarea v-model="form.description" rows="2" class="mt-1 w-full rounded-md border-slate-300 shadow-sm"></textarea></div>
                    <div class="sm:col-span-2"><InputLabel :value="$e('Заметка (кратко)')" /><textarea v-model="form.note" rows="2" class="mt-1 w-full rounded-md border-slate-300 shadow-sm" :placeholder="$e('Коротко и чётко по сделке')"></textarea></div>
                </div>
                <div class="mt-6 flex justify-end gap-2">
                    <SecondaryButton @click="showModal = false">{{ $e('Отмена') }}</SecondaryButton>
                    <PrimaryButton :disabled="form.processing" @click="submit">{{ $e('Создать') }}</PrimaryButton>
                </div>
            </div>
        </Modal>

        <!-- BIN EXISTS MODAL -->
        <Modal :show="showBinModal" @close="showBinModal = false" max-width="lg">
            <div class="p-6">
                <h2 class="text-lg font-semibold text-slate-900">{{ $e('С этим номером договора уже есть данные') }}</h2>
                <p class="mt-1 text-sm text-slate-500">{{ $e('Можно подставить его данные в новую сделку.') }}</p>
                <div class="mt-4 rounded-lg bg-slate-50 p-4 border border-slate-200">
                    <div class="text-xs uppercase tracking-wide text-slate-400">{{ $e('Компания') }}</div>
                    <div class="text-base font-semibold text-slate-900">{{ binMatch?.company_name }}</div>
                    <div class="mt-2 grid grid-cols-2 gap-1 text-xs text-slate-500">
                        <div>{{ $e('Номер договора:') }} <span class="font-medium text-slate-700">{{ binMatch?.bin }}</span></div>
                        <div v-if="binMatch?.phone">{{ $e('Тел:') }} <span class="font-medium text-slate-700">{{ binMatch.phone }}</span></div>
                        <div v-if="binMatch?.address" class="col-span-2">{{ $e('Адрес:') }} <span class="font-medium text-slate-700">{{ binMatch.address }}</span></div>
                    </div>
                </div>

                <button v-if="binHistory.length" type="button" @click="showBinHistory = !showBinHistory"
                    class="mt-4 text-sm font-medium text-indigo-600 hover:text-indigo-700">
                    {{ showBinHistory ? '▾' : '▸' }} {{ $e('История сделок по этому номеру договора (') }}{{ binHistory.length }})
                </button>
                <div v-if="showBinHistory" class="mt-2 max-h-56 space-y-1.5 overflow-y-auto pr-1">
                    <div v-for="h in binHistory" :key="h.id" class="flex items-center justify-between rounded-lg border border-slate-100 px-3 py-2 text-xs">
                        <div class="min-w-0">
                            <div class="truncate font-medium text-slate-800">{{ h.company || h.client || h.number }}</div>
                            <div class="text-slate-400">{{ h.number }} · {{ h.created }}<span v-if="h.stage"> · {{ h.stage }}</span></div>
                        </div>
                        <div class="tabular-nums font-semibold text-slate-700">{{ money(h.budget) }}</div>
                    </div>
                </div>

                <div class="mt-6 flex justify-end gap-2">
                    <SecondaryButton @click="showBinModal = false">{{ $e('Отмена') }}</SecondaryButton>
                    <PrimaryButton @click="applyBinMatch">{{ $e('Подставить данные') }}</PrimaryButton>
                </div>
            </div>
        </Modal>

        <!-- Выбор цеха (если их несколько) — как на карточке сделки -->
        <Modal :show="!!workshopPickDeal" max-width="sm" @close="workshopPickDeal = null">
            <div class="p-6">
                <h3 class="mb-1 text-base font-semibold text-slate-900">{{ $e('В какой цех отправить?') }}</h3>
                <p class="mb-4 text-xs text-slate-400">{{ workshopPickDeal?.number }} · {{ workshopPickDeal?.company_name }} {{ $e('— у компании несколько цехов, у каждого своя воронка.') }}</p>
                <div class="space-y-2">
                    <button v-for="w in workshopOptions" :key="w" @click="toWorkshop(workshopPickDeal, w)"
                        class="w-full rounded-xl border border-slate-200 px-4 py-3 text-left text-sm font-semibold text-slate-800 transition hover:border-emerald-400 hover:bg-emerald-50">{{ w }}</button>
                </div>
                <div class="mt-4 text-right">
                    <SecondaryButton @click="workshopPickDeal = null">{{ $e('Отмена') }}</SecondaryButton>
                </div>
            </div>
        </Modal>
    </AppLayout>
</template>
