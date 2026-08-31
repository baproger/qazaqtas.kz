<script setup>
import { computed, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Modal from '@/Components/Modal.vue';
import TranslationTabs from '@/Components/TranslationTabs.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import { confirmDialog } from '@/composables/useConfirm';
import { useE } from '@/composables/useTranslations';

const tr = useE();

const props = defineProps({ projects: Array, locales: { type: Array, default: () => [] } });

// Год и площадь — цифры, они одинаковы на всех языках и не переводятся.
const PROJECT_FIELDS = [
    { key: 'title', label: 'Название', type: 'text' },
    { key: 'city', label: 'Город', type: 'text' },
    { key: 'products', label: 'Состав работ', type: 'text' },
    { key: 'description', label: 'Описание', type: 'textarea', rows: 3 },
];

const showForm = ref(false);
const editingId = ref(null);
const fileInputs = ref({});

const form = useForm({
    title: '', city: '', year: '', area: '', products: '', description: '',
    order: 0, is_active: true, translations: {},
});

/** Базовые значения — подсказка на вкладках языков. */
const translationBase = computed(() => ({
    title: form.title,
    city: form.city,
    products: form.products,
    description: form.description,
}));

const openCreate = () => {
    editingId.value = null;
    form.reset();
    form.clearErrors();
    form.translations = {};
    showForm.value = true;
};

const openEdit = (p) => {
    editingId.value = p.id;
    form.clearErrors();
    Object.assign(form, {
        title: p.title, city: p.city ?? '', year: p.year ?? '', area: p.area ?? '',
        products: p.products ?? '', description: p.description ?? '',
        order: p.order ?? 0, is_active: !!p.is_active,
        translations: p.translations_map ?? {},
    });
    showForm.value = true;
};

const submit = () => {
    const opts = { preserveScroll: true, onSuccess: () => (showForm.value = false) };
    editingId.value
        ? form.put(route('siteProjects.update', editingId.value), opts)
        : form.post(route('siteProjects.store'), opts);
};

const uploadImage = (project, event) => {
    const file = event.target.files?.[0];
    if (!file) return;
    router.post(route('siteProjects.image', project.id), { image: file }, {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => { if (fileInputs.value[project.id]) fileInputs.value[project.id].value = ''; },
    });
};

const remove = async (p) => {
    if (!(await confirmDialog({ title: `Удалить объект «${p.title}»?`, confirmText: tr('Удалить'), danger: true }))) return;
    router.delete(route('siteProjects.destroy', p.id), { preserveScroll: true });
};
</script>

<template>
    <AppLayout>
        <template #header>{{ $e('Объекты сайта') }}</template>

        <div class="mb-4 flex flex-wrap items-center gap-2">
            <PrimaryButton @click="openCreate">{{ $e('+ Объект') }}</PrimaryButton>
            <a :href="route('site.home')" target="_blank" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-medium text-indigo-600 shadow-sm transition hover:bg-slate-50 dark:border-slate-800/80 dark:bg-slate-900/70 dark:text-indigo-400 dark:hover:bg-slate-800/60">
                {{ $e('Открыть главную ↗') }}
            </a>
            <p class="ml-auto text-xs text-slate-400">
                {{ $e('Объекты с фото показываются на главной крупными кадрами при скролле') }}
            </p>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <article v-for="p in projects" :key="p.id" class="overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900/70">
                <div class="relative">
                    <img v-if="p.thumb || p.image" :src="p.thumb ?? p.image" :alt="p.title" loading="lazy" class="aspect-[16/10] w-full object-cover" />
                    <div v-else class="grid aspect-[16/10] w-full place-items-center bg-slate-100 text-xs text-slate-400 dark:bg-slate-800/60">
                        {{ $e('Нет фото — объект не попадёт на главную') }}
                    </div>

                    <label class="absolute bottom-2 right-2 cursor-pointer rounded-lg bg-slate-900/80 px-2.5 py-1.5 text-xs font-semibold text-white backdrop-blur transition hover:bg-slate-900">
                        {{ p.image ? $e('Заменить фото') : $e('Загрузить фото') }}
                        <input :ref="(el) => (fileInputs[p.id] = el)" type="file" accept="image/jpeg,image/png,image/webp" class="hidden" @change="uploadImage(p, $event)" />
                    </label>

                    <span v-if="!p.is_active" class="absolute left-2 top-2 rounded bg-slate-900/80 px-2 py-0.5 text-xs font-semibold text-white">{{ $e('скрыт') }}</span>
                </div>

                <div class="p-4">
                    <p class="text-xs text-slate-400">{{ p.city }}<template v-if="p.year"> · {{ p.year }}</template></p>
                    <h3 class="mt-1 font-semibold text-slate-900 dark:text-slate-100">{{ p.title }}</h3>
                    <p v-if="p.products" class="mt-1 line-clamp-2 text-xs text-slate-500 dark:text-slate-400">{{ p.products }}</p>
                    <div class="mt-3 flex items-center justify-between">
                        <span class="text-sm font-semibold text-indigo-600 dark:text-indigo-400">{{ p.area ?? '—' }}</span>
                        <span>
                            <button class="rounded p-1 text-slate-300 transition hover:text-indigo-600 dark:text-slate-600 dark:hover:text-indigo-400" :title="$e('Изменить')" @click="openEdit(p)">
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>
                            </button>
                            <button class="rounded p-1 text-slate-300 transition hover:text-rose-600 dark:text-slate-600 dark:hover:text-rose-400" :title="$e('Удалить')" @click="remove(p)">✕</button>
                        </span>
                    </div>
                </div>
            </article>

            <p v-if="!projects.length" class="col-span-full rounded-2xl border border-dashed border-slate-200 py-16 text-center text-sm text-slate-400 dark:border-slate-800/80">
                {{ $e('Объектов пока нет — «+ Объект»') }}
            </p>
        </div>

        <Modal :show="showForm" max-width="xl" @close="showForm = false">
            <div class="p-6">
                <h3 class="mb-4 text-base font-semibold text-slate-900 dark:text-slate-100">{{ editingId ? $e('Изменить объект') : $e('Новый объект') }}</h3>

                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <InputLabel :value="$e('Название *')" /><TextInput v-model="form.title" class="mt-1 w-full" :placeholder="$e('Благоустройство ЖК «Керемет»')" />
                        <InputError :message="form.errors.title" class="mt-1" />
                    </div>
                    <div><InputLabel :value="$e('Город')" /><TextInput v-model="form.city" class="mt-1 w-full" /></div>
                    <div><InputLabel :value="$e('Год')" /><TextInput v-model="form.year" class="mt-1 w-full" placeholder="2025" /></div>
                    <div><InputLabel :value="$e('Объём')" /><TextInput v-model="form.area" class="mt-1 w-full" :placeholder="$e('4 200 м²')" /></div>
                    <div><InputLabel :value="$e('Порядок')" /><TextInput v-model="form.order" type="number" min="0" class="mt-1 w-full" /></div>
                    <div class="sm:col-span-2">
                        <InputLabel :value="$e('Что уложено')" /><TextInput v-model="form.products" class="mt-1 w-full" :placeholder="$e('Плитка «Квадрат», бордюр дорожный, вазоны Ø900')" />
                    </div>
                    <div class="sm:col-span-2">
                        <InputLabel :value="$e('Описание')" />
                        <textarea v-model="form.description" rows="3" class="mt-1 w-full rounded-lg border-slate-300 text-sm shadow-sm"></textarea>
                    </div>
                </div>

                <label class="mt-4 flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                    <input v-model="form.is_active" type="checkbox" class="rounded border-slate-300 text-indigo-600" /> {{ $e('Показывать на сайте') }}
                </label>

                <div class="mt-5">
                    <p class="text-sm font-medium text-slate-700 dark:text-slate-300">{{ $e('Языки') }}</p>
                    <!-- :key пересоздаёт вкладки при переходе на другой объект. -->
                    <TranslationTabs
                        :key="`tr-${editingId ?? 'new'}`"
                        v-model="form.translations"
                        class="mt-2"
                        :locales="locales"
                        :base="translationBase"
                        :fields="PROJECT_FIELDS"
                    />
                </div>

                <p class="mt-3 text-xs text-slate-400">
                    {{ $e('Фотография загружается на карточке объекта после сохранения. Для главной лучше горизонтальный снимок объекта целиком — он показывается во весь экран.') }}
                </p>

                <div class="mt-5 flex justify-end gap-2">
                    <SecondaryButton @click="showForm = false">{{ $e('Отмена') }}</SecondaryButton>
                    <PrimaryButton :disabled="form.processing" @click="submit">{{ editingId ? $e('Сохранить') : $e('Добавить') }}</PrimaryButton>
                </div>
            </div>
        </Modal>
    </AppLayout>
</template>
