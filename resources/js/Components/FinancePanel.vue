<script setup>
import { ref, computed, onMounted } from 'vue';
import { useForm, router, usePage } from '@inertiajs/vue3';
import { confirmDialog } from '@/composables/useConfirm';
import StatusBadge from '@/Components/StatusBadge.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import Modal from '@/Components/Modal.vue';
import { useE } from '@/composables/useTranslations';

const tr = useE();

const props = defineProps({
    entityType: String,   // 'deal' | 'project'
    entityId: Number,
    clientId: { type: Number, default: null },
    invoices: { type: Array, default: () => [] },
    expenses: { type: Array, default: () => [] },
    finance: { type: Object, default: () => ({ income: 0, invoiced: 0, expense: 0, profit: 0, margin: 0 }) },
    materials: { type: Array, default: () => [] }, // склад компании сделки (для расходов по материалам)
    balances: { type: Object, default: null }, // остатки касса/банк (видит бухгалтер/админ)
});

const money = (v) => new Intl.NumberFormat('ru-RU').format(v ?? 0) + ' ₸';
const barIncome = computed(() => { const t = (props.finance.income ?? 0) + (props.finance.expense ?? 0); return t > 0 ? (props.finance.income / t * 100) : 0; });
const barExpense = computed(() => { const t = (props.finance.income ?? 0) + (props.finance.expense ?? 0); return t > 0 ? (props.finance.expense / t * 100) : 0; });
// Визуальное: плавный рост полосы «Доход vs Расходы» после монтирования (0 → значение).
const barMounted = ref(false);
onMounted(() => requestAnimationFrame(() => (barMounted.value = true)));

const showInvoice = ref(false);
const invoiceForm = useForm({
    invoiceable_type: props.entityType, invoiceable_id: props.entityId,
    client_id: props.clientId, amount: 0, status: 'sent', due_date: '', description: '',
});
const addInvoice = () => invoiceForm.post(route('invoices.store'), {
    preserveScroll: true, onSuccess: () => { invoiceForm.reset('amount', 'due_date', 'description'); showInvoice.value = false; },
});

const payFor = ref(null);
const payForm = useForm({ invoice_id: null, amount: 0, payment_date: new Date().toISOString().slice(0, 10), payment_method: 'bank', reference: '' });
const openPay = (inv) => { payFor.value = inv.id; payForm.invoice_id = inv.id; payForm.amount = Math.max(0, inv.amount - (inv.payments_sum_amount ?? 0)); };
const addPayment = () => payForm.post(route('payments.store'), { preserveScroll: true, onSuccess: () => { payFor.value = null; payForm.reset('amount', 'reference'); } });

const showExpense = ref(false);
const receiptInput = ref(null);
// Тип расхода: прочий (чек) / доставка / закуп / по материалам (со склада).
// Виды идут в Сводный отчёт и Аналитику отдельными колонками.
const expenseMode = ref('other'); // other | delivery | purchase | assembly | material
const EXPENSE_TYPE = { other: 'direct', delivery: 'delivery', purchase: 'purchase', assembly: 'assembly' };
const expenseTypeLabels = { delivery: tr('🚚 Доставка'), purchase: tr('📦 Закуп'), assembly: tr('🔧 Сборка') };
const expenseForm = useForm({ expenseable_type: props.entityType, expenseable_id: props.entityId, material_id: '', qty: '', amount: 0, date: new Date().toISOString().slice(0, 10), description: '', type: 'direct', status: 'confirmed', payment_method: 'cash', file: null });
const onReceipt = (e) => { expenseForm.file = e.target.files[0] ?? null; };
const selectedMaterial = computed(() => props.materials.find((m) => m.id === expenseForm.material_id));
const qtyNum = (v) => new Intl.NumberFormat('ru-RU').format(Number(v ?? 0));
// Цена закупки на материале (последний приход): сумма расхода = количество × цена.
// Для старых позиций без цены сумму вводят вручную.
const materialPrice = computed(() => Number(selectedMaterial.value?.price ?? 0));
const autoAmount = computed(() => materialPrice.value > 0 ? Math.round(Number(expenseForm.qty || 0) * materialPrice.value * 100) / 100 : null);
// «Доступно N»: расход списывается с поступлений (касса/банк) — показываем
// остаток выбранного способа и предупреждаем о превышении (не блокируем).
const effAmount = computed(() => Number((expenseMode.value === 'material' ? (autoAmount.value ?? expenseForm.amount) : expenseForm.amount) || 0));
const methodBalance = computed(() => props.balances ? Number(expenseForm.payment_method === 'cash' ? props.balances.cash : props.balances.bank) : null);
const overBalance = computed(() => methodBalance.value !== null && effAmount.value > methodBalance.value);
const canSubmitExpense = computed(() => expenseMode.value === 'material'
    ? !!expenseForm.material_id && Number(expenseForm.qty) > 0 && (materialPrice.value > 0 || Number(expenseForm.amount) > 0)
    : Number(expenseForm.amount) > 0);

// Подтверждение прочего расхода — только бухгалтер (financist) или админ.
const canConfirm = computed(() => (usePage().props.auth.user?.roles ?? []).some((r) => ['admin', 'financist'].includes(r)));
const confirmFor = ref(null); // id расхода, открытого на подтверждение
const confirmInput = ref(null);
const confirmForm = useForm({ payment_method: 'bank', file: null });
const openConfirm = (e) => { confirmFor.value = e.id; confirmForm.reset(); confirmForm.payment_method = 'bank'; };
const onConfirmReceipt = (ev) => { confirmForm.file = ev.target.files[0] ?? null; };
const submitConfirm = (e) => confirmForm
    .transform((d) => ({ ...d, _method: 'PATCH' }))
    .post(route('expenses.confirm', e.id), {
        preserveScroll: true, forceFormData: true,
        onSuccess: () => { confirmFor.value = null; },
    });
const addExpense = () => expenseForm
    .transform((d) => expenseMode.value === 'material'
        ? { ...d, file: null, amount: autoAmount.value ?? d.amount } // сервер пересчитает: кол-во × цена
        : { ...d, material_id: '', qty: '', type: EXPENSE_TYPE[expenseMode.value] ?? 'direct' })
    .post(route('expenses.store'), {
        preserveScroll: true, forceFormData: true,
        onSuccess: () => { expenseForm.reset('amount', 'description', 'file', 'material_id', 'qty'); if (receiptInput.value) receiptInput.value.value = ''; showExpense.value = false; },
    });
const fmtDateTime = (v) => v ? new Date(v).toLocaleString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '';
const isImage = (path) => /\.(jpe?g|png|webp|heic)$/i.test(path ?? '');

const receiptView = ref(null); // { url, image } — открытый на просмотр чек
const openReceipt = (e) => { receiptView.value = { url: route('expenses.receipt', e.id), image: isImage(e.file_path) }; };
const closeReceipt = () => { receiptView.value = null; };

const delInvoice = async (i) => { if (await confirmDialog({ title: tr('Удалить счёт'), message: tr('Счёт будет удалён.'), confirmText: tr('Удалить'), danger: true })) router.delete(route('invoices.destroy', i.id), { preserveScroll: true }); };
const delExpense = async (e) => { if (await confirmDialog({ title: tr('Удалить расход'), message: tr('Расход будет удалён.'), confirmText: tr('Удалить'), danger: true })) router.delete(route('expenses.destroy', e.id), { preserveScroll: true }); };
</script>

<template>
    <div class="space-y-6">
        <!-- Summary -->
        <div class="space-y-4">
            <div class="grid grid-cols-3 gap-3">
                <div class="rounded-xl bg-indigo-50 p-4"><div class="text-xs font-medium text-indigo-700">{{ $e('Сумма договора') }}</div><div class="mt-0.5 text-lg font-bold tabular-nums text-indigo-700">{{ money(finance.budget) }}</div></div>
                <div class="rounded-xl bg-emerald-50 p-4"><div class="text-xs font-medium text-emerald-700">{{ $e('Аванс (оплачено)') }}</div><div class="mt-0.5 text-lg font-bold tabular-nums text-emerald-700">{{ money(finance.income) }}</div></div>
                <div class="rounded-xl bg-rose-50 p-4"><div class="text-xs font-medium text-rose-700">{{ $e('Расходы') }}</div><div class="mt-0.5 text-lg font-bold tabular-nums text-rose-700">{{ money(finance.expense) }}</div></div>
            </div>

            <!-- income vs expense bar -->
            <div>
                <div class="mb-1.5 flex justify-between text-xs font-medium text-slate-500"><span>{{ $e('Доход vs Расходы') }}</span><span>{{ $e('наценка') }} {{ finance.markup }}%</span></div>
                <div class="flex h-3 overflow-hidden rounded-full bg-slate-100">
                    <div class="h-full bg-gradient-to-r from-emerald-400 to-emerald-600 transition-[width] duration-500 ease-out" :style="{ width: (barMounted ? barIncome : 0) + '%' }"></div>
                    <div class="h-full bg-gradient-to-r from-rose-400 to-rose-500 transition-[width] duration-500 ease-out" :style="{ width: (barMounted ? barExpense : 0) + '%' }"></div>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div class="rounded-xl border border-slate-200 p-4">
                    <div class="text-xs font-semibold uppercase tracking-wide text-slate-400">{{ $e('Факт (по оплатам)') }}</div>
                    <div class="mt-1 flex justify-between text-sm"><span class="text-slate-500">{{ $e('Прибыль') }}</span><span class="font-bold tabular-nums" :class="finance.profit >= 0 ? 'text-emerald-600' : 'text-rose-600'">{{ money(finance.profit) }}</span></div>
                    <div class="flex justify-between text-sm"><span class="text-slate-500">{{ $e('Маржа') }}</span><span class="font-bold tabular-nums">{{ finance.margin }}%</span></div>
                    <div class="flex justify-between text-sm"><span class="text-slate-500">{{ $e('Доля расходов') }}</span><span class="tabular-nums">{{ finance.expenseRatio }}%</span></div>
                </div>
                <div class="rounded-xl border border-slate-200 p-4">
                    <div class="text-xs font-semibold uppercase tracking-wide text-slate-400">{{ $e('План (по сумме сделки)') }}</div>
                    <div class="mt-1 flex justify-between text-sm"><span class="text-slate-500">{{ $e('Выгода') }}</span><span class="font-bold tabular-nums" :class="finance.plannedProfit >= 0 ? 'text-emerald-600' : 'text-rose-600'">{{ money(finance.plannedProfit) }}</span></div>
                    <div class="flex justify-between text-sm"><span class="text-slate-500">{{ $e('Маржа') }}</span><span class="font-bold tabular-nums">{{ finance.plannedMargin }}%</span></div>
                    <div v-if="finance.plannedProfit < 0" class="mt-1 flex items-center gap-1 text-xs font-medium text-rose-600">
                        <svg class="h-3.5 w-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0zM12 9v4M12 17h.01"/></svg>
                        {{ $e('Расходы превысили сумму договора') }}
                    </div>
                </div>
            </div>
        </div>

        <!-- Invoices -->
        <div>
            <div class="mb-3 flex items-center justify-between">
                <h4 class="text-sm font-semibold text-slate-900">{{ $e('Аванс') }}</h4>
                <button class="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white transition-colors duration-150 hover:bg-indigo-700" @click="showInvoice = !showInvoice">{{ $e('+ Счёт') }}</button>
            </div>
            <div v-if="showInvoice" class="mb-3 rounded-xl border border-dashed border-slate-300 p-4">
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <TextInput v-model="invoiceForm.amount" type="number" step="0.01" :placeholder="$e('Сумма *')"
                            :class="Number(invoiceForm.amount) <= 0 ? 'border-red-400 focus:border-red-400 focus:ring-red-300' : ''" class="w-full" />
                        <div v-if="Number(invoiceForm.amount) <= 0" class="mt-1 text-xs font-medium text-red-600">{{ $e('Введите сумму счёта') }}</div>
                    </div>
                    <TextInput v-model="invoiceForm.due_date" type="date" />
                </div>
                <TextInput v-model="invoiceForm.description" :placeholder="$e('Описание')" class="mt-2 w-full" />
                <div v-if="invoiceForm.errors.amount" class="mt-1 text-xs text-red-600">{{ invoiceForm.errors.amount }}</div>
                <div class="mt-2"><PrimaryButton :disabled="invoiceForm.processing || Number(invoiceForm.amount) <= 0" @click="addInvoice">{{ $e('Создать счёт') }}</PrimaryButton></div>
            </div>
            <div class="space-y-2">
                <div v-for="inv in invoices" :key="inv.id" class="rounded-xl bg-slate-50 p-4 text-sm">
                    <div class="flex items-center justify-between">
                        <div>
                            <span class="font-medium text-slate-900">{{ inv.number }}</span>
                            <span class="ml-2 tabular-nums text-slate-500">{{ money(inv.amount) }}</span>
                            <span class="ml-2 rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-700">{{ $e('оплачено') }} {{ money(inv.payments_sum_amount ?? 0) }}</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <StatusBadge :status="inv.status" />
                            <button class="text-xs font-semibold text-indigo-600 transition-colors duration-150 hover:text-indigo-800" @click="openPay(inv)">{{ $e('Оплата') }}</button>
                            <button class="rounded p-1 text-slate-400 transition-colors duration-150 hover:text-rose-600" :title="$e('Удалить')" @click="delInvoice(inv)">
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6M10 11v6M14 11v6"/></svg>
                            </button>
                        </div>
                    </div>
                    <div v-if="payFor === inv.id" class="mt-2 flex items-end gap-2 border-t border-slate-200 pt-2">
                        <TextInput v-model="payForm.amount" type="number" step="0.01" class="w-32" />
                        <TextInput v-model="payForm.payment_date" type="date" class="w-40" />
                        <select v-model="payForm.payment_method" class="rounded-lg border-slate-300 text-sm transition duration-150 focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20">
                            <option value="bank">{{ $e('Банк') }}</option><option value="cash">{{ $e('Нал') }}</option><option value="card">{{ $e('Карта') }}</option><option value="other">{{ $e('Другое') }}</option>
                        </select>
                        <PrimaryButton :disabled="payForm.processing" @click="addPayment">{{ $e('ОК') }}</PrimaryButton>
                        <button class="text-sm text-slate-500 transition-colors duration-150 hover:text-slate-700" @click="payFor = null">{{ $e('Отмена') }}</button>
                    </div>
                </div>
                <div v-if="!invoices.length" class="flex flex-col items-center gap-3 py-8 text-center">
                    <svg class="h-10 w-10 text-slate-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M9 13h6M9 17h6"/></svg>
                    <span class="text-sm text-slate-400">{{ $e('Счетов нет') }}</span>
                    <button class="rounded-lg bg-indigo-600 px-4 py-2 text-xs font-semibold text-white transition-colors duration-150 hover:bg-indigo-700" @click="showInvoice = !showInvoice">{{ $e('+ Счёт') }}</button>
                </div>
            </div>
        </div>

        <!-- Expenses -->
        <div>
            <div class="mb-3 flex items-center justify-between">
                <h4 class="text-sm font-semibold text-slate-900">{{ $e('Расходы') }}</h4>
                <button class="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white transition-colors duration-150 hover:bg-indigo-700" @click="showExpense = !showExpense">{{ $e('+ Расход') }}</button>
            </div>
            <div v-if="showExpense" class="mb-3 rounded-xl border border-dashed border-slate-300 p-4">
                <!-- Тип расхода: прочий / доставка / закуп / материалы -->
                <div class="mb-3 flex flex-wrap gap-2">
                    <button v-for="m in [
                            { k: 'other', l: $e('Прочий расход (чек)') },
                            { k: 'delivery', l: $e('🚚 Доставка') },
                            { k: 'purchase', l: $e('📦 Закуп') },
                            { k: 'assembly', l: $e('🔧 Сборка') },
                        ]" :key="m.k" type="button" @click="expenseMode = m.k"
                        class="rounded-lg border px-3 py-1.5 text-xs font-semibold transition-all"
                        :class="expenseMode === m.k ? 'border-indigo-500 bg-indigo-50 text-indigo-700 ring-1 ring-indigo-500' : 'border-slate-200 text-slate-500 hover:border-slate-300'">
                        {{ m.l }}
                    </button>
                    <button v-if="materials.length" type="button" @click="expenseMode = 'material'"
                        class="rounded-lg border px-3 py-1.5 text-xs font-semibold transition-all"
                        :class="expenseMode === 'material' ? 'border-indigo-500 bg-indigo-50 text-indigo-700 ring-1 ring-indigo-500' : 'border-slate-200 text-slate-500 hover:border-slate-300'">
                        {{ $e('По материалам (со склада)') }}
                    </button>
                </div>

                <!-- Материал со склада -->
                <div v-if="expenseMode === 'material' && materials.length" class="mb-2 grid grid-cols-2 gap-2">
                    <div class="col-span-2">
                        <select v-model="expenseForm.material_id" class="w-full rounded-md border-slate-300 text-sm shadow-sm transition focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20">
                            <option value="">{{ $e('— материал со склада —') }}</option>
                            <option v-for="m in materials" :key="m.id" :value="m.id">{{ m.name }} {{ $e('· остаток') }} {{ qtyNum(m.quantity) }} {{ m.unit }}<template v-if="Number(m.price) > 0"> · {{ money(m.price) }}/{{ m.unit }}</template></option>
                        </select>
                        <div v-if="expenseForm.errors.material_id" class="mt-1 text-xs text-red-600">{{ expenseForm.errors.material_id }}</div>
                    </div>
                    <div>
                        <TextInput v-model="expenseForm.qty" type="number" min="0.01" step="any" :placeholder="$e('Количество')" class="w-full" />
                        <div v-if="expenseForm.errors.qty" class="mt-1 text-xs text-red-600">{{ expenseForm.errors.qty }}</div>
                    </div>
                    <div v-if="selectedMaterial" class="flex items-center text-xs"
                        :class="Number(expenseForm.qty) > Number(selectedMaterial.quantity) ? 'text-rose-600 font-semibold' : 'text-slate-500'">
                        {{ $e('Остаток:') }} {{ qtyNum(selectedMaterial.quantity) }} {{ selectedMaterial.unit }}
                        <template v-if="Number(expenseForm.qty) > 0"> → {{ qtyNum(Number(selectedMaterial.quantity) - Number(expenseForm.qty)) }}</template>
                    </div>
                    <!-- Сумма считается автоматически: количество × закупочная цена -->
                    <div v-if="selectedMaterial && materialPrice > 0" class="col-span-2 rounded-lg bg-indigo-50 px-3 py-2 text-sm text-indigo-700">
                        {{ $e('Сумма:') }} <span class="font-semibold tabular-nums">{{ money(autoAmount ?? 0) }}</span>
                        <span class="ml-1 text-xs text-indigo-400">({{ qtyNum(expenseForm.qty || 0) }} × {{ money(materialPrice) }})</span>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-2">
                    <TextInput v-if="expenseMode !== 'material' || !selectedMaterial || materialPrice <= 0"
                        v-model="expenseForm.amount" type="number" step="0.01"
                        :placeholder="expenseMode === 'material' ? $e('Сумма, ₸ (цена не заведена)') : $e('Сумма, ₸')" />
                    <TextInput v-model="expenseForm.date" type="date" />
                </div>
                <TextInput v-model="expenseForm.description" :placeholder="$e('Описание')" class="mt-2 w-full" />
                <!-- Способ оплаты: нал / банк — и для прочих, и для материальных -->
                <div class="mt-2 flex gap-2">
                    <button type="button" @click="expenseForm.payment_method = 'cash'"
                        class="rounded-lg border px-3 py-1.5 text-xs font-semibold transition-all"
                        :class="expenseForm.payment_method === 'cash' ? 'border-emerald-500 bg-emerald-100 text-emerald-700 ring-1 ring-emerald-500' : 'border-slate-200 bg-white text-slate-500 hover:border-slate-300'">{{ $e('Наличные') }}</button>
                    <button type="button" @click="expenseForm.payment_method = 'bank'"
                        class="rounded-lg border px-3 py-1.5 text-xs font-semibold transition-all"
                        :class="expenseForm.payment_method === 'bank' ? 'border-sky-500 bg-sky-100 text-sky-700 ring-1 ring-sky-500' : 'border-slate-200 bg-white text-slate-500 hover:border-slate-300'">{{ $e('Банк (счёт)') }}</button>
                </div>
                <!-- Остатки из поступлений (видит бухгалтер): расход списывается отсюда -->
                <div v-if="balances" class="mt-1.5 text-xs" :class="overBalance ? 'font-semibold text-rose-600' : 'text-slate-400'">
                    {{ $e('Доступно: касса') }} {{ money(balances.cash) }} {{ $e('· счёт') }} {{ money(balances.bank) }}
                    <template v-if="overBalance"> {{ $e('— расход превышает остаток') }} {{ expenseForm.payment_method === 'cash' ? $e('кассы') : $e('счёта') }}!</template>
                </div>
                <div v-if="expenseMode === 'other'" class="mt-2">
                    <label class="mb-1 block text-xs font-medium text-slate-500">{{ $e('Чек (фото или PDF) — можно прикрепить сейчас, иначе бухгалтер добавит при подтверждении') }}</label>
                    <input ref="receiptInput" type="file" accept="image/*,.pdf" @change="onReceipt"
                        class="block w-full text-sm text-slate-600 file:mr-3 file:rounded-md file:border-0 file:bg-indigo-50 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-indigo-700 hover:file:bg-indigo-100" />
                    <div v-if="expenseForm.errors.file" class="mt-1 text-xs text-red-600">{{ expenseForm.errors.file }}</div>
                </div>
                <div v-else class="mt-2 text-xs text-slate-400">{{ $e('Списание со склада — чек не требуется, остаток уменьшится автоматически.') }}</div>
                <div class="mt-2"><PrimaryButton :disabled="expenseForm.processing || !canSubmitExpense" @click="addExpense">{{ $e('Добавить расход') }}</PrimaryButton></div>
            </div>
            <div class="space-y-2">
                <div v-for="e in expenses" :key="e.id" class="rounded-xl bg-slate-50 p-4 text-sm">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <div>
                                <span class="font-medium tabular-nums text-slate-900">{{ money(e.amount) }}</span>
                                <span v-if="expenseTypeLabels[e.type]" class="ml-2 rounded bg-sky-50 px-1.5 py-0.5 text-xs font-semibold text-sky-700 ring-1 ring-sky-200">{{ expenseTypeLabels[e.type] }}</span>
                                <span class="ml-2 text-slate-500">{{ e.description }}</span>
                            </div>
                            <div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-slate-400">
                                <span v-if="e.material" class="rounded-full bg-indigo-100 px-2 py-0.5 font-medium text-indigo-700">{{ $e('склад:') }} {{ e.material.name }} × {{ qtyNum(e.qty) }} {{ e.material.unit }}</span>
                                <span v-if="e.payment_method" class="rounded-full bg-slate-200 px-2 py-0.5 font-medium text-slate-600">{{ e.payment_method === 'cash' ? $e('наличные') : $e('банк') }}</span>
                                <span v-if="e.responsible">{{ e.responsible.name }}</span>
                                <span>· {{ fmtDateTime(e.created_at) }}</span>
                                <button v-if="e.file_path" type="button" @click="openReceipt(e)"
                                    class="inline-flex items-center gap-1 font-medium text-indigo-600 transition-colors duration-150 hover:text-indigo-800">
                                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m21.44 11.05-9.19 9.19a6 6 0 0 1-8.49-8.49l8.57-8.57A4 4 0 1 1 18 8.84l-8.59 8.57a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
                                    {{ $e('Посмотреть чек') }}
                                </button>
                                <span v-else-if="!e.material" class="rounded-full bg-amber-100 px-2 py-0.5 font-medium text-amber-700">{{ $e('без чека') }}</span>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <span v-if="e.status === 'confirmed'" class="rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-semibold text-emerald-700">{{ $e('Подтверждён') }}</span>
                            <span v-else-if="e.status === 'pending'" class="rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-semibold text-amber-700">{{ $e('Ждёт бухгалтера') }}</span>
                            <span v-else class="rounded-full bg-slate-200 px-2.5 py-0.5 text-xs font-semibold text-slate-600">{{ $e('Черновик') }}</span>
                            <button v-if="canConfirm && e.status !== 'confirmed' && confirmFor !== e.id"
                                class="rounded-lg bg-emerald-600 px-2.5 py-1 text-xs font-semibold text-white transition-colors duration-150 hover:bg-emerald-700"
                                @click="openConfirm(e)">{{ $e('✓ Подтвердить') }}</button>
                            <!-- Удаляет расходы только бухгалтер: удаление
                                 двигает деньги и склад. Менеджеру кнопку не
                                 показываем — сервер всё равно откажет. -->
                            <button v-if="canConfirm" class="rounded p-1 text-slate-400 transition-colors duration-150 hover:text-rose-600" :title="$e('Удалить')" @click="delExpense(e)">
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6M10 11v6M14 11v6"/></svg>
                            </button>
                        </div>
                    </div>
                    <!-- Подтверждение бухгалтером: способ оплаты (касса) + чек -->
                    <div v-if="confirmFor === e.id" class="mt-3 rounded-lg border border-dashed border-emerald-300 bg-emerald-50/50 p-3">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-xs font-medium text-slate-500">{{ $e('Оплата:') }}</span>
                            <button type="button" @click="confirmForm.payment_method = 'cash'"
                                class="rounded-lg border px-3 py-1.5 text-xs font-semibold transition-all"
                                :class="confirmForm.payment_method === 'cash' ? 'border-emerald-500 bg-emerald-100 text-emerald-700 ring-1 ring-emerald-500' : 'border-slate-200 bg-white text-slate-500'">{{ $e('Наличные') }}</button>
                            <button type="button" @click="confirmForm.payment_method = 'bank'"
                                class="rounded-lg border px-3 py-1.5 text-xs font-semibold transition-all"
                                :class="confirmForm.payment_method === 'bank' ? 'border-emerald-500 bg-emerald-100 text-emerald-700 ring-1 ring-emerald-500' : 'border-slate-200 bg-white text-slate-500'">{{ $e('Банк (счёт)') }}</button>
                        </div>
                        <div v-if="!e.file_path" class="mt-2">
                            <label class="mb-1 block text-xs font-medium text-slate-500">{{ $e('Чек (обязателен для подтверждения)') }}</label>
                            <input ref="confirmInput" type="file" accept="image/*,.pdf" @change="onConfirmReceipt"
                                class="block w-full text-sm text-slate-600 file:mr-3 file:rounded-md file:border-0 file:bg-emerald-100 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-emerald-700 hover:file:bg-emerald-200" />
                        </div>
                        <div v-if="confirmForm.errors.file" class="mt-1 text-xs text-red-600">{{ confirmForm.errors.file }}</div>
                        <div v-if="confirmForm.errors.payment_method" class="mt-1 text-xs text-red-600">{{ confirmForm.errors.payment_method }}</div>
                        <div class="mt-2 flex gap-2">
                            <button class="rounded-lg bg-emerald-600 px-4 py-1.5 text-xs font-semibold text-white transition-colors duration-150 hover:bg-emerald-700 disabled:opacity-50"
                                :disabled="confirmForm.processing || (!e.file_path && !confirmForm.file)" @click="submitConfirm(e)">{{ $e('Подтвердить расход') }}</button>
                            <button class="rounded-lg bg-white px-4 py-1.5 text-xs font-medium text-slate-500 ring-1 ring-slate-200 transition-colors duration-150 hover:bg-slate-100" @click="confirmFor = null">{{ $e('Отмена') }}</button>
                        </div>
                    </div>
                </div>
                <div v-if="!expenses.length" class="flex flex-col items-center gap-3 py-8 text-center">
                    <svg class="h-10 w-10 text-slate-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/><path d="M6 12h.01M18 12h.01"/></svg>
                    <span class="text-sm text-slate-400">{{ $e('Расходов нет') }}</span>
                    <button class="rounded-lg bg-indigo-600 px-4 py-2 text-xs font-semibold text-white transition-colors duration-150 hover:bg-indigo-700" @click="showExpense = !showExpense">{{ $e('+ Расход') }}</button>
                </div>
            </div>
        </div>

        <!-- Просмотр чека -->
        <Modal :show="!!receiptView" max-width="2xl" @close="closeReceipt">
            <div class="bg-white">
                <div class="flex items-center justify-between border-b px-4 py-2">
                    <h4 class="font-semibold text-slate-700">{{ $e('Чек') }}</h4>
                    <div class="flex items-center gap-3">
                        <a :href="receiptView?.url" target="_blank" class="text-sm font-medium text-indigo-600 transition-colors duration-150 hover:text-indigo-800">{{ $e('Открыть в новой вкладке') }}</a>
                        <button type="button" class="rounded p-1 text-slate-400 transition-colors duration-150 hover:text-slate-600" @click="closeReceipt">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                        </button>
                    </div>
                </div>
                <div class="max-h-[75vh] overflow-auto p-3 text-center">
                    <img v-if="receiptView?.image" :src="receiptView.url" :alt="$e('Чек')" class="mx-auto max-w-full rounded" />
                    <iframe v-else :src="receiptView?.url" class="h-[70vh] w-full rounded border" :title="$e('Чек')"></iframe>
                </div>
            </div>
        </Modal>
    </div>
</template>
