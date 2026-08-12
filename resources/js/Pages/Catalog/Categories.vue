<script setup>
import { computed, ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';

const props = defineProps({ categories: { type: Array, default: () => [] } });

const showForm = ref(false);
const editing = ref(null);

const form = useForm({
    name: '',
    slug: '',
    tagline: '',
    description: '',
    accent: '#C8B79A',
    order: 0,
    is_active: true,
});

const openCreate = () => {
    editing.value = null;
    form.reset();
    form.clearErrors();
    form.order = props.categories.length;
    showForm.value = true;
};

const openEdit = (category) => {
    editing.value = category;
    form.clearErrors();
    Object.assign(form, {
        name: category.name,
        slug: category.slug ?? '',
        tagline: category.tagline ?? '',
        description: category.description ?? '',
        accent: category.accent ?? '#C8B79A',
        order: category.order ?? 0,
        is_active: category.is_active,
    });
    showForm.value = true;
};

const submit = () => {
    const done = { onSuccess: () => (showForm.value = false) };
    editing.value
        ? form.put(route('catalogCategories.update', editing.value.id), done)
        : form.post(route('catalogCategories.store'), done);
};

const remove = (category) => {
    if (!confirm(`Удалить категорию «${category.name}»? Позиции внутри останутся без раздела.`)) return;
    router.delete(route('catalogCategories.destroy', category.id), { preserveScroll: true });
};

/* ---------------------------------------------------------------- */
/* Снимок категории                                                  */
/* ---------------------------------------------------------------- */

const uploading = ref(null);
const uploadError = ref(null);

const uploadImage = (category, event) => {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (!file) return;

    uploadError.value = null;
    uploading.value = category.id;

    router.post(route('catalogCategories.image', category.id), { image: file }, {
        preserveScroll: true,
        forceFormData: true,
        onError: (errors) => (uploadError.value = errors.image ?? 'Не удалось загрузить снимок.'),
        onFinish: () => (uploading.value = null),
    });
};

const dropImage = (category) => {
    if (!confirm('Удалить снимок категории?')) return;
    router.delete(route('catalogCategories.imageDestroy', category.id), { preserveScroll: true });
};

const withoutImage = computed(() => props.categories.filter((c) => !c.image).length);
</script>

<template>
    <AppLayout>
        <template #header>Каталог сайта · Категории</template>

        <div class="mb-4 flex gap-2 border-b">
            <Link :href="route('catalog.index')" class="px-3 py-2 text-sm text-slate-500 hover:text-slate-700">Позиции</Link>
            <Link :href="route('catalogCategories.index')" class="border-b-2 border-indigo-600 px-3 py-2 text-sm font-medium text-indigo-600">Категории</Link>
        </div>

        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <p class="text-sm text-slate-500">
                Категории задают разделы каталога и слайды витрины на главной.
                <span v-if="withoutImage" class="font-medium text-amber-600">
                    Без снимка: {{ withoutImage }}.
                </span>
            </p>
            <PrimaryButton @click="openCreate">+ Категория</PrimaryButton>
        </div>

        <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-xs leading-relaxed text-amber-800">
            <b>Снимок для витрины:</b> PNG или WebP с прозрачным фоном, предмет вырезан, 1600×1600.
            Прозрачность нужна, чтобы кадр одинаково лёг и на тёмную, и на светлую тему —
            JPG с белым фоном на тёмном сайте будет выглядеть заплаткой.
            Снимайте все категории в одном масштабе и с одной точки, иначе при переключении предмет «прыгает».
        </div>

        <div class="mt-4 space-y-3">
            <article
                v-for="c in categories"
                :key="c.id"
                class="flex flex-wrap items-center gap-4 rounded-xl border border-slate-200 bg-white p-4 shadow-sm"
            >
                <!-- Снимок на клетчатой подложке: прозрачность видно сразу -->
                <div class="checkerboard grid h-20 w-20 shrink-0 place-items-center rounded-lg border border-slate-200">
                    <img v-if="c.thumb || c.image" :src="c.thumb ?? c.image" :alt="c.name" class="h-full w-full object-contain p-1" />
                    <span v-else class="text-[10px] uppercase tracking-wider text-slate-400">нет фото</span>
                </div>

                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-semibold text-slate-900">{{ c.name }}</p>
                    <p class="truncate text-xs text-slate-400">{{ c.tagline || '—' }}</p>
                    <p class="mt-1 text-xs text-slate-400">
                        {{ c.products_count }} позиций · /{{ c.slug }}
                        <span v-if="!c.is_active" class="ml-1 rounded bg-slate-100 px-1.5 py-0.5 text-slate-500">скрыта</span>
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <label class="cursor-pointer rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-medium text-slate-600 hover:bg-slate-50">
                        {{ uploading === c.id ? 'Загрузка…' : (c.image ? 'Заменить фото' : 'Загрузить фото') }}
                        <input type="file" accept="image/png,image/webp" class="hidden" @change="uploadImage(c, $event)" />
                    </label>
                    <button v-if="c.image" class="rounded-lg px-2 py-1.5 text-xs text-slate-400 hover:text-rose-600" @click="dropImage(c)">Убрать фото</button>
                    <button class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-medium text-slate-600 hover:bg-slate-50" @click="openEdit(c)">Изменить</button>
                    <button class="rounded-lg px-2 py-1.5 text-xs text-slate-400 hover:text-rose-600" @click="remove(c)">Удалить</button>
                </div>
            </article>

            <p v-if="!categories.length" class="rounded-xl border border-dashed border-slate-200 px-6 py-12 text-center text-sm text-slate-400">
                Категорий пока нет — «+ Категория»
            </p>
        </div>

        <p v-if="uploadError" class="mt-3 rounded-lg bg-rose-50 px-3 py-2 text-xs text-rose-700">{{ uploadError }}</p>

        <Modal :show="showForm" max-width="xl" @close="showForm = false">
            <div class="p-6">
                <h3 class="text-sm font-semibold text-slate-900">
                    {{ editing ? 'Категория' : 'Новая категория' }}
                </h3>

                <div class="mt-4 grid gap-3 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <InputLabel value="Название *" />
                        <TextInput v-model="form.name" class="mt-1 w-full" placeholder="Тротуарная плитка" />
                        <InputError :message="form.errors.name" class="mt-1" />
                    </div>
                    <div class="sm:col-span-2">
                        <InputLabel value="Подзаголовок" />
                        <TextInput v-model="form.tagline" class="mt-1 w-full" placeholder="Мраморный композит для дворов и парков" />
                    </div>
                    <div class="sm:col-span-2">
                        <InputLabel value="Описание" />
                        <textarea v-model="form.description" rows="3" class="mt-1 w-full rounded-lg border-slate-300 text-sm shadow-sm"></textarea>
                    </div>
                    <div>
                        <InputLabel value="Адрес (slug)" />
                        <TextInput v-model="form.slug" class="mt-1 w-full" placeholder="оставьте пустым — соберём из названия" />
                        <InputError :message="form.errors.slug" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel value="Акцентный цвет" />
                        <div class="mt-1 flex gap-2">
                            <input v-model="form.accent" type="color" class="h-10 w-12 rounded border-slate-300" />
                            <TextInput v-model="form.accent" class="w-full" />
                        </div>
                    </div>
                    <div>
                        <InputLabel value="Порядок" />
                        <TextInput v-model="form.order" type="number" min="0" class="mt-1 w-full" />
                    </div>
                    <label class="mt-6 flex items-center gap-2 text-sm text-slate-600">
                        <input v-model="form.is_active" type="checkbox" class="rounded border-slate-300 text-indigo-600" />
                        Показывать на сайте
                    </label>
                </div>

                <p v-if="!editing" class="mt-4 rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-500">
                    Снимок загрузите после сохранения — кнопкой «Загрузить фото» в списке.
                </p>

                <div class="mt-6 flex justify-end gap-2">
                    <button class="rounded-lg px-4 py-2 text-sm text-slate-500 hover:bg-slate-50" @click="showForm = false">Отмена</button>
                    <PrimaryButton :disabled="form.processing" @click="submit">Сохранить</PrimaryButton>
                </div>
            </div>
        </Modal>
    </AppLayout>
</template>

<style scoped>
/* Клетка под снимком: прозрачные участки видно без догадок. */
.checkerboard {
    background-image:
        linear-gradient(45deg, #eef1f4 25%, transparent 25%),
        linear-gradient(-45deg, #eef1f4 25%, transparent 25%),
        linear-gradient(45deg, transparent 75%, #eef1f4 75%),
        linear-gradient(-45deg, transparent 75%, #eef1f4 75%);
    background-size: 12px 12px;
    background-position: 0 0, 0 6px, 6px -6px, -6px 0;
}
</style>
