<script setup>
import { computed, ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Avatar from '@/Components/Avatar.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import { formatDate } from '@/utils/format';
import { confirmDialog } from '@/composables/useConfirm';
import { UNITS } from '@/utils/dealOptions';
import { useE } from '@/composables/useTranslations';

const tr = useE();

const props = defineProps({
    preDeals: Array, items: Array, minMargin: Number, taxPercent: Number,
    leadership: Boolean, stats: Array, managers: Array, filters: Object, canManageChecklist: Boolean,
});

const money = (v) => new Intl.NumberFormat('ru-RU').format(Math.round(v ?? 0)) + ' ₸';

// Фильтры: менеджер, статус и месяц внесения (какие заявки в какой день вводили).
const managerF = ref(props.filters?.manager ?? '');
const statusF = ref(props.filters?.status ?? '');
const monthF = ref(props.filters?.month ?? '');
const applyFilters = () => router.get(route('preDeals.index'), {
    manager: managerF.value || undefined, status: statusF.value || undefined,
    month: monthF.value || undefined,
}, { preserveState: true, preserveScroll: true, replace: true });

// Форма заявки: живой расчёт как в Excel (партнёр/налог/остаток/маржа).
// «Сегодня + прошлые» как блок «Расходы» на Финансах: сегодняшние заявки сверху,
// прошлые — аккордеоном (свёрнуты, раскрываются кликом по заголовку).
const isToday = (d) => d && new Date(d).toDateString() === new Date().toDateString();
const showPast = ref(false);
const requestGroups = computed(() => {
    // Выбран месяц — секция на КАЖДУЮ дату внесения (свежие сверху), все раскрыты.
    if (monthF.value) {
        const byDate = new Map();
        for (const p of props.preDeals) {
            const k = (p.created_at || '').slice(0, 10);
            if (!byDate.has(k)) byDate.set(k, []);
            byDate.get(k).push(p);
        }
        return [...byDate.entries()]
            .sort((a, b) => b[0].localeCompare(a[0]))
            .map(([d, list]) => ({ key: d, label: formatDate(d), list, open: true, toggle: false }));
    }
    const today = props.preDeals.filter((p) => isToday(p.created_at));
    const past = props.preDeals.filter((p) => !isToday(p.created_at));
    return [
        { key: 'today', label: tr('Сегодня'), list: today, open: true, toggle: false },
        { key: 'past', label: tr('Прошлые'), list: past, open: showPast.value, toggle: true },
    ];
});

const showForm = ref(false);
const editingId = ref(null);
const form = useForm({ request_number: '', valid_until: '', bin: '', customer: '', object_address: '', client_name: '', client_phone: '', product: '', quantity: '', unit: tr('м²'), unit_price: '', contract_sum: '', purchase_price: '', partner_pct: '', delivery: '', assembly: '', commission: '' });
// Срок действия КП: сегодня/прошёл у незакрытой заявки — подсветка.
const quoteUrgent = (p) => p.valid_until && p.status === 'new' && new Date(p.valid_until) <= new Date(new Date().toDateString());
// Сумма КП = объём × цена за единицу (если заданы оба), иначе вводится вручную.
const autoSum = computed(() => {
    const q = Number(form.quantity || 0), p = Number(form.unit_price || 0);
    return q > 0 && p > 0 ? Math.round(q * p * 100) / 100 : null;
});
// Автосумма подставляется в поле, чтобы уходила на сервер уже посчитанной.
watch(autoSum, (v) => { if (v !== null) form.contract_sum = v; });
const calc = computed(() => {
    const sum = autoSum.value ?? Number(form.contract_sum || 0);
    const partner = Math.round(sum * Number(form.partner_pct || 0)) / 100;
    const tax = Math.round(sum * (props.taxPercent ?? 3)) / 100;
    const remainder = Math.round((sum - Number(form.purchase_price || 0) - partner - Number(form.delivery || 0) - Number(form.assembly || 0) - Number(form.commission || 0) - tax) * 100) / 100;
    const margin = sum > 0 ? Math.round(remainder / sum * 10000) / 100 : 0;
    return { partner, tax, remainder, margin, pass: margin >= (props.minMargin ?? 15) };
});
// Кнопка «Проверить» у № заявки: занят ли номер — ДО заполнения остальных полей.
const numberCheck = ref(null);      // null | {exists, manager, date, status}
const numberChecking = ref(false);
const checkNumber = async () => {
    if (!form.request_number || numberChecking.value) return;
    numberChecking.value = true;
    try {
        const { data } = await window.axios.get(route('preDeals.checkNumber'), {
            params: { request_number: form.request_number, ignore: editingId.value || undefined },
        });
        numberCheck.value = data;
    } catch (e) { numberCheck.value = null; }
    numberChecking.value = false;
};

const openCreate = () => { editingId.value = null; form.reset(); form.clearErrors(); numberCheck.value = null; showForm.value = true; };
const openEdit = (p) => {
    editingId.value = p.id;
    form.clearErrors();
    numberCheck.value = null;
    Object.assign(form, {
        request_number: p.request_number ?? '', valid_until: p.valid_until ? p.valid_until.slice(0, 10) : '', bin: p.bin ?? '', customer: p.customer ?? '',
        object_address: p.object_address ?? '',
        client_name: p.client_name ?? '', client_phone: p.client_phone ?? '', product: p.product,
        quantity: Number(p.quantity), unit: p.unit ?? tr('м²'), unit_price: Number(p.unit_price),
        contract_sum: Number(p.contract_sum), purchase_price: Number(p.purchase_price),
        partner_pct: Number(p.partner_pct), delivery: Number(p.delivery), assembly: Number(p.assembly), commission: Number(p.commission),
    });
    showForm.value = true;
};
const submit = () => (editingId.value
    ? form.put(route('preDeals.update', editingId.value), { preserveScroll: true, onSuccess: () => (showForm.value = false) })
    : form.post(route('preDeals.store'), { preserveScroll: true, onSuccess: () => (showForm.value = false) }));

// Откат случайного «В работу ✓»: сделка удалится, заявка вернётся «В работе».
const revertDeal = async (p) => {
    if (!(await confirmDialog({ title: tr('Вернуть в заявки?'), message: `Сделка ${p.deal?.number ?? ''} будет удалена, заявка снова станет «В работе». Возможно, только пока по сделке нет счетов, расходов и заказа цеха.`, confirmText: tr('↩ Вернуть'), danger: true }))) return;
    router.post(route('preDeals.revert', p.id), {}, { preserveScroll: true });
};

const confirmDeal = async (p) => {
    if (!(await confirmDialog({ title: tr('Заказ подтверждён — создать сделку?'), message: `«${p.product}» на ${money(p.contract_sum)} (маржа ${p.margin}%): заявка станет сделкой и появится на странице «Сделки».`, confirmText: tr('В работу ✓') }))) return;
    router.post(route('preDeals.confirm', p.id), {}, { preserveScroll: true });
};
const del = async (p) => {
    if (!(await confirmDialog({ title: tr('Удалить заявку?'), message: `«${p.product}» будет удалена.`, confirmText: tr('Удалить'), danger: true }))) return;
    router.delete(route('preDeals.destroy', p.id), { preserveScroll: true });
};

// Чек-лист: раскрытие строки + галочки.
const expanded = ref(null);
const checked = (p, item) => !!(p.checks ?? {})[String(item.id)];
const checkedCount = (p) => props.items.filter((i) => checked(p, i)).length;
const toggleCheck = (p, item) => router.post(route('preDeals.check', [p.id, item.id]), {}, { preserveScroll: true });

// Управление чек-листом (админ/финансист).
const showItems = ref(false);
const newItem = ref('');
const itemNames = ref({});
const openItems = () => { itemNames.value = Object.fromEntries(props.items.map((i) => [i.id, i.label])); showItems.value = true; };
const addItem = () => {
    if (!newItem.value.trim()) return;
    router.post(route('preDealItems.store'), { label: newItem.value.trim() }, { preserveScroll: true, onSuccess: () => (newItem.value = '') });
};
const saveItem = (i) => {
    const label = (itemNames.value[i.id] ?? '').trim();
    if (!label || label === i.label) return;
    router.put(route('preDealItems.update', i.id), { label }, { preserveScroll: true });
};
const delItem = async (i) => {
    if (!(await confirmDialog({ title: `Удалить пункт «${i.label}»?`, confirmText: tr('Удалить'), danger: true }))) return;
    router.delete(route('preDealItems.destroy', i.id), { preserveScroll: true });
};

const marginClass = (m) => Number(m) >= (props.minMargin ?? 15)
    ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700';
</script>

<template>
    <Head :title="$e('Заявки')" />
    <AppLayout>
        <template #header>{{ $e('Заявки / запросы КП') }}</template>

        <!-- Рейтинг менеджеров (руководству) -->
        <div v-if="leadership && stats?.length" class="mb-4 rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-100 px-5 py-3 text-sm font-semibold text-slate-900">{{ $e('Рейтинг менеджеров') }} <span class="font-normal text-slate-400">{{ $e('— по заявкам, ставшим сделками') }}</span></div>
            <div class="flex gap-3 overflow-x-auto px-5 py-3">
                <div v-for="(m, i) in stats" :key="m.name" class="flex min-w-56 flex-shrink-0 items-center gap-3 rounded-xl border p-3"
                    :class="i === 0 ? 'border-amber-200 bg-amber-50/60' : 'border-slate-100 bg-slate-50'">
                    <span class="text-lg font-bold" :class="i === 0 ? '' : 'text-slate-300'">{{ i === 0 ? '👑' : i + 1 }}</span>
                    <Avatar :name="m.name" :src="m.avatar" :size="36" />
                    <div class="min-w-0">
                        <div class="truncate text-sm font-semibold text-slate-900">{{ m.name }}</div>
                        <div class="text-xs text-slate-500">{{ $e('подтв.') }} <b>{{ m.confirmed }}</b> {{ $e('из') }} {{ m.total }} · <b class="tabular-nums">{{ money(m.sum) }}</b></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Панель: фильтры + действия -->
        <div class="mb-4 flex flex-wrap items-center gap-2">
            <PrimaryButton @click="openCreate">{{ $e('+ Заявка') }}</PrimaryButton>
            <button v-if="canManageChecklist" @click="openItems"
                class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-medium text-slate-600 shadow-sm transition hover:bg-slate-50">{{ $e('⚙ Чек-лист') }}</button>
            <div class="ml-auto flex flex-wrap items-center gap-2">
                <select v-if="leadership" v-model="managerF" @change="applyFilters" class="rounded-lg border-slate-200 py-1.5 text-sm text-slate-600 shadow-sm">
                    <option value="">{{ $e('Все менеджеры') }}</option>
                    <option v-for="m in managers" :key="m.id" :value="m.id">{{ m.name }}</option>
                </select>
                <select v-model="statusF" @change="applyFilters" class="rounded-lg border-slate-200 py-1.5 text-sm text-slate-600 shadow-sm">
                    <option value="">{{ $e('Все статусы') }}</option>
                    <option value="new">{{ $e('В работе') }}</option>
                    <option value="confirmed">{{ $e('Подтверждённые') }}</option>
                </select>
                <label class="flex items-center gap-1 text-xs text-slate-400" :title="$e('Показать заявки, внесённые в выбранном месяце — по датам')">{{ $e('месяц') }}
                    <input v-model="monthF" @change="applyFilters" type="month" class="rounded-lg border-slate-200 py-1.5 text-sm text-slate-600 shadow-sm" />
                </label>
                <button v-if="monthF" @click="monthF = ''; applyFilters()" class="text-xs font-medium text-indigo-600 hover:underline">{{ $e('сбросить') }}</button>
                <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs text-slate-500">{{ $e('порог маржи:') }} <b>{{ minMargin }}%</b></span>
            </div>
        </div>

        <!-- Таблица как Excel -->
        <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="overflow-x-auto">
                <table class="min-w-full whitespace-nowrap divide-y divide-slate-100 text-sm">
                    <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-400">
                        <tr>
                            <th class="px-4 py-2.5">{{ $e('№ заявки') }}</th>
                            <th class="px-4 py-2.5">{{ $e('Заказчик · изделие') }}</th>
                            <th v-if="leadership" class="px-4 py-2.5">{{ $e('Менеджер') }}</th>
                            <th class="px-4 py-2.5 text-right">{{ $e('Объём') }}</th>
                            <th class="px-4 py-2.5 text-right">{{ $e('Сумма КП') }}</th>
                            <th class="px-4 py-2.5 text-right">{{ $e('Закуп') }}</th>
                            <th class="px-4 py-2.5 text-right">{{ $e('Партнёр') }}</th>
                            <th class="px-4 py-2.5 text-right">{{ $e('Доставка') }}</th>
                            <th class="px-4 py-2.5 text-right">{{ $e('Монтаж') }}</th>
                            <th class="px-4 py-2.5 text-right">{{ $e('Комиссия') }}</th>
                            <th class="px-4 py-2.5 text-right">{{ $e('Налог') }}</th>
                            <th class="px-4 py-2.5 text-right">{{ $e('Остаток') }}</th>
                            <th class="px-4 py-2.5 text-center">{{ $e('Маржа') }}</th>
                            <th class="px-4 py-2.5 text-center">{{ $e('Берём') }}</th>
                            <th class="px-4 py-2.5 text-center">{{ $e('Чек-лист') }}</th>
                            <th class="px-4 py-2.5"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        <template v-for="g in requestGroups" :key="g.key">
                        <!-- Секция «Сегодня» / «Прошлые» (аккордеон) -->
                        <tr v-if="g.list.length || g.toggle" class="bg-slate-100/70" :class="g.toggle ? 'cursor-pointer select-none hover:bg-slate-200/60' : ''"
                            @click="g.toggle && (showPast = !showPast)">
                            <td :colspan="leadership ? 16 : 15" class="px-4 py-2">
                                <span class="flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wide text-slate-500">
                                    <svg v-if="g.toggle" class="h-3.5 w-3.5 text-slate-400 transition-transform" :class="showPast ? 'rotate-90' : ''" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 5l5 5-5 5"/></svg>
                                    {{ g.label }} <span class="font-medium normal-case tracking-normal text-slate-400">{{ g.list.length }}</span>
                                </span>
                            </td>
                        </tr>
                        <tr v-if="g.key === 'today' && !g.list.length"><td :colspan="leadership ? 16 : 15" class="px-6 py-4 text-center text-xs text-slate-300">{{ $e('Сегодня заявок ещё нет') }}</td></tr>
                        <template v-if="g.open">
                        <template v-for="p in g.list" :key="p.id">
                            <tr class="hover:bg-slate-50">
                                <td class="px-4 py-3 text-slate-500">{{ p.request_number || '—' }}<span class="block text-[10px] text-slate-300">{{ formatDate(p.created_at) }}</span>
                                    <span v-if="p.valid_until" class="block text-[10px] font-semibold" :class="quoteUrgent(p) ? 'text-rose-600' : 'text-slate-400'">{{ $e('⏳ КП до') }} {{ formatDate(p.valid_until) }}</span>
                                </td>
                                <td class="max-w-56 px-4 py-3">
                                    <div class="truncate font-medium text-slate-800" :title="p.customer">{{ p.customer || '—' }}<span v-if="p.bin" class="text-xs text-slate-400"> · {{ p.bin }}</span></div>
                                    <div class="truncate text-xs text-slate-500" :title="p.product">{{ p.product }}</div>
                                    <div v-if="p.object_address" class="truncate text-[11px] text-slate-400" :title="p.object_address">📍 {{ p.object_address }}</div>
                                </td>
                                <td v-if="leadership" class="px-4 py-3 text-xs text-slate-500">{{ p.user?.name ?? '—' }}</td>
                                <td class="px-4 py-3 text-right tabular-nums text-slate-600">
                                    <template v-if="Number(p.quantity) > 0">{{ Number(p.quantity) }} {{ p.unit || '' }}<span v-if="Number(p.unit_price) > 0" class="block text-[10px] text-slate-400">{{ money(p.unit_price) }}/{{ p.unit || $e('ед') }}</span></template>
                                    <span v-else class="text-slate-300">—</span>
                                </td>
                                <td class="px-4 py-3 text-right font-semibold tabular-nums text-slate-900">{{ money(p.contract_sum) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums text-slate-600">{{ money(p.purchase_price) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums text-slate-600">{{ money(p.partner_sum) }}<span class="block text-[10px] text-slate-400">{{ Number(p.partner_pct) }}%</span></td>
                                <td class="px-4 py-3 text-right tabular-nums text-slate-600">{{ money(p.delivery) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums text-slate-600">{{ money(p.assembly) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums text-slate-600">{{ money(p.commission) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums text-slate-600">{{ money(p.tax) }}</td>
                                <td class="px-4 py-3 text-right font-semibold tabular-nums" :class="Number(p.remainder) >= 0 ? 'text-slate-900' : 'text-rose-600'">{{ money(p.remainder) }}</td>
                                <td class="px-4 py-3 text-center"><span class="rounded-full px-2 py-0.5 text-xs font-bold tabular-nums" :class="marginClass(p.margin)">{{ Number(p.margin) }}%</span></td>
                                <td class="px-4 py-3 text-center">
                                    <span class="rounded-md px-2.5 py-1 text-xs font-bold" :class="Number(p.margin) >= minMargin ? 'bg-emerald-500 text-white' : 'bg-rose-500 text-white'">{{ Number(p.margin) >= minMargin ? $e('да') : $e('нет') }}</span>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <button @click="expanded = expanded === p.id ? null : p.id"
                                        class="rounded-full px-2.5 py-1 text-xs font-semibold transition"
                                        :class="checkedCount(p) === items.length && items.length ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'">
                                        ☑ {{ checkedCount(p) }}/{{ items.length }}</button>
                                </td>
                                <td class="px-4 py-3 text-right whitespace-nowrap">
                                    <template v-if="p.status === 'confirmed'">
                                        <span class="rounded-full bg-indigo-100 px-2.5 py-1 text-xs font-semibold text-indigo-700">→ {{ p.deal?.number ?? $e('сделка') }}</span>
                                        <button class="ml-1 rounded p-1 text-slate-300 transition hover:text-amber-600" :title="$e('Вернуть в заявки (нажали «В работу» случайно)')" @click="revertDeal(p)">↩</button>
                                    </template>
                                    <template v-else>
                                        <button v-if="Number(p.margin) >= minMargin" @click="confirmDeal(p)"
                                            class="rounded-lg bg-emerald-600 px-2.5 py-1.5 text-xs font-semibold text-white transition hover:bg-emerald-700">{{ $e('В работу ✓') }}</button>
                                        <span v-else class="text-[11px] text-rose-400" :title="$e('Маржа ниже порога — заявка отклонена')">{{ $e('отклонена') }}</span>
                                        <button class="ml-1 rounded p-1 text-slate-300 transition hover:text-indigo-600" :title="$e('Изменить')" @click="openEdit(p)">
                                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>
                                        </button>
                                        <button class="rounded p-1 text-slate-300 transition hover:text-rose-600" :title="$e('Удалить')" @click="del(p)">✕</button>
                                    </template>
                                </td>
                            </tr>
                            <!-- Чек-лист + контакт клиента -->
                            <tr v-if="expanded === p.id" class="bg-slate-50/60">
                                <td :colspan="leadership ? 16 : 15" class="px-6 py-3">
                                    <div class="flex flex-wrap items-center gap-x-6 gap-y-2">
                                        <label v-for="i in items" :key="i.id" class="flex cursor-pointer items-center gap-2 text-sm text-slate-700">
                                            <input type="checkbox" :checked="checked(p, i)" @change="toggleCheck(p, i)"
                                                class="rounded border-slate-300 text-emerald-600 focus:ring-emerald-500" />
                                            {{ i.label }}
                                        </label>
                                        <span v-if="p.client_name || p.client_phone" class="ml-auto text-sm text-slate-500">
                                            {{ $e('Клиент:') }} <b class="text-slate-700">{{ p.client_name || '—' }}</b>
                                            <a v-if="p.client_phone" :href="'tel:' + p.client_phone" class="ml-2 font-semibold text-indigo-600 hover:underline">{{ p.client_phone }}</a>
                                        </span>
                                    </div>
                                </td>
                            </tr>
                        </template>
                        </template>
                        </template>
                        <tr v-if="!preDeals.length"><td :colspan="leadership ? 16 : 15" class="px-6 py-10 text-center text-slate-400">{{ $e('Пока нет заявок — «+ Заявка»') }}</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Модалка заявки: живой расчёт -->
        <Modal :show="showForm" max-width="2xl" @close="showForm = false">
            <div class="p-6">
                <h3 class="mb-4 text-base font-semibold text-slate-900">{{ editingId ? $e('Изменить заявку') : $e('Новая заявка') }}</h3>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div>
                        <InputLabel :value="$e('№ заявки / КП')" />
                        <div class="mt-1 flex gap-2">
                            <TextInput v-model="form.request_number" class="w-full" @input="numberCheck = null" @keydown.enter.prevent="checkNumber" />
                            <button type="button" @click="checkNumber" :disabled="!form.request_number || numberChecking"
                                class="shrink-0 rounded-lg border border-indigo-200 bg-indigo-50 px-3 text-xs font-semibold text-indigo-600 transition hover:bg-indigo-100 disabled:opacity-40">
                                {{ numberChecking ? '…' : $e('Проверить') }}
                            </button>
                        </div>
                        <p v-if="numberCheck?.exists" class="mt-1 text-xs font-semibold text-rose-600">
                            {{ $e('✗ Такая заявка уже внесена') }}<template v-if="numberCheck.manager"> — {{ numberCheck.manager }}</template><template v-if="numberCheck.date"> ({{ formatDate(numberCheck.date) }})</template><template v-if="numberCheck.status === 'confirmed'"> {{ $e('· уже в работе') }}</template>
                        </p>
                        <p v-else-if="numberCheck" class="mt-1 text-xs font-semibold text-emerald-600">{{ $e('✓ Свободен — можно заполнять') }}</p>
                        <InputError :message="form.errors.request_number" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel :value="$e('КП действительно до')" />
                        <TextInput v-model="form.valid_until" type="date" class="mt-1 w-full" />
                        <p class="mt-1 text-[11px] text-slate-400">{{ $e('В этот день менеджеру придёт напоминание') }}</p>
                        <InputError :message="form.errors.valid_until" class="mt-1" />
                    </div>
                    <div><InputLabel :value="$e('Заказчик (компания или частное лицо)')" /><TextInput v-model="form.customer" class="mt-1 w-full" /></div>
                    <div><InputLabel :value="$e('БИН / ИИН заказчика')" /><TextInput v-model="form.bin" class="mt-1 w-full" /></div>
                    <div class="sm:col-span-2"><InputLabel :value="$e('Объект (адрес доставки / монтажа)')" /><TextInput v-model="form.object_address" class="mt-1 w-full" :placeholder="$e('г. Астана, ЖК …')" /></div>
                    <div><InputLabel :value="$e('Изделие *')" /><TextInput v-model="form.product" class="mt-1 w-full" :placeholder="$e('Тротуарная плитка 300×300, вазон…')" /><div v-if="form.errors.product" class="mt-1 text-xs text-rose-600">{{ form.errors.product }}</div></div>
                    <div>
                        <InputLabel :value="$e('Объём и единица')" />
                        <div class="mt-1 flex gap-2">
                            <TextInput v-model="form.quantity" type="number" min="0" step="any" class="w-1/2" :placeholder="$e('кол-во')" />
                            <select v-model="form.unit" class="w-1/2 rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option v-for="u in UNITS" :key="u" :value="u">{{ u }}</option>
                            </select>
                        </div>
                        <InputError :message="form.errors.quantity || form.errors.unit" class="mt-1" />
                    </div>
                    <div><InputLabel :value="$e('Цена за единицу')" /><TextInput v-model="form.unit_price" type="number" min="0" step="any" class="mt-1 w-full" /><p class="mt-1 text-[11px] text-slate-400">{{ $e('Объём × цена = сумма КП') }}</p></div>
                    <div>
                        <InputLabel :value="$e('Сумма КП *')" />
                        <TextInput v-model="form.contract_sum" type="number" min="1" class="mt-1 w-full" :disabled="autoSum !== null" />
                        <p v-if="autoSum !== null" class="mt-1 text-[11px] text-emerald-600">{{ $e('Считается автоматически:') }} {{ money(autoSum) }}</p>
                        <div v-if="form.errors.contract_sum" class="mt-1 text-xs text-rose-600">{{ form.errors.contract_sum }}</div>
                    </div>
                    <div><InputLabel :value="$e('Имя клиента (контакт)')" /><TextInput v-model="form.client_name" class="mt-1 w-full" /></div>
                    <div><InputLabel :value="$e('Телефон клиента')" /><TextInput v-model="form.client_phone" class="mt-1 w-full" placeholder="+7 ___ ___ __ __" /></div>
                    <div><InputLabel :value="$e('Себестоимость (сырьё, производство)')" /><TextInput v-model="form.purchase_price" type="number" min="0" class="mt-1 w-full" /></div>
                    <div><InputLabel :value="$e('Доля партнёра, %')" /><TextInput v-model="form.partner_pct" type="number" min="0" max="100" step="0.1" class="mt-1 w-full" /></div>
                    <div><InputLabel :value="$e('Доставка, разгрузка')" /><TextInput v-model="form.delivery" type="number" min="0" class="mt-1 w-full" /></div>
                    <div><InputLabel :value="$e('Монтаж / укладка')" /><TextInput v-model="form.assembly" type="number" min="0" class="mt-1 w-full" /></div>
                    <div><InputLabel :value="$e('Комиссия (площадка, агент)')" /><TextInput v-model="form.commission" type="number" min="0" class="mt-1 w-full" /></div>
                </div>

                <!-- Живой расчёт -->
                <div class="mt-4 rounded-xl border p-4" :class="calc.pass ? 'border-emerald-200 bg-emerald-50/60' : 'border-rose-200 bg-rose-50/60'">
                    <div class="flex flex-wrap items-center gap-x-6 gap-y-1 text-sm">
                        <span class="text-slate-500">{{ $e('Партнёр:') }} <b class="tabular-nums text-slate-700">{{ money(calc.partner) }}</b></span>
                        <span class="text-slate-500">{{ $e('Налог') }} {{ taxPercent }}%: <b class="tabular-nums text-slate-700">{{ money(calc.tax) }}</b></span>
                        <span class="text-slate-500">{{ $e('Остаток:') }} <b class="tabular-nums" :class="calc.remainder >= 0 ? 'text-slate-900' : 'text-rose-600'">{{ money(calc.remainder) }}</b></span>
                        <span class="ml-auto flex items-center gap-2">
                            <span class="rounded-full px-3 py-1 text-sm font-bold tabular-nums" :class="calc.pass ? 'bg-emerald-500 text-white' : 'bg-rose-500 text-white'">{{ $e('маржа') }} {{ calc.margin }}%</span>
                            <span class="text-xs font-semibold" :class="calc.pass ? 'text-emerald-700' : 'text-rose-600'">{{ calc.pass ? $e('берём в работу') : $e('ниже ') + minMargin + $e('% — отклоняется') }}</span>
                        </span>
                    </div>
                </div>

                <div class="mt-4 flex justify-end gap-2">
                    <SecondaryButton @click="showForm = false">{{ $e('Отмена') }}</SecondaryButton>
                    <PrimaryButton :disabled="form.processing" @click="submit">{{ editingId ? $e('Сохранить') : $e('Добавить') }}</PrimaryButton>
                </div>
            </div>
        </Modal>

        <!-- Настройка чек-листа (админ/финансист) -->
        <Modal :show="showItems" max-width="md" @close="showItems = false">
            <div class="p-6">
                <h3 class="mb-1 text-base font-semibold text-slate-900">{{ $e('Чек-лист заявки') }}</h3>
                <p class="mb-4 text-xs text-slate-400">{{ $e('Пункты видят все менеджеры. Переименование — Enter или клик мимо, ✕ — удалить.') }}</p>
                <div class="max-h-72 space-y-2 overflow-y-auto pr-1">
                    <div v-for="i in items" :key="i.id" class="flex items-center gap-2">
                        <input v-model="itemNames[i.id]" @keyup.enter="saveItem(i)" @blur="saveItem(i)" type="text"
                            class="flex-1 rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                        <button @click="delItem(i)" class="rounded p-1.5 text-slate-300 transition hover:text-rose-600" :title="$e('Удалить пункт')">✕</button>
                    </div>
                    <div v-if="!items.length" class="py-4 text-center text-sm text-slate-400">{{ $e('Пунктов пока нет') }}</div>
                </div>
                <div class="mt-4 flex gap-2">
                    <input v-model="newItem" @keyup.enter="addItem" type="text" :placeholder="$e('Новый пункт…')"
                        class="flex-1 rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                    <PrimaryButton type="button" @click="addItem">{{ $e('Добавить') }}</PrimaryButton>
                </div>
                <div class="mt-4 text-right"><SecondaryButton @click="showItems = false">{{ $e('Закрыть') }}</SecondaryButton></div>
            </div>
        </Modal>
    </AppLayout>
</template>
