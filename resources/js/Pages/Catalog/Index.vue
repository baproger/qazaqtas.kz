<script setup>
import { computed, ref, watch } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import { confirmDialog } from '@/composables/useConfirm';

const props = defineProps({ products: Object, categories: Array, filters: Object, units: Array });

const money = (v) => new Intl.NumberFormat('ru-RU').format(Math.round(v ?? 0)) + ' ₸';
const search = ref(props.filters?.search ?? '');
const category = ref(props.filters?.category ?? '');
let timer = null;

const apply = () => router.get(route('catalog.index'), {
    search: search.value || undefined,
    category: category.value || undefined,
}, { preserveState: true, preserveScroll: true, replace: true });

watch(search, () => { clearTimeout(timer); timer = setTimeout(apply, 350); });
watch(category, apply);

// ---- Карточка товара ----
const showForm = ref(false);
const editingId = ref(null);
const specsText = ref('');
const colorsText = ref('');

const form = useForm({
    category_id: '', name: '', slug: '', code: '', unit: 'м²', price: '', old_price: '',
    min_order: 1, short_description: '', description: '',
    specs: {}, colors: [], images: [], documents: [],
    is_active: true, is_featured: false, in_stock: true, order: 0,
});

const openCreate = () => {
    editingId.value = null;
    form.reset();
    form.clearErrors();
    form.category_id = props.categories[0]?.id ?? '';
    specsText.value = 'size: 300 × 300 × 60 мм\npieces_per_m2: 11.1\nfrost: F200';
    colorsText.value = 'Мрамор белый #E8E6E1\nСерый графит #8A8D91';
    showForm.value = true;
};

const openEdit = (p) => {
    editingId.value = p.id;
    form.clearErrors();
    Object.assign(form, {
        category_id: p.category_id ?? '', name: p.name, slug: p.slug ?? '', code: p.code ?? '',
        unit: p.unit, price: Number(p.price), old_price: p.old_price ? Number(p.old_price) : '',
        min_order: Number(p.min_order), short_description: p.short_description ?? '',
        description: p.description ?? '', images: p.images ?? [], documents: p.documents ?? [],
        is_active: !!p.is_active, is_featured: !!p.is_featured, in_stock: !!p.in_stock, order: p.order ?? 0,
    });
    specsText.value = Object.entries(p.specs ?? {}).map(([k, v]) => `${k}: ${v}`).join('\n');
    colorsText.value = (p.colors ?? []).map((c) => `${c.name} ${c.hex}`).join('\n');
    showForm.value = true;
};

/** «ключ: значение» построчно → объект характеристик. */
const parseSpecs = () => Object.fromEntries(
    specsText.value.split('\n').map((l) => l.split(':')).filter((p) => p.length >= 2)
        .map(([k, ...rest]) => [k.trim(), rest.join(':').trim()]),
);

/** «Название #HEX» построчно → палитра. */
const parseColors = () => colorsText.value.split('\n').map((line) => {
    const m = line.trim().match(/^(.+?)\s+(#[0-9a-fA-F]{3,8})$/);
    return m ? { name: m[1].trim(), hex: m[2] } : null;
}).filter(Boolean);

const submit = () => {
    form.specs = parseSpecs();
    form.colors = parseColors();
    const opts = { preserveScroll: true, onSuccess: () => (showForm.value = false) };
    editingId.value ? form.put(route('catalog.update', editingId.value), opts) : form.post(route('catalog.store'), opts);
};

const remove = async (p) => {
    if (!(await confirmDialog({ title: `Удалить «${p.name}»?`, message: 'Позиция исчезнет из каталога на сайте.', confirmText: 'Удалить', danger: true }))) return;
    router.delete(route('catalog.destroy', p.id), { preserveScroll: true });
};

// ---- Категории ----
const showCats = ref(false);
const catForm = useForm({ name: '', slug: '', tagline: '', description: '', accent: '#C8B79A', order: 0, is_active: true });
const editingCat = ref(null);

const saveCat = () => {
    const opts = { preserveScroll: true, onSuccess: () => { catForm.reset(); editingCat.value = null; } };
    editingCat.value
        ? catForm.put(route('catalogCategories.update', editingCat.value), opts)
        : catForm.post(route('catalogCategories.store'), opts);
};

const editCat = (c) => {
    editingCat.value = c.id;
    Object.assign(catForm, { name: c.name, slug: c.slug, tagline: c.tagline ?? '', description: c.description ?? '', accent: c.accent ?? '#C8B79A', order: c.order, is_active: !!c.is_active });
};

const removeCat = async (c) => {
    if (!(await confirmDialog({ title: `Удалить категорию «${c.name}»?`, confirmText: 'Удалить', danger: true }))) return;
    router.delete(route('catalogCategories.destroy', c.id), { preserveScroll: true });
};

const categoryName = (id) => props.categories.find((c) => c.id === id)?.name ?? '—';
</script>

<template>
    <AppLayout>
        <template #header>Каталог сайта</template>

        <div class="mb-4 flex flex-wrap items-center gap-2">
            <PrimaryButton @click="openCreate">+ Позиция</PrimaryButton>
            <button class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-medium text-slate-600 shadow-sm transition hover:bg-slate-50" @click="showCats = true">
                ⚙ Категории
            </button>
            <a :href="route('site.catalog')" target="_blank" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-medium text-indigo-600 shadow-sm transition hover:bg-slate-50">
                Открыть витрину ↗
            </a>

            <div class="ml-auto flex flex-wrap items-center gap-2">
                <input v-model="search" type="search" placeholder="Название или артикул…" class="w-56 rounded-lg border-slate-200 py-2 text-sm shadow-sm" />
                <select v-model="category" class="rounded-lg border-slate-200 py-2 text-sm text-slate-600 shadow-sm">
                    <option value="">Все категории</option>
                    <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }} ({{ c.products_count }})</option>
                </select>
            </div>
        </div>

        <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-100 text-sm">
                    <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-400">
                        <tr>
                            <th class="px-4 py-2.5">Позиция</th>
                            <th class="px-4 py-2.5">Категория</th>
                            <th class="px-4 py-2.5">Артикул</th>
                            <th class="px-4 py-2.5 text-right">Цена</th>
                            <th class="px-4 py-2.5 text-center">На сайте</th>
                            <th class="px-4 py-2.5"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        <tr v-for="p in products.data" :key="p.id" class="hover:bg-slate-50">
                            <td class="px-4 py-3">
                                <span class="font-medium text-slate-800">{{ p.name }}</span>
                                <span class="block text-xs text-slate-400">{{ p.short_description }}</span>
                            </td>
                            <td class="px-4 py-3 text-slate-500">{{ p.category?.name ?? categoryName(p.category_id) }}</td>
                            <td class="px-4 py-3 text-slate-400">{{ p.code ?? '—' }}</td>
                            <td class="px-4 py-3 text-right font-semibold tabular-nums text-slate-900">{{ money(p.price) }} <span class="text-xs font-normal text-slate-400">/ {{ p.unit }}</span></td>
                            <td class="px-4 py-3 text-center">
                                <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold" :class="p.is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-500'">
                                    {{ p.is_active ? 'опубликована' : 'скрыта' }}
                                </span>
                                <span v-if="p.is_featured" class="ml-1 text-amber-500" title="Показывается на главной">★</span>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right">
                                <button class="rounded p-1 text-slate-300 transition hover:text-indigo-600" title="Изменить" @click="openEdit(p)">
                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>
                                </button>
                                <button class="rounded p-1 text-slate-300 transition hover:text-rose-600" title="Удалить" @click="remove(p)">✕</button>
                            </td>
                        </tr>
                        <tr v-if="!products.data.length"><td colspan="6" class="px-6 py-12 text-center text-slate-400">Каталог пуст — «+ Позиция»</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div v-if="products.last_page > 1" class="mt-4 flex flex-wrap justify-center gap-1.5">
            <Link v-for="link in products.links" :key="link.label" :href="link.url ?? ''" preserve-scroll
                class="min-w-9 rounded-lg border px-2.5 py-1.5 text-center text-sm"
                :class="[link.active ? 'border-indigo-500 bg-indigo-500 text-white' : 'border-slate-200 text-slate-600 hover:bg-slate-50', !link.url && 'pointer-events-none opacity-40']"
                v-html="link.label" />
        </div>

        <!-- Форма позиции -->
        <Modal :show="showForm" max-width="2xl" @close="showForm = false">
            <div class="p-6">
                <h3 class="mb-4 text-base font-semibold text-slate-900">{{ editingId ? 'Изменить позицию' : 'Новая позиция каталога' }}</h3>

                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div>
                        <InputLabel value="Категория" />
                        <select v-model="form.category_id" class="mt-1 w-full rounded-lg border-slate-300 text-sm shadow-sm">
                            <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
                        </select>
                    </div>
                    <div><InputLabel value="Артикул" /><TextInput v-model="form.code" class="mt-1 w-full" /></div>
                    <div class="sm:col-span-2">
                        <InputLabel value="Название *" /><TextInput v-model="form.name" class="mt-1 w-full" />
                        <InputError :message="form.errors.name" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel value="Единица измерения" />
                        <select v-model="form.unit" class="mt-1 w-full rounded-lg border-slate-300 text-sm shadow-sm">
                            <option v-for="u in units" :key="u" :value="u">{{ u }}</option>
                        </select>
                    </div>
                    <div><InputLabel value="Цена, ₸ *" /><TextInput v-model="form.price" type="number" min="0" class="mt-1 w-full" /><InputError :message="form.errors.price" class="mt-1" /></div>
                    <div><InputLabel value="Старая цена" /><TextInput v-model="form.old_price" type="number" min="0" class="mt-1 w-full" /></div>
                    <div><InputLabel value="Минимальный заказ" /><TextInput v-model="form.min_order" type="number" min="0" step="any" class="mt-1 w-full" /></div>
                    <div class="sm:col-span-2"><InputLabel value="Короткое описание" /><TextInput v-model="form.short_description" class="mt-1 w-full" /></div>
                    <div class="sm:col-span-2">
                        <InputLabel value="Описание" />
                        <textarea v-model="form.description" rows="3" class="mt-1 w-full rounded-lg border-slate-300 text-sm shadow-sm"></textarea>
                    </div>
                    <div>
                        <InputLabel value="Характеристики (ключ: значение)" />
                        <textarea v-model="specsText" rows="5" class="mt-1 w-full rounded-lg border-slate-300 font-mono text-xs shadow-sm" placeholder="size: 300 × 300 × 60 мм&#10;pieces_per_m2: 11.1"></textarea>
                        <p class="mt-1 text-[11px] text-slate-400">pieces_per_m2 нужен калькулятору площади и конфигуратору.</p>
                    </div>
                    <div>
                        <InputLabel value="Цвета (Название #HEX)" />
                        <textarea v-model="colorsText" rows="5" class="mt-1 w-full rounded-lg border-slate-300 font-mono text-xs shadow-sm" placeholder="Мрамор белый #E8E6E1"></textarea>
                        <p class="mt-1 text-[11px] text-slate-400">Первый цвет — основной, им рисуется превью.</p>
                    </div>
                </div>

                <div class="mt-4 flex flex-wrap gap-5 text-sm text-slate-600">
                    <label class="flex items-center gap-2"><input v-model="form.is_active" type="checkbox" class="rounded border-slate-300 text-indigo-600" /> Опубликована</label>
                    <label class="flex items-center gap-2"><input v-model="form.is_featured" type="checkbox" class="rounded border-slate-300 text-indigo-600" /> На главной</label>
                    <label class="flex items-center gap-2"><input v-model="form.in_stock" type="checkbox" class="rounded border-slate-300 text-indigo-600" /> В наличии</label>
                    <label class="flex items-center gap-2">Порядок <TextInput v-model="form.order" type="number" class="w-20" /></label>
                </div>

                <div class="mt-5 flex justify-end gap-2">
                    <SecondaryButton @click="showForm = false">Отмена</SecondaryButton>
                    <PrimaryButton :disabled="form.processing" @click="submit">{{ editingId ? 'Сохранить' : 'Добавить' }}</PrimaryButton>
                </div>
            </div>
        </Modal>

        <!-- Категории -->
        <Modal :show="showCats" max-width="lg" @close="showCats = false">
            <div class="p-6">
                <h3 class="mb-1 text-base font-semibold text-slate-900">Категории каталога</h3>
                <p class="mb-4 text-xs text-slate-400">Порядок и названия видны на витрине сразу после сохранения.</p>

                <div class="max-h-64 space-y-2 overflow-y-auto pr-1">
                    <div v-for="c in categories" :key="c.id" class="flex items-center gap-2 rounded-lg border border-slate-100 px-3 py-2">
                        <span class="h-4 w-4 rounded-full" :style="{ background: c.accent ?? '#C8B79A' }" />
                        <span class="flex-1 text-sm text-slate-700">{{ c.name }} <span class="text-xs text-slate-400">· {{ c.products_count }}</span></span>
                        <button class="rounded p-1 text-slate-300 hover:text-indigo-600" @click="editCat(c)">✎</button>
                        <button class="rounded p-1 text-slate-300 hover:text-rose-600" @click="removeCat(c)">✕</button>
                    </div>
                </div>

                <div class="mt-4 space-y-2 border-t border-slate-100 pt-4">
                    <div class="grid grid-cols-2 gap-2">
                        <TextInput v-model="catForm.name" placeholder="Название" class="w-full" />
                        <TextInput v-model="catForm.accent" placeholder="#C8B79A" class="w-full" />
                    </div>
                    <TextInput v-model="catForm.tagline" placeholder="Подзаголовок для витрины" class="w-full" />
                    <div class="flex justify-end gap-2">
                        <SecondaryButton v-if="editingCat" @click="editingCat = null; catForm.reset()">Отменить правку</SecondaryButton>
                        <PrimaryButton :disabled="catForm.processing" @click="saveCat">{{ editingCat ? 'Сохранить' : 'Добавить' }}</PrimaryButton>
                    </div>
                </div>

                <div class="mt-4 text-right"><SecondaryButton @click="showCats = false">Закрыть</SecondaryButton></div>
            </div>
        </Modal>
    </AppLayout>
</template>
