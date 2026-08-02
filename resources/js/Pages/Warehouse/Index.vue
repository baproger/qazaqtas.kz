<script setup>
import { ref, computed } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import { confirmDialog } from '@/composables/useConfirm';
import { formatDate, formatDateTime } from '@/utils/format';

const props = defineProps({
    materials: Array, writeoffs: Object, receipts: Array, units: Array,
    canManage: Boolean, allMode: Boolean, companyName: String, filters: Object,
});

// Детали списания: клик по колонке «Списание» — на какие сделки/заказы ушло.
const writeoffFor = ref(null); // материал, чьи списания открыты
const writeoffRows = computed(() => writeoffFor.value ? (props.writeoffs?.[writeoffFor.value.id] ?? []) : []);
const writeoffLink = (w) => w.type === 'deal' ? route('deals.show', w.target_id) : route('projects.show', w.target_id);

const qty = (v) => new Intl.NumberFormat('ru-RU').format(Number(v ?? 0));
const money = (v) => new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 2 }).format(Number(v ?? 0)) + ' ₸';

// Приход: существующий материал или новая позиция.
const showModal = ref(false);
const mode = ref('existing'); // existing | new
const form = useForm({ material_id: '', name: '', unit: 'штук', quantity: '', price: '', date: '', note: '' });
const openReceipt = () => {
    form.reset(); form.unit = 'штук';
    mode.value = props.materials.length ? 'existing' : 'new';
    showModal.value = true;
};
const submit = () => {
    const payload = mode.value === 'existing'
        ? { material_id: form.material_id, name: '', unit: '' }
        : { material_id: '', name: form.name, unit: form.unit };
    form.transform((d) => ({ ...d, ...payload }))
        .post(route('warehouse.receipt'), { preserveScroll: true, onSuccess: () => (showModal.value = false) });
};
const removeMaterial = async (m) => {
    if (await confirmDialog({ title: 'Удалить позицию', message: `«${m.name}» и вся история прихода будут удалены.`, confirmText: 'Удалить', danger: true })) {
        router.delete(route('warehouse.materials.destroy', m.id), { preserveScroll: true });
    }
};

// Правка/удаление прихода (бухгалтер/админ) — остаток пересчитывается на сервере.
const editingReceipt = ref(null);
const receiptForm = useForm({ quantity: '', price: '', date: '', note: '' });
const openEditReceipt = (r) => {
    editingReceipt.value = r.id;
    receiptForm.quantity = Number(r.quantity);
    receiptForm.price = r.price != null ? Number(r.price) : '';
    receiptForm.date = (r.date ?? '').slice(0, 10);
    receiptForm.note = r.note ?? '';
    receiptForm.clearErrors();
};
const saveReceipt = (r) => receiptForm.put(route('warehouse.receipts.update', r.id), {
    preserveScroll: true, onSuccess: () => (editingReceipt.value = null),
});
const removeReceipt = async (r) => {
    if (await confirmDialog({ title: 'Удалить приход', message: `Приход «+${r.quantity} ${r.material?.unit ?? ''} ${r.material?.name ?? ''}» будет удалён, остаток уменьшится.`, confirmText: 'Удалить', danger: true })) {
        router.delete(route('warehouse.receipts.destroy', r.id), { preserveScroll: true });
    }
};

// Фильтры: поиск / ед.изм / остаток — клиентские (данные уже загружены);
// период поступления — серверный (суммы считаются в контроллере).
const search = ref('');
const fUnit = ref('');
const fStock = ref(''); // '' все | 'in' в наличии | 'zero' на нуле
const fFrom = ref(props.filters?.from ?? '');
const fTo = ref(props.filters?.to ?? '');
const applyPeriod = () => router.get(route('warehouse.index'), {
    from: fFrom.value || undefined, to: fTo.value || undefined,
}, { preserveState: true, preserveScroll: true, replace: true });
const hasFilters = computed(() => search.value || fUnit.value || fStock.value || fFrom.value || fTo.value);
const resetFilters = () => { search.value = ''; fUnit.value = ''; fStock.value = ''; fFrom.value = ''; fTo.value = ''; applyPeriod(); };
// Ед. изм. в фильтре — только реально имеющиеся на складе.
const unitOptions = computed(() => [...new Set(props.materials.map((m) => m.unit).filter(Boolean))]);
const filtered = computed(() => {
    const s = search.value.trim().toLowerCase();
    return props.materials
        .filter((m) => !s || m.name.toLowerCase().includes(s))
        .filter((m) => !fUnit.value || m.unit === fUnit.value)
        .filter((m) => !fStock.value || (fStock.value === 'zero' ? Number(m.quantity) <= 0 : Number(m.quantity) > 0));
});
const lowStock = (m) => Number(m.quantity) <= 0;
</script>

<template>
    <Head title="Склад" />
    <AppLayout>
        <template #header>
            <div class="flex items-center gap-3">
                <span>{{ $t('page.warehouse', 'Склад') }}</span>
                <span class="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-semibold text-slate-500">{{ companyName }}</span>
            </div>
        </template>

        <div class="mb-4 flex justify-end">
            <PrimaryButton v-if="canManage" @click="openReceipt">+ Приход товара</PrimaryButton>
        </div>

        <!-- Фильтры: поиск, ед.изм, остаток, период поступления -->
        <div class="mb-4 flex flex-wrap items-center gap-2 rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
            <div class="relative w-full sm:w-56">
                <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
                <input v-model="search" type="text" placeholder="Поиск материала…"
                    class="w-full rounded-lg border-slate-200 py-1.5 pl-9 pr-3 text-sm shadow-sm transition focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20" />
            </div>
            <select v-model="fUnit" class="w-full rounded-lg border-slate-200 py-1.5 text-sm text-slate-600 shadow-sm focus:border-indigo-400 focus:ring-indigo-400 sm:w-auto">
                <option value="">Все ед. изм.</option>
                <option v-for="u in unitOptions" :key="u" :value="u">{{ u }}</option>
            </select>
            <select v-model="fStock" class="w-full rounded-lg border-slate-200 py-1.5 text-sm text-slate-600 shadow-sm focus:border-indigo-400 focus:ring-indigo-400 sm:w-auto">
                <option value="">Все остатки</option>
                <option value="in">В наличии</option>
                <option value="zero">На нуле</option>
            </select>
            <label class="flex items-center gap-1 text-xs text-slate-400">поступление с
                <input v-model="fFrom" @change="applyPeriod" type="date" class="rounded-lg border-slate-200 py-1.5 text-xs shadow-sm" />
            </label>
            <label class="flex items-center gap-1 text-xs text-slate-400">по
                <input v-model="fTo" @change="applyPeriod" type="date" class="rounded-lg border-slate-200 py-1.5 text-xs shadow-sm" />
            </label>
            <button v-if="hasFilters" type="button" @click="resetFilters"
                class="rounded-lg px-2.5 py-1.5 text-xs font-medium text-slate-400 transition hover:bg-slate-100 hover:text-slate-600">Сбросить ✕</button>
            <span class="ml-auto hidden text-[11px] tabular-nums text-slate-300 lg:block">найдено: {{ filtered.length }}</span>
        </div>

        <!-- Остатки -->
        <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-card">
            <table class="min-w-full divide-y divide-slate-100 text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-400">
                    <tr>
                        <th class="px-6 py-3">Материал</th>
                        <th v-if="allMode" class="px-4 py-3">Компания</th>
                        <th class="px-4 py-3">Ед. изм.</th>
                        <th class="px-4 py-3 text-right">Цена за ед.</th>
                        <th class="px-4 py-3 text-right">Поступление</th>
                        <th class="px-4 py-3 text-right">Сумма</th>
                        <th class="px-4 py-3 text-right">Списание</th>
                        <th class="px-4 py-3 text-right">Остаток</th>
                        <th v-if="canManage" class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    <tr v-for="m in filtered" :key="m.id" class="transition-colors hover:bg-slate-50">
                        <td class="px-6 py-3 font-medium text-slate-900">{{ m.name }}<span v-if="m.note" class="ml-2 text-xs text-slate-400">{{ m.note }}</span></td>
                        <td v-if="allMode" class="px-4 py-3 text-slate-500">{{ m.company?.name ?? '—' }}</td>
                        <td class="px-4 py-3 text-slate-500">{{ m.unit }}</td>
                        <td class="px-4 py-3 text-right tabular-nums text-slate-600">
                            <template v-if="Number(m.price) > 0">{{ money(m.price) }}</template>
                            <span v-else class="text-slate-300">—</span>
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums text-emerald-600">
                            <template v-if="Number(m.received_qty) > 0">+ {{ qty(m.received_qty) }}</template>
                            <span v-else class="text-slate-300">—</span>
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums font-medium text-slate-700">
                            <template v-if="Number(m.received_sum) > 0">{{ money(m.received_sum) }}</template>
                            <span v-else class="text-slate-300">—</span>
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums">
                            <button v-if="Number(m.written_off_qty) > 0" type="button" @click="writeoffFor = m"
                                class="font-medium text-rose-500 underline decoration-rose-200 decoration-dashed underline-offset-4 transition hover:text-rose-700"
                                title="Показать, на какие сделки списано">− {{ qty(m.written_off_qty) }}</button>
                            <span v-else class="text-slate-300">—</span>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <span class="rounded-full px-2.5 py-0.5 text-sm font-bold tabular-nums"
                                :class="lowStock(m) ? 'bg-rose-100 text-rose-700' : 'bg-emerald-100 text-emerald-700'">
                                {{ qty(m.quantity) }}
                            </span>
                        </td>
                        <td v-if="canManage" class="px-4 py-3 text-right">
                            <button class="text-slate-300 transition-colors hover:text-rose-600" title="Удалить позицию" @click="removeMaterial(m)">
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
                            </button>
                        </td>
                    </tr>
                    <tr v-if="!filtered.length">
                        <td :colspan="canManage ? (allMode ? 10 : 9) : (allMode ? 9 : 8)" class="px-6 py-12 text-center">
                            <svg class="mx-auto h-10 w-10 text-slate-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M21 8 12 3 3 8v8l9 5 9-5z"/><path d="M3 8l9 5 9-5M12 13v8"/></svg>
                            <p class="mt-3 text-sm text-slate-400">Склад пуст — оформите первый приход товара</p>
                            <PrimaryButton v-if="canManage" class="mt-4" @click="openReceipt">+ Приход товара</PrimaryButton>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- История прихода -->
        <div v-if="receipts.length" class="mt-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-card">
            <h3 class="mb-4 text-sm font-semibold text-slate-700">Последние приходы</h3>
            <div class="divide-y divide-slate-50">
                <div v-for="r in receipts" :key="r.id" class="py-2.5 text-sm">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-bold text-emerald-700">+ {{ qty(r.quantity) }} {{ r.material?.unit }}</span>
                        <span class="font-medium text-slate-800">{{ r.material?.name }}</span>
                        <span v-if="Number(r.price) > 0" class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium tabular-nums text-slate-600">{{ money(r.price) }}/{{ r.material?.unit }}</span>
                        <span v-if="r.note" class="text-xs text-slate-400">{{ r.note }}</span>
                        <span class="ml-auto text-xs text-slate-400">{{ r.user?.name ?? '—' }} · {{ formatDate(r.date) }} · внесено {{ formatDateTime(r.created_at) }}</span>
                        <template v-if="canManage">
                            <button class="rounded p-1 text-slate-300 transition-colors hover:text-indigo-600" title="Редактировать приход" @click="openEditReceipt(r)">
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>
                            </button>
                            <button class="rounded p-1 text-slate-300 transition-colors hover:text-rose-600" title="Удалить приход" @click="removeReceipt(r)">
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
                            </button>
                        </template>
                    </div>
                    <!-- Инлайн-правка прихода -->
                    <div v-if="editingReceipt === r.id" class="mt-2 rounded-lg border border-dashed border-indigo-300 bg-indigo-50/40 p-3">
                        <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                            <div>
                                <InputLabel value="Количество" />
                                <TextInput v-model="receiptForm.quantity" type="number" min="0.01" step="any" class="mt-1 w-full" />
                            </div>
                            <div>
                                <InputLabel value="Цена за ед., ₸" />
                                <TextInput v-model="receiptForm.price" type="number" min="0" step="0.01" class="mt-1 w-full" />
                            </div>
                            <div>
                                <InputLabel value="Дата" />
                                <TextInput v-model="receiptForm.date" type="date" class="mt-1 w-full" />
                            </div>
                            <div>
                                <InputLabel value="Заметка" />
                                <TextInput v-model="receiptForm.note" class="mt-1 w-full" />
                            </div>
                        </div>
                        <InputError :message="receiptForm.errors.quantity || receiptForm.errors.price" class="mt-1" />
                        <div class="mt-2 flex gap-2">
                            <PrimaryButton :disabled="receiptForm.processing" @click="saveReceipt(r)">Сохранить</PrimaryButton>
                            <SecondaryButton @click="editingReceipt = null">Отмена</SecondaryButton>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Модалка прихода -->
        <Modal :show="showModal" @close="showModal = false" max-width="lg">
            <div class="p-6">
                <h2 class="mb-4 text-lg font-semibold">Приход товара</h2>
                <div v-if="materials.length" class="mb-4 flex gap-2">
                    <button type="button" @click="mode = 'existing'"
                        class="rounded-lg border px-4 py-2 text-sm font-semibold transition-all"
                        :class="mode === 'existing' ? 'border-indigo-500 bg-indigo-50 text-indigo-700 ring-1 ring-indigo-500' : 'border-slate-200 text-slate-500 hover:border-slate-300'">
                        Существующий материал
                    </button>
                    <button type="button" @click="mode = 'new'"
                        class="rounded-lg border px-4 py-2 text-sm font-semibold transition-all"
                        :class="mode === 'new' ? 'border-indigo-500 bg-indigo-50 text-indigo-700 ring-1 ring-indigo-500' : 'border-slate-200 text-slate-500 hover:border-slate-300'">
                        Новая позиция
                    </button>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div v-if="mode === 'existing' && materials.length" class="col-span-2">
                        <InputLabel value="Материал" />
                        <select v-model="form.material_id" class="mt-1 w-full rounded-md border-slate-300 shadow-sm transition focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20">
                            <option value="">— выберите —</option>
                            <option v-for="m in materials" :key="m.id" :value="m.id">{{ m.name }} (остаток {{ qty(m.quantity) }} {{ m.unit }})</option>
                        </select>
                        <InputError :message="form.errors.material_id" class="mt-1" />
                    </div>
                    <template v-else>
                        <div class="col-span-2 sm:col-span-1">
                            <InputLabel value="Название материала *" />
                            <TextInput v-model="form.name" class="mt-1 w-full" placeholder="ЛДСП 16мм белый" />
                            <InputError :message="form.errors.name" class="mt-1" />
                        </div>
                        <div class="col-span-2 sm:col-span-1">
                            <InputLabel value="Ед. изм." />
                            <select v-model="form.unit" class="mt-1 w-full rounded-md border-slate-300 shadow-sm transition focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20">
                                <option v-for="u in units" :key="u" :value="u">{{ u }}</option>
                            </select>
                        </div>
                    </template>
                    <div>
                        <InputLabel value="Количество *" />
                        <TextInput v-model="form.quantity" type="number" min="0.01" step="any" class="mt-1 w-full" />
                        <InputError :message="form.errors.quantity" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel value="Цена за ед., ₸" />
                        <TextInput v-model="form.price" type="number" min="0" step="0.01" class="mt-1 w-full" placeholder="Закупочная цена" />
                        <InputError :message="form.errors.price" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel value="Дата" />
                        <TextInput v-model="form.date" type="date" class="mt-1 w-full" />
                    </div>
                    <div>
                        <InputLabel value="Заметка" />
                        <TextInput v-model="form.note" class="mt-1 w-full" placeholder="Поставщик, накладная…" />
                    </div>
                </div>
                <div class="mt-6 flex justify-end gap-2">
                    <SecondaryButton @click="showModal = false">Отмена</SecondaryButton>
                    <PrimaryButton :disabled="form.processing" @click="submit">Оформить приход</PrimaryButton>
                </div>
            </div>
        </Modal>

        <!-- Детали списания: на какие сделки/заказы ушёл материал -->
        <Modal :show="!!writeoffFor" @close="writeoffFor = null" max-width="lg">
            <div class="p-6">
                <h2 class="mb-1 text-lg font-semibold text-slate-900">Списания — {{ writeoffFor?.name }}</h2>
                <p class="mb-4 text-xs text-slate-400">Клик по строке — переход в сделку / заказ цеха</p>
                <div class="max-h-80 divide-y divide-slate-50 overflow-y-auto">
                    <button v-for="(w, i) in writeoffRows" :key="i" type="button" @click="w.target_id && router.get(writeoffLink(w))"
                        class="flex w-full items-center justify-between gap-3 rounded-lg px-2 py-2.5 text-left text-sm transition"
                        :class="w.target_id ? 'hover:bg-indigo-50/60' : 'cursor-default opacity-60'">
                        <div class="min-w-0">
                            <div class="truncate font-medium" :class="w.target_id ? 'text-slate-800' : 'text-slate-400 line-through'">{{ w.label }}</div>
                            <div class="text-[11px] text-slate-400"><template v-if="w.number">{{ w.number }} · </template>{{ w.type === 'deal' ? 'сделка' : 'заказ цеха' }} · {{ w.date ? formatDate(w.date) : '—' }} · внесено {{ formatDateTime(w.created_at) }}</div>
                        </div>
                        <div class="text-right">
                            <div class="font-semibold tabular-nums text-rose-600">− {{ qty(w.qty) }} {{ writeoffFor?.unit }}</div>
                            <div class="text-[11px] tabular-nums text-slate-400">{{ money(w.amount) }}</div>
                        </div>
                    </button>
                    <div v-if="!writeoffRows.length" class="py-6 text-center text-sm text-slate-400">Списаний нет</div>
                </div>
                <div class="mt-4 flex justify-end"><SecondaryButton @click="writeoffFor = null">Закрыть</SecondaryButton></div>
            </div>
        </Modal>
    </AppLayout>
</template>
