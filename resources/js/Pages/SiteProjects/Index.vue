<script setup>
import { ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import { confirmDialog } from '@/composables/useConfirm';

defineProps({ projects: Array });

const showForm = ref(false);
const editingId = ref(null);
const fileInputs = ref({});

const form = useForm({
    title: '', city: '', year: '', area: '', products: '', description: '',
    order: 0, is_active: true,
});

const openCreate = () => {
    editingId.value = null;
    form.reset();
    form.clearErrors();
    showForm.value = true;
};

const openEdit = (p) => {
    editingId.value = p.id;
    form.clearErrors();
    Object.assign(form, {
        title: p.title, city: p.city ?? '', year: p.year ?? '', area: p.area ?? '',
        products: p.products ?? '', description: p.description ?? '',
        order: p.order ?? 0, is_active: !!p.is_active,
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
    if (!(await confirmDialog({ title: `Удалить объект «${p.title}»?`, confirmText: 'Удалить', danger: true }))) return;
    router.delete(route('siteProjects.destroy', p.id), { preserveScroll: true });
};
</script>

<template>
    <AppLayout>
        <template #header>Объекты сайта</template>

        <div class="mb-4 flex flex-wrap items-center gap-2">
            <PrimaryButton @click="openCreate">+ Объект</PrimaryButton>
            <a :href="route('site.home')" target="_blank" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-medium text-indigo-600 shadow-sm transition hover:bg-slate-50">
                Открыть главную ↗
            </a>
            <p class="ml-auto text-xs text-slate-400">
                Объекты с фото показываются на главной крупными кадрами при скролле
            </p>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <article v-for="p in projects" :key="p.id" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="relative">
                    <img v-if="p.thumb || p.image" :src="p.thumb ?? p.image" :alt="p.title" loading="lazy" class="aspect-[16/10] w-full object-cover" />
                    <div v-else class="grid aspect-[16/10] w-full place-items-center bg-slate-100 text-xs text-slate-400">
                        Нет фото — объект не попадёт на главную
                    </div>

                    <label class="absolute bottom-2 right-2 cursor-pointer rounded-lg bg-slate-900/80 px-2.5 py-1.5 text-[11px] font-semibold text-white backdrop-blur transition hover:bg-slate-900">
                        {{ p.image ? 'Заменить фото' : 'Загрузить фото' }}
                        <input :ref="(el) => (fileInputs[p.id] = el)" type="file" accept="image/jpeg,image/png,image/webp" class="hidden" @change="uploadImage(p, $event)" />
                    </label>

                    <span v-if="!p.is_active" class="absolute left-2 top-2 rounded bg-slate-900/80 px-2 py-0.5 text-[10px] font-semibold text-white">скрыт</span>
                </div>

                <div class="p-4">
                    <p class="text-xs text-slate-400">{{ p.city }}<template v-if="p.year"> · {{ p.year }}</template></p>
                    <h3 class="mt-1 font-semibold text-slate-900">{{ p.title }}</h3>
                    <p v-if="p.products" class="mt-1 line-clamp-2 text-xs text-slate-500">{{ p.products }}</p>
                    <div class="mt-3 flex items-center justify-between">
                        <span class="text-sm font-semibold text-indigo-600">{{ p.area ?? '—' }}</span>
                        <span>
                            <button class="rounded p-1 text-slate-300 transition hover:text-indigo-600" title="Изменить" @click="openEdit(p)">
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>
                            </button>
                            <button class="rounded p-1 text-slate-300 transition hover:text-rose-600" title="Удалить" @click="remove(p)">✕</button>
                        </span>
                    </div>
                </div>
            </article>

            <p v-if="!projects.length" class="col-span-full rounded-2xl border border-dashed border-slate-200 py-16 text-center text-sm text-slate-400">
                Объектов пока нет — «+ Объект»
            </p>
        </div>

        <Modal :show="showForm" max-width="xl" @close="showForm = false">
            <div class="p-6">
                <h3 class="mb-4 text-base font-semibold text-slate-900">{{ editingId ? 'Изменить объект' : 'Новый объект' }}</h3>

                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <InputLabel value="Название *" /><TextInput v-model="form.title" class="mt-1 w-full" placeholder="Благоустройство ЖК «Керемет»" />
                        <InputError :message="form.errors.title" class="mt-1" />
                    </div>
                    <div><InputLabel value="Город" /><TextInput v-model="form.city" class="mt-1 w-full" /></div>
                    <div><InputLabel value="Год" /><TextInput v-model="form.year" class="mt-1 w-full" placeholder="2025" /></div>
                    <div><InputLabel value="Объём" /><TextInput v-model="form.area" class="mt-1 w-full" placeholder="4 200 м²" /></div>
                    <div><InputLabel value="Порядок" /><TextInput v-model="form.order" type="number" min="0" class="mt-1 w-full" /></div>
                    <div class="sm:col-span-2">
                        <InputLabel value="Что уложено" /><TextInput v-model="form.products" class="mt-1 w-full" placeholder="Плитка «Квадрат», бордюр дорожный, вазоны Ø900" />
                    </div>
                    <div class="sm:col-span-2">
                        <InputLabel value="Описание" />
                        <textarea v-model="form.description" rows="3" class="mt-1 w-full rounded-lg border-slate-300 text-sm shadow-sm"></textarea>
                    </div>
                </div>

                <label class="mt-4 flex items-center gap-2 text-sm text-slate-600">
                    <input v-model="form.is_active" type="checkbox" class="rounded border-slate-300 text-indigo-600" /> Показывать на сайте
                </label>

                <p class="mt-3 text-[11px] text-slate-400">
                    Фотография загружается на карточке объекта после сохранения. Для главной
                    лучше горизонтальный снимок объекта целиком — он показывается во весь экран.
                </p>

                <div class="mt-5 flex justify-end gap-2">
                    <SecondaryButton @click="showForm = false">Отмена</SecondaryButton>
                    <PrimaryButton :disabled="form.processing" @click="submit">{{ editingId ? 'Сохранить' : 'Добавить' }}</PrimaryButton>
                </div>
            </div>
        </Modal>
    </AppLayout>
</template>
