<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { confirmDialog } from '@/composables/useConfirm';

/**
 * Медиа карточки каталога: фото, текстура для 3D, GLB-модель, документы.
 * Загрузка идёт отдельными запросами — не смешивается с формой позиции,
 * поэтому файлы не теряются при ошибке валидации основных полей.
 */
const props = defineProps({ product: { type: Object, required: true } });

const uploading = ref(false);
const docName = ref('');
const imageInput = ref(null);
const modelInput = ref(null);
const docInput = ref(null);

const opts = { preserveScroll: true, onFinish: () => (uploading.value = false) };

const uploadImages = (event) => {
    const files = [...event.target.files];
    if (!files.length) return;
    uploading.value = true;
    router.post(route('catalogMedia.images', props.product.id), { images: files }, {
        ...opts,
        forceFormData: true,
        onSuccess: () => (imageInput.value.value = ''),
    });
};

const removeImage = async (index) => {
    if (!(await confirmDialog({ title: 'Удалить фотографию?', confirmText: 'Удалить', danger: true }))) return;
    router.delete(route('catalogMedia.imageDestroy', props.product.id), { data: { index }, preserveScroll: true });
};

const makeMain = (index) => router.post(route('catalogMedia.imageMain', props.product.id), { index }, opts);

const setTexture = (index) => router.post(route('catalogMedia.texture', props.product.id), { index }, opts);

const uploadModel = (event) => {
    const file = event.target.files?.[0];
    if (!file) return;
    uploading.value = true;
    router.post(route('catalogMedia.model', props.product.id), { model: file }, {
        ...opts,
        forceFormData: true,
        onSuccess: () => (modelInput.value.value = ''),
    });
};

const removeModel = async () => {
    if (!(await confirmDialog({ title: 'Удалить 3D-модель?', confirmText: 'Удалить', danger: true }))) return;
    router.delete(route('catalogMedia.modelDestroy', props.product.id), { preserveScroll: true });
};

const uploadDocument = (event) => {
    const file = event.target.files?.[0];
    if (!file || !docName.value.trim()) return;
    uploading.value = true;
    router.post(route('catalogMedia.document', props.product.id), { document: file, name: docName.value.trim() }, {
        ...opts,
        forceFormData: true,
        onSuccess: () => { docName.value = ''; docInput.value.value = ''; },
    });
};

const removeDocument = async (index) => {
    if (!(await confirmDialog({ title: 'Удалить документ?', confirmText: 'Удалить', danger: true }))) return;
    router.delete(route('catalogMedia.documentDestroy', props.product.id), { data: { index }, preserveScroll: true });
};
</script>

<template>
    <div class="space-y-6">
        <!-- Фотогалерея -->
        <section>
            <div class="flex items-center justify-between">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Фотографии</p>
                <label class="cursor-pointer text-xs font-semibold text-indigo-600 hover:underline">
                    + Загрузить
                    <input ref="imageInput" type="file" accept="image/jpeg,image/png,image/webp" multiple class="hidden" @change="uploadImages" />
                </label>
            </div>

            <div v-if="product.images?.length" class="mt-3 grid grid-cols-3 gap-3 sm:grid-cols-4">
                <figure v-for="(img, i) in product.images" :key="img.path" class="group relative overflow-hidden rounded-xl border border-slate-200">
                    <img :src="img.thumb ?? img.path" :alt="img.alt ?? product.name" loading="lazy" class="aspect-[4/3] w-full object-cover" />

                    <span v-if="i === 0" class="absolute left-1.5 top-1.5 rounded bg-slate-900/80 px-1.5 py-0.5 text-[10px] font-semibold text-white">главное</span>
                    <span v-if="product.texture_path === img.path" class="absolute right-1.5 top-1.5 rounded bg-emerald-600 px-1.5 py-0.5 text-[10px] font-semibold text-white">3D</span>

                    <figcaption class="absolute inset-x-0 bottom-0 flex justify-center gap-1 bg-slate-900/80 p-1 opacity-0 transition group-hover:opacity-100">
                        <button v-if="i !== 0" class="rounded px-1.5 py-0.5 text-[10px] text-white hover:bg-white/20" title="Сделать главным" @click="makeMain(i)">★</button>
                        <button class="rounded px-1.5 py-0.5 text-[10px] text-white hover:bg-white/20"
                            :title="product.texture_path === img.path ? 'Снять текстуру 3D' : 'Использовать как текстуру 3D'"
                            @click="setTexture(product.texture_path === img.path ? null : i)">3D</button>
                        <button class="rounded px-1.5 py-0.5 text-[10px] text-white hover:bg-rose-500" title="Удалить" @click="removeImage(i)">✕</button>
                    </figcaption>
                </figure>
            </div>
            <p v-else class="mt-3 rounded-xl border border-dashed border-slate-200 py-6 text-center text-xs text-slate-400">
                Пока нет фото — витрина рисует схему изделия по типу и цвету
            </p>

            <p class="mt-2 text-[11px] leading-relaxed text-slate-400">
                Кнопка <b>3D</b> помечает снимок как текстуру: этим фото 3D-сцена красит изделие
                на главной и в конфигураторе. Лучше всего подходит фрагмент поверхности,
                снятый сверху при ровном свете.
            </p>
        </section>

        <!-- 3D-модель -->
        <section class="border-t border-slate-100 pt-5">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">3D-модель (GLB)</p>

            <div v-if="product.model_path" class="mt-3 flex items-center gap-3 rounded-xl border border-slate-200 px-3 py-2">
                <span class="text-lg">🧊</span>
                <span class="flex-1 truncate text-sm text-slate-700">Модель загружена — конфигуратор покажет её вместо схемы</span>
                <button class="rounded p-1 text-slate-300 hover:text-rose-600" @click="removeModel">✕</button>
            </div>

            <label v-else class="mt-3 flex cursor-pointer items-center justify-center rounded-xl border border-dashed border-slate-200 py-6 text-xs text-slate-400 hover:border-indigo-300 hover:text-indigo-600">
                Перетащите или выберите .glb (до 24 МБ)
                <input ref="modelInput" type="file" accept=".glb,.gltf" class="hidden" @change="uploadModel" />
            </label>
        </section>

        <!-- Документы -->
        <section class="border-t border-slate-100 pt-5">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Документы</p>

            <ul v-if="product.documents?.length" class="mt-3 space-y-2">
                <li v-for="(doc, i) in product.documents" :key="doc.path" class="flex items-center gap-2 rounded-lg border border-slate-100 px-3 py-2">
                    <a :href="doc.path" target="_blank" class="flex-1 truncate text-sm text-indigo-600 hover:underline">{{ doc.name }}</a>
                    <button class="rounded p-1 text-slate-300 hover:text-rose-600" @click="removeDocument(i)">✕</button>
                </li>
            </ul>

            <div class="mt-3 flex flex-wrap items-center gap-2">
                <TextInput v-model="docName" placeholder="Название документа" class="flex-1" />
                <label class="cursor-pointer rounded-lg border border-slate-200 px-3 py-2 text-xs font-medium text-slate-600 hover:bg-slate-50"
                    :class="!docName.trim() && 'pointer-events-none opacity-40'">
                    Выбрать файл
                    <input ref="docInput" type="file" accept=".pdf,.doc,.docx,.xls,.xlsx" class="hidden" @change="uploadDocument" />
                </label>
            </div>
        </section>

        <p v-if="uploading" class="text-xs text-indigo-600">Загружаем…</p>
    </div>
</template>
