<script setup>
/**
 * Фотографии сделки и заказа цеха.
 *
 * Живут в тех же документах — отдельной таблицы под них нет: фото это тот же
 * прикреплённый файл, только показываем его картинкой, а не строкой списка.
 * Что фото, а что документ, решает mime, а не расширение в названии.
 *
 * Перед отправкой снимок жмётся прямо в браузере (utils/image.js): в цехе
 * грузят с телефона, и мегабайтный файл ехал бы минуту.
 */
import { ref, computed } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { confirmDialog } from '@/composables/useConfirm';
import { useE } from '@/composables/useTranslations';
import { compressImage, isImage } from '@/utils/image';

const tr = useE();

const props = defineProps({
    documents: { type: Array, default: () => [] },
    entityType: String,
    entityId: Number,
    canUpload: { type: Boolean, default: true },
    /** Рядом с товаром: мельче плитка, кнопка в строку с ними. */
    compact: { type: Boolean, default: false },
});

const photos = computed(() => props.documents.filter((d) => isImage(d.mime_type)));

// Свой снимок убирает автор — цех прикрепляет фото сам, и «мимо» снятое не
// должно ждать менеджера. Чужой файл трогает только тот, кому дано право.
const page = usePage();
const canDelete = (photo) => photo.user_id === page.props.auth?.user?.id
    || (page.props.auth?.user?.permissions ?? []).includes('document.delete');

const fileInput = ref(null);
const zoomed = ref(null);
const form = useForm({ documentable_type: props.entityType, documentable_id: props.entityId, name: '', file: null });

// Сколько снимков уже ушло из пачки: с телефона выбирают сразу десяток, и
// без счётчика кнопка просто «висит».
const queue = ref({ done: 0, total: 0 });

const pick = () => fileInput.value.click();

const onFiles = async (e) => {
    const files = Array.from(e.target.files ?? []);
    e.target.value = '';
    queue.value = { done: 0, total: files.length };

    // По одному: сервер версионирует документы по имени и ждёт один файл.
    // Пачку отправляем последовательно — параллельные запросы на одну и ту
    // же сущность спорили бы за версию документа.
    for (const raw of files) {
        const file = await compressImage(raw);
        form.name = file.name;
        form.file = file;
        await new Promise((done) => form.post(route('documents.store'), {
            preserveScroll: true, forceFormData: true, onFinish: done,
        }));
        queue.value.done += 1;
    }

    queue.value = { done: 0, total: 0 };
    form.reset('file', 'name');
};

const remove = async (d) => {
    if (await confirmDialog({ title: tr('Удалить фото'), message: tr('Фото будет удалено.'), confirmText: tr('Удалить'), danger: true })) {
        router.delete(route('documents.destroy', d.id), { preserveScroll: true });
    }
};
</script>

<template>
    <div>
        <input ref="fileInput" type="file" accept="image/*" multiple class="hidden" @change="onFiles" />

        <div v-if="photos.length" class="grid gap-1.5" :class="compact ? 'grid-cols-4 sm:grid-cols-5' : 'grid-cols-3'">
            <div v-for="p in photos" :key="p.id" class="group relative aspect-square overflow-hidden rounded-lg bg-slate-100">
                <img :src="route('documents.preview', p.id)" :alt="p.name" loading="lazy"
                    class="h-full w-full cursor-zoom-in object-cover transition-transform duration-200 group-hover:scale-105"
                    @click="zoomed = p" />
                <span v-if="p.user?.name && !compact" class="pointer-events-none absolute inset-x-0 bottom-0 truncate bg-gradient-to-t from-slate-900/70 to-transparent px-1.5 pb-1 pt-4 text-[10px] text-white">{{ p.user.name }}</span>
                <button v-if="canDelete(p)" class="absolute right-1 top-1 rounded-md bg-white/85 p-1 text-slate-500 opacity-0 transition-opacity duration-150 group-hover:opacity-100 hover:text-rose-600"
                    :title="$e('Удалить')" @click.stop="remove(p)">
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>
        </div>

        <div class="flex items-center gap-3" :class="photos.length ? 'mt-1.5' : ''">
            <button v-if="canUpload" :disabled="form.processing" @click="pick"
                class="inline-flex items-center gap-1.5 rounded-lg bg-slate-100 px-3 py-1.5 text-xs font-semibold text-slate-700 transition-colors duration-150 hover:bg-slate-200 disabled:opacity-50">
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3z"/><circle cx="12" cy="13" r="3.5"/></svg>
                <template v-if="queue.total > 1">{{ queue.done + 1 }} / {{ queue.total }}…</template>
                <template v-else>{{ form.processing ? $e('Загрузка…') : $e('+ Фото') }}</template>
            </button>
            <span v-if="!photos.length" class="text-xs text-slate-400">{{ compact ? $e('фото нет') : $e('Можно выбрать сразу несколько') }}</span>
            <span v-else-if="!compact" class="text-xs text-slate-400">{{ photos.length }} {{ $e('шт. · жмутся автоматически') }}</span>
        </div>

        <!-- Просмотр во весь экран: в цехе смотрят деталь отливки, миниатюры мало. -->
        <div v-if="zoomed" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/80 p-6" @click="zoomed = null">
            <img :src="route('documents.preview', zoomed.id)" :alt="zoomed.name" class="max-h-full max-w-full rounded-lg object-contain" />
            <button class="absolute right-5 top-5 rounded-lg bg-white/10 p-2 text-white hover:bg-white/20" :title="$e('Закрыть')">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
            </button>
        </div>
    </div>
</template>
