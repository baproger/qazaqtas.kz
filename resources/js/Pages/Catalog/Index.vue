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
import MediaManager from '@/Components/catalog/MediaManager.vue';
import SeoPanel from '@/Components/catalog/SeoPanel.vue';
import Pagination from '@/Components/Pagination.vue';
import TranslationTabs from '@/Components/TranslationTabs.vue';
import { parseMap, formatMap, parseColors, formatColors } from '@/utils/catalog';
import { useE } from '@/composables/useTranslations';

const tr = useE();

const props = defineProps({ products: Object, categories: Array, filters: Object, units: Array, locales: Array });

// Что на карточке переводится. Порядок повторяет основную форму, чтобы
// вкладка языка читалась как её копия.
const PRODUCT_FIELDS = [
    { key: 'name', label: 'Название', type: 'text' },
    { key: 'short_description', label: 'Короткое описание', type: 'text' },
    { key: 'description', label: 'Описание', type: 'textarea', rows: 3 },
    { key: 'specs', label: 'Характеристики', type: 'map', hint: 'Ключи те же, что в карточке — переводятся только значения', rows: 4 },
    { key: 'colors', label: 'Палитра', type: 'colors', hint: 'Название #HEX — цвет тот же, меняется только название', rows: 3 },
];

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
const tab = ref('fields');
// Актуальная карточка из списка: после загрузки фото Inertia обновляет props,
// и медиа-менеджер сразу показывает новое состояние.
const editingProduct = computed(() => props.products.data.find((p) => p.id === editingId.value) ?? null);
const specsText = ref('');
const colorsText = ref('');

// ---- ИИ-перевод вкладки «Языки»: заполняет kk и ru из базовых полей ----
const trBusy = ref(false);
const trNote = ref('');
const trError = ref(false);
const trVersion = ref(0);
const translateAi = async () => {
    trBusy.value = true; trNote.value = ''; trError.value = false;
    try {
        const { data } = await window.axios.post(route('catalog.translate', editingId.value), {
            name: form.name,
            short_description: form.short_description,
            description: form.description,
            specs: parseMap(specsText.value),
            colors: parseColors(colorsText.value),
        });
        form.translations = { ...form.translations, ...data };
        trVersion.value++; // вкладки пересоздаются и подтягивают новые значения
        trNote.value = tr('Переведено ИИ — проверьте и сохраните форму.');
    } catch (e) {
        trError.value = true;
        trNote.value = e?.response?.data?.message ?? tr('Не удалось перевести.');
    } finally {
        trBusy.value = false;
    }
};

const form = useForm({
    category_id: '', name: '', slug: '', code: '', unit: 'м²', price: '', old_price: '',
    min_order: 1, short_description: '', description: '',
    specs: {}, colors: [], images: [], documents: [],
    is_active: true, is_featured: false, in_stock: true, min_stock: '', order: 0,
    translations: {},
});

/** Базовые значения — подсказка на вкладках: их покажет витрина без перевода. */
const translationBase = computed(() => ({
    name: form.name,
    short_description: form.short_description,
    description: form.description,
    specs: parseMap(specsText.value),
    colors: parseColors(colorsText.value),
}));

const openCreate = () => {
    editingId.value = null;
    tab.value = 'fields';
    form.reset();
    form.clearErrors();
    form.category_id = props.categories[0]?.id ?? '';
    form.translations = {};
    specsText.value = tr('size: 300 × 300 × 60 мм\npieces_per_m2: 11.1\nfrost: F200');
    colorsText.value = tr('Мрамор белый #E8E6E1\nСерый графит #8A8D91');
    showForm.value = true;
};

const openEdit = (p) => {
    editingId.value = p.id;
    tab.value = 'fields';
    form.clearErrors();
    Object.assign(form, {
        category_id: p.category_id ?? '', name: p.name, slug: p.slug ?? '', code: p.code ?? '',
        unit: p.unit, price: Number(p.price), old_price: p.old_price ? Number(p.old_price) : '',
        min_order: Number(p.min_order), short_description: p.short_description ?? '',
        description: p.description ?? '', images: p.images ?? [], documents: p.documents ?? [],
        is_active: !!p.is_active, is_featured: !!p.is_featured, in_stock: !!p.in_stock, min_stock: p.min_stock ?? '', order: p.order ?? 0,
        translations: p.translations_map ?? {},
    });
    specsText.value = formatMap(p.specs);
    colorsText.value = formatColors(p.colors);
    showForm.value = true;
};

const submit = () => {
    form.specs = parseMap(specsText.value);
    form.colors = parseColors(colorsText.value);
    const opts = { preserveScroll: true, onSuccess: () => (showForm.value = false) };
    editingId.value ? form.put(route('catalog.update', editingId.value), opts) : form.post(route('catalog.store'), opts);
};

const remove = async (p) => {
    if (!(await confirmDialog({ title: `Удалить «${p.name}»?`, message: tr('Позиция исчезнет из каталога на сайте.'), confirmText: tr('Удалить'), danger: true }))) return;
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
    if (!(await confirmDialog({ title: `Удалить категорию «${c.name}»?`, confirmText: tr('Удалить'), danger: true }))) return;
    router.delete(route('catalogCategories.destroy', c.id), { preserveScroll: true });
};

const categoryName = (id) => props.categories.find((c) => c.id === id)?.name ?? '—';
</script>

<template>
    <AppLayout>
        <template #header>{{ $e('Каталог сайта') }}</template>

        <div class="mb-4 flex gap-2 border-b">
            <Link :href="route('catalog.index')" class="border-b-2 border-indigo-600 px-3 py-2 text-sm font-medium text-indigo-600 dark:text-indigo-400">{{ $e('Позиции') }}</Link>
            <Link :href="route('catalogCategories.index')" class="px-3 py-2 text-sm text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-300">{{ $e('Категории') }}</Link>
        </div>

        <div class="mb-4 flex flex-wrap items-center gap-2">
            <PrimaryButton @click="openCreate">{{ $e('+ Позиция') }}</PrimaryButton>
            <button class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-medium text-slate-600 shadow-sm transition hover:bg-slate-50 dark:border-slate-800/80 dark:bg-slate-900/70 dark:text-slate-300 dark:hover:bg-slate-800/60" @click="showCats = true">
                {{ $e('⚙ Категории') }}
            </button>
            <a :href="route('site.catalog')" target="_blank" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-medium text-indigo-600 shadow-sm transition hover:bg-slate-50 dark:border-slate-800/80 dark:bg-slate-900/70 dark:text-indigo-400 dark:hover:bg-slate-800/60">
                {{ $e('Открыть витрину ↗') }}
            </a>

            <div class="ml-auto flex flex-wrap items-center gap-2">
                <input v-model="search" type="search" :placeholder="$e('Название или артикул…')" class="w-56 rounded-lg border-slate-200 py-2 text-sm shadow-sm" />
                <select v-model="category" class="rounded-lg border-slate-200 py-2 text-sm text-slate-600 shadow-sm">
                    <option value="">{{ $e('Все категории') }}</option>
                    <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }} ({{ c.products_count }})</option>
                </select>
            </div>
        </div>

        <div class="overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900/70">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-100 text-sm dark:divide-slate-800">
                    <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-400 dark:bg-slate-800/50">
                        <tr>
                            <th class="px-4 py-2.5 w-16">{{ $e('Фото') }}</th>
                            <th class="px-4 py-2.5">{{ $e('Позиция') }}</th>
                            <th class="px-4 py-2.5">{{ $e('Категория') }}</th>
                            <th class="px-4 py-2.5">{{ $e('Артикул') }}</th>
                            <th class="px-4 py-2.5 text-right">{{ $e('Цена') }}</th>
                            <th class="px-4 py-2.5 text-center">{{ $e('На сайте') }}</th>
                            <th class="px-4 py-2.5"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50 dark:divide-slate-800">
                        <tr v-for="p in products.data" :key="p.id" class="hover:bg-slate-50 dark:hover:bg-slate-800/60">
                            <td class="px-4 py-2">
                                <img v-if="p.images?.length" :src="p.images[0].thumb ?? p.images[0].path" :alt="p.name" loading="lazy"
                                    class="h-11 w-14 rounded-lg object-cover ring-1 ring-slate-200 dark:ring-slate-800" />
                                <span v-else class="grid h-11 w-14 place-items-center rounded-lg bg-slate-100 text-xs text-slate-400 dark:bg-slate-800/60">{{ $e('нет') }}</span>
                            </td>
                            <td class="px-4 py-3">
                                <span class="font-medium text-slate-800 dark:text-slate-200">{{ p.name }}</span>
                                <span class="block text-xs text-slate-400">{{ p.short_description }}</span>
                            </td>
                            <td class="px-4 py-3 text-slate-500 dark:text-slate-400">{{ p.category?.name ?? categoryName(p.category_id) }}</td>
                            <td class="px-4 py-3 text-slate-400">{{ p.code ?? '—' }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right font-semibold tabular-nums text-slate-900 dark:text-slate-100">{{ money(p.price) }} <span class="text-xs font-normal text-slate-400">/ {{ p.unit }}</span></td>
                            <td class="px-4 py-3 text-center">
                                <span class="rounded-full px-2 py-0.5 text-xs font-semibold" :class="p.is_active ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-400' : 'bg-slate-100 text-slate-500 dark:bg-slate-800/60 dark:text-slate-400'">
                                    {{ p.is_active ? $e('опубликована') : $e('скрыта') }}
                                </span>
                                <span v-if="p.is_featured" class="ml-1 text-amber-500" :title="$e('Показывается на главной')">★</span>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right">
                                <button class="rounded p-1 text-slate-300 transition hover:text-indigo-600 dark:text-slate-600 dark:hover:text-indigo-400" :title="$e('Изменить')" @click="openEdit(p)">
                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>
                                </button>
                                <button class="rounded p-1 text-slate-300 transition hover:text-rose-600 dark:text-slate-600 dark:hover:text-rose-400" :title="$e('Удалить')" @click="remove(p)">✕</button>
                            </td>
                        </tr>
                        <tr v-if="!products.data.length"><td colspan="7" class="px-6 py-12 text-center text-slate-400">{{ $e('Каталог пуст — «+ Позиция»') }}</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div v-if="products.last_page > 1" class="mt-4 flex justify-center">
            <Pagination :links="products.links" />
        </div>

        <!-- Форма позиции -->
        <Modal :show="showForm" max-width="2xl" @close="showForm = false">
            <div class="p-6">
                <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ editingId ? $e('Изменить позицию') : $e('Новая позиция каталога') }}</h3>

                <!-- Медиа доступны только у сохранённой позиции: файлам нужен id. -->
                <div v-if="editingId" class="mb-4 mt-3 flex gap-4 border-b border-slate-100 dark:border-slate-800">
                    <button v-for="t2 in [['fields', $e('Данные')], ['lang', $e('Языки')], ['media', $e('Фото, 3D и документы')], ['seo', 'SEO']]" :key="t2[0]"
                        class="-mb-px border-b-2 pb-2 text-sm transition"
                        :class="tab === t2[0] ? 'border-indigo-500 text-slate-900 dark:text-slate-100' : 'border-transparent text-slate-400 hover:text-slate-600 dark:hover:text-slate-300'"
                        @click="tab = t2[0]">{{ t2[1] }}</button>
                </div>
                <div v-else class="mb-4"></div>

                <MediaManager v-if="tab === 'media' && editingProduct" :product="editingProduct" />

                <SeoPanel v-if="tab === 'seo' && editingProduct" :product="editingProduct" />

                <!-- :key пересоздаёт вкладки при переходе на другую карточку:
                     построчные поля держат свой текст и сами не обновятся. -->
                <div v-show="tab === 'lang'">
                    <div v-if="editingId" class="mb-3 flex flex-wrap items-center gap-2">
                        <button type="button" :disabled="trBusy" @click="translateAi"
                            class="inline-flex items-center gap-1.5 rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-indigo-700 active:scale-[0.97] disabled:opacity-50">
                            <span v-if="!trBusy">✨ {{ $e('Перевести (ИИ)') }}</span>
                            <span v-else>{{ $e('Переводим…') }}</span>
                        </button>
                        <span v-if="trNote" class="text-xs" :class="trError ? 'text-rose-600 dark:text-rose-400' : 'text-emerald-600 dark:text-emerald-400'">{{ trNote }}</span>
                    </div>
                    <TranslationTabs
                        :key="`tr-${editingId ?? 'new'}-${trVersion}`"
                        v-model="form.translations"
                        :locales="locales"
                        :base="translationBase"
                        :fields="PRODUCT_FIELDS"
                    />
                </div>

                <div v-show="tab === 'fields'" class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div>
                        <InputLabel :value="$e('Категория')" />
                        <select v-model="form.category_id" class="mt-1 w-full rounded-lg border-slate-300 text-sm shadow-sm">
                            <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
                        </select>
                    </div>
                    <div><InputLabel :value="$e('Артикул')" /><TextInput v-model="form.code" class="mt-1 w-full" /></div>
                    <div class="sm:col-span-2">
                        <InputLabel :value="$e('Название *')" /><TextInput v-model="form.name" class="mt-1 w-full" />
                        <InputError :message="form.errors.name" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel :value="$e('Единица измерения')" />
                        <select v-model="form.unit" class="mt-1 w-full rounded-lg border-slate-300 text-sm shadow-sm">
                            <option v-for="u in units" :key="u" :value="u">{{ u }}</option>
                        </select>
                    </div>
                    <div>
                        <InputLabel :value="$e('Минимальный остаток')" />
                        <TextInput v-model="form.min_stock" type="number" min="0" step="any" class="mt-1 w-full" :placeholder="$e('не следим')" />
                        <p class="mt-1 text-xs text-slate-400">{{ $e('Ниже этого остатка склад пометит товар «мало»') }}</p>
                    </div>
                    <div><InputLabel :value="$e('Цена, ₸ *')" /><TextInput v-model="form.price" type="number" min="0" class="mt-1 w-full" /><InputError :message="form.errors.price" class="mt-1" /></div>
                    <div><InputLabel :value="$e('Старая цена')" /><TextInput v-model="form.old_price" type="number" min="0" class="mt-1 w-full" /></div>
                    <div><InputLabel :value="$e('Минимальный заказ')" /><TextInput v-model="form.min_order" type="number" min="0" step="any" class="mt-1 w-full" /></div>
                    <div class="sm:col-span-2"><InputLabel :value="$e('Короткое описание')" /><TextInput v-model="form.short_description" class="mt-1 w-full" /></div>
                    <div class="sm:col-span-2">
                        <InputLabel :value="$e('Описание')" />
                        <textarea v-model="form.description" rows="3" class="mt-1 w-full rounded-lg border-slate-300 text-sm shadow-sm"></textarea>
                    </div>
                    <div>
                        <InputLabel :value="$e('Характеристики (ключ: значение)')" />
                        <textarea v-model="specsText" rows="5" class="mt-1 w-full rounded-lg border-slate-300 font-mono text-xs shadow-sm" :placeholder="$e('size: 300 × 300 × 60 мм') + '\n' + 'pieces_per_m2: 11.1'"></textarea>
                        <p class="mt-1 text-xs text-slate-400">{{ $e('pieces_per_m2 нужен калькулятору площади и конфигуратору.') }}</p>
                    </div>
                    <div>
                        <InputLabel :value="$e('Цвета (Название #HEX)')" />
                        <textarea v-model="colorsText" rows="5" class="mt-1 w-full rounded-lg border-slate-300 font-mono text-xs shadow-sm" :placeholder="$e('Мрамор белый #E8E6E1')"></textarea>
                        <p class="mt-1 text-xs text-slate-400">{{ $e('Первый цвет — основной, им рисуется превью.') }}</p>
                    </div>
                </div>

                <div v-show="tab === 'fields'" class="mt-4 flex flex-wrap gap-5 text-sm text-slate-600 dark:text-slate-300">
                    <label class="flex items-center gap-2"><input v-model="form.is_active" type="checkbox" class="rounded border-slate-300 text-indigo-600" /> {{ $e('Опубликована') }}</label>
                    <label class="flex items-center gap-2"><input v-model="form.is_featured" type="checkbox" class="rounded border-slate-300 text-indigo-600" /> {{ $e('На главной') }}</label>
                    <label class="flex items-center gap-2"><input v-model="form.in_stock" type="checkbox" class="rounded border-slate-300 text-indigo-600" /> {{ $e('В наличии') }}</label>
                    <label class="flex items-center gap-2">{{ $e('Порядок') }} <TextInput v-model="form.order" type="number" class="w-20" /></label>
                </div>

                <div class="mt-5 flex justify-end gap-2">
                    <SecondaryButton @click="showForm = false">{{ tab === 'media' ? $e('Закрыть') : $e('Отмена') }}</SecondaryButton>
                    <PrimaryButton v-show="tab !== 'media'" :disabled="form.processing" @click="submit">{{ editingId ? $e('Сохранить') : $e('Добавить') }}</PrimaryButton>
                </div>
            </div>
        </Modal>

        <!-- Категории -->
        <Modal :show="showCats" max-width="lg" @close="showCats = false">
            <div class="p-6">
                <h3 class="mb-1 text-base font-semibold text-slate-900 dark:text-slate-100">{{ $e('Категории каталога') }}</h3>
                <p class="mb-4 text-xs text-slate-400">{{ $e('Порядок и названия видны на витрине сразу после сохранения.') }}</p>

                <div class="max-h-64 space-y-2 overflow-y-auto pr-1">
                    <div v-for="c in categories" :key="c.id" class="flex items-center gap-2 rounded-lg border border-slate-100 px-3 py-2 dark:border-slate-800">
                        <span class="h-4 w-4 rounded-full" :style="{ background: c.accent ?? '#C8B79A' }" />
                        <span class="flex-1 text-sm text-slate-700 dark:text-slate-300">{{ c.name }} <span class="text-xs text-slate-400">· {{ c.products_count }}</span></span>
                        <button class="rounded p-1 text-slate-300 hover:text-indigo-600 dark:text-slate-600 dark:hover:text-indigo-400" @click="editCat(c)">✎</button>
                        <button class="rounded p-1 text-slate-300 hover:text-rose-600 dark:text-slate-600 dark:hover:text-rose-400" @click="removeCat(c)">✕</button>
                    </div>
                </div>

                <div class="mt-4 space-y-2 border-t border-slate-100 pt-4 dark:border-slate-800">
                    <div class="grid grid-cols-2 gap-2">
                        <TextInput v-model="catForm.name" :placeholder="$e('Название')" class="w-full" />
                        <TextInput v-model="catForm.accent" placeholder="#C8B79A" class="w-full" />
                    </div>
                    <TextInput v-model="catForm.tagline" :placeholder="$e('Подзаголовок для витрины')" class="w-full" />
                    <div class="flex justify-end gap-2">
                        <SecondaryButton v-if="editingCat" @click="editingCat = null; catForm.reset()">{{ $e('Отменить правку') }}</SecondaryButton>
                        <PrimaryButton :disabled="catForm.processing" @click="saveCat">{{ editingCat ? $e('Сохранить') : $e('Добавить') }}</PrimaryButton>
                    </div>
                </div>

                <div class="mt-4 text-right"><SecondaryButton @click="showCats = false">{{ $e('Закрыть') }}</SecondaryButton></div>
            </div>
        </Modal>
    </AppLayout>
</template>
