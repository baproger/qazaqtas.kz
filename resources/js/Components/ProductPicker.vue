<script setup>
/**
 * Выбор товаров заказа: сначала категории, затем товары этих категорий,
 * затем — строка на каждый товар со своим количеством и ценой.
 *
 * Единицу менеджер не выбирает: она приходит из каталога (брусчатка — м²,
 * урна — штук). Цена подставляется оттуда же, но правится в строке — скидку
 * дают заказу, а не переоценивают товар в каталоге.
 */
import { computed, ref } from 'vue';
import { money } from '@/utils/format';
import { useE } from '@/composables/useTranslations';

const tr = useE();

const props = defineProps({
    // Строки: [{ product_id, name, unit, quantity, price }]
    modelValue: { type: Array, default: () => [] },
    catalog: { type: Array, default: () => [] },
    categories: { type: Array, default: () => [] },
    errors: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['update:modelValue']);

const openCategories = ref([]);
const search = ref('');

const toggleCategory = (id) => {
    openCategories.value = openCategories.value.includes(id)
        ? openCategories.value.filter((c) => c !== id)
        : [...openCategories.value, id];
};

// Товары показываются только по выбранным категориям — иначе список каталога
// на сотни позиций, и найти в нём нужное дольше, чем спросить у клиента.
const visibleProducts = computed(() => {
    const q = search.value.trim().toLowerCase();

    return props.catalog.filter((p) => {
        if (openCategories.value.length && !openCategories.value.includes(p.category_id)) return false;
        if (!openCategories.value.length && !q) return false;

        return !q || p.name.toLowerCase().includes(q);
    });
});

const chosenIds = computed(() => props.modelValue.map((r) => r.product_id));
const isChosen = (id) => chosenIds.value.includes(id);

const toggleProduct = (product) => {
    if (isChosen(product.id)) {
        emit('update:modelValue', props.modelValue.filter((r) => r.product_id !== product.id));

        return;
    }
    emit('update:modelValue', [...props.modelValue, {
        product_id: product.id,
        name: product.name,
        unit: product.unit,
        quantity: '',
        price: product.price,
    }]);
};

const updateRow = (index, field, value) => {
    const rows = props.modelValue.map((r, i) => (i === index ? { ...r, [field]: value } : r));
    emit('update:modelValue', rows);
};
const removeRow = (index) => emit('update:modelValue', props.modelValue.filter((_, i) => i !== index));

const lineTotal = (row) => Number(row.quantity || 0) * Number(row.price || 0);
const total = computed(() => props.modelValue.reduce((sum, r) => sum + lineTotal(r), 0));
</script>

<template>
    <div class="rounded-xl border border-slate-200 bg-slate-50/60 p-4">
        <!-- 1. Категории: множественный выбор -->
        <div class="mb-1 text-xs font-medium text-slate-500">{{ $e('1. Категории') }}</div>
        <div class="flex flex-wrap gap-1.5">
            <button v-for="c in categories" :key="c.id" type="button" @click="toggleCategory(c.id)"
                class="rounded-full border px-3 py-1 text-xs font-medium transition-colors duration-150"
                :class="openCategories.includes(c.id)
                    ? 'border-indigo-500 bg-indigo-50 text-indigo-700'
                    : 'border-slate-200 bg-white text-slate-500 hover:bg-slate-100'">{{ c.name }}</button>
            <span v-if="!categories.length" class="text-xs text-slate-400">{{ $e('В каталоге пока нет товаров') }}</span>
        </div>

        <!-- 2. Товары выбранных категорий: множественный выбор -->
        <div class="mt-3 flex items-center justify-between gap-2">
            <div class="text-xs font-medium text-slate-500">{{ $e('2. Товары') }}</div>
            <input v-model="search" type="search" :placeholder="$e('поиск по названию…')"
                class="w-44 rounded-lg border-slate-300 py-1 text-xs shadow-sm" />
        </div>
        <div v-if="visibleProducts.length" class="mt-1.5 flex max-h-40 flex-wrap gap-1.5 overflow-y-auto">
            <button v-for="p in visibleProducts" :key="p.id" type="button" @click="toggleProduct(p)"
                class="rounded-lg border px-2.5 py-1 text-xs transition-colors duration-150"
                :class="isChosen(p.id)
                    ? 'border-emerald-500 bg-emerald-50 text-emerald-700'
                    : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-100'">
                {{ isChosen(p.id) ? '✓ ' : '' }}{{ p.name }}
                <span class="text-slate-400">· {{ p.unit || $e('ед') }}</span>
            </button>
        </div>
        <p v-else class="mt-1.5 text-xs text-slate-400">{{ $e('Выберите категорию — появятся её товары.') }}</p>

        <!-- 3. Строки заказа: у каждой своя единица из каталога -->
        <div v-if="modelValue.length" class="mt-4">
            <div class="mb-1 text-xs font-medium text-slate-500">{{ $e('3. Количество и цена') }}</div>
            <div class="space-y-2">
                <div v-for="(row, i) in modelValue" :key="row.product_id ?? i"
                    class="flex flex-wrap items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2">
                    <span class="min-w-0 flex-1 truncate text-sm text-slate-700">{{ row.name }}</span>

                    <label class="flex items-center gap-1 text-xs text-slate-400">
                        <input :value="row.quantity" @input="updateRow(i, 'quantity', $event.target.value)"
                            type="number" min="0" step="any" :placeholder="$e('кол-во')"
                            class="w-24 rounded-md border-slate-300 py-1 text-sm shadow-sm" />
                        <!-- Единица из каталога: менеджер её не выбирает -->
                        <span class="w-12 font-medium text-slate-500">{{ row.unit || $e('ед') }}</span>
                    </label>

                    <label class="flex items-center gap-1 text-xs text-slate-400">
                        {{ $e('цена') }}
                        <input :value="row.price" @input="updateRow(i, 'price', $event.target.value)"
                            type="number" min="0" step="any"
                            class="w-28 rounded-md border-slate-300 py-1 text-sm shadow-sm" />
                    </label>

                    <span class="w-28 text-right text-sm font-semibold tabular-nums text-slate-800">{{ money(lineTotal(row)) }}</span>
                    <button type="button" @click="removeRow(i)"
                        class="rounded p-1 text-slate-300 transition-colors hover:text-rose-600" :title="$e('Убрать товар')">✕</button>
                </div>
            </div>

            <div class="mt-2 flex flex-wrap items-center justify-end gap-4 text-sm">
                <span class="text-slate-500">
                    {{ $e('Сумма заказа:') }} <b class="text-base tabular-nums text-slate-900">{{ money(total) }}</b>
                </span>
            </div>
            <p class="mt-1 text-right text-[11px] text-slate-400">{{ $e('Сумма считается по строкам — вручную её не вводят.') }}</p>
        </div>
    </div>
</template>
