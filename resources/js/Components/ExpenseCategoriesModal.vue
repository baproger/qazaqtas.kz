<script setup>
/**
 * Категории расходов компании: добавить, переименовать, удалить.
 *
 * Общий компонент для Финансов и рабочего места бухгалтера: список один и
 * тот же, а форма, разъехавшаяся по двум страницам, рано или поздно начнёт
 * вести себя по-разному.
 */
import { ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { confirmDialog } from '@/composables/useConfirm';
import { useE } from '@/composables/useTranslations';

const tr = useE();

const props = defineProps({
    show: { type: Boolean, default: false },
    categories: { type: Array, default: () => [] },
});
const emit = defineEmits(['close']);

const newCat = ref('');
const names = ref({});
const sync = () => (names.value = Object.fromEntries((props.categories ?? []).map((c) => [c.id, c.name])));

watch(() => props.show, (open) => { if (open) { newCat.value = ''; sync(); } }, { immediate: true });
watch(() => props.categories, sync);

const add = () => {
    if (!newCat.value.trim()) return;
    router.post(route('expenseCategories.store'), { name: newCat.value.trim() },
        { preserveScroll: true, onSuccess: () => { newCat.value = ''; sync(); } });
};
const save = (c) => {
    const name = (names.value[c.id] ?? '').trim();
    if (!name || name === c.name) return;
    router.put(route('expenseCategories.update', c.id), { name }, { preserveScroll: true, onSuccess: sync });
};
const remove = async (c) => {
    if (!(await confirmDialog({
        title: `Удалить категорию «${c.name}»?`,
        message: tr('Если по ней уже есть расходы — она скроется из списка, суммы в отчётах сохранятся.'),
        confirmText: tr('Удалить'),
        danger: true,
    }))) return;
    router.delete(route('expenseCategories.destroy', c.id), { preserveScroll: true, onSuccess: sync });
};
</script>

<template>
    <Modal :show="show" max-width="md" @close="emit('close')">
        <div class="p-6">
            <h3 class="mb-1 text-base font-semibold text-slate-900">{{ $e('Категории расходов компании') }}</h3>
            <p class="mb-4 text-xs text-slate-400">{{ $e('Переименуйте прямо в поле (сохранение — Enter или клик мимо), ✕ — удалить.') }}</p>
            <div class="max-h-72 space-y-2 overflow-y-auto pr-1">
                <!-- Служебные категории (code) заблокированы: на них держатся
                     расчёты — итог ЗП без двойного счёта и оплата закупа.
                     Сервер их всё равно не отдаст, здесь просто не показываем
                     кнопки, чтобы не звать в тупик. -->
                <div v-for="c in categories" :key="c.id" class="flex items-center gap-2">
                    <input v-if="!c.code" v-model="names[c.id]" @keyup.enter="save(c)" @blur="save(c)" type="text"
                        class="flex-1 rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                    <span v-else class="flex flex-1 items-center gap-2 rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-500">
                        {{ c.name }}
                        <span class="rounded bg-slate-200 px-1.5 py-0.5 text-xs font-medium text-slate-500"
                            :title="$e('На этой категории держатся расчёты — менять её нельзя')">{{ $e('служебная') }}</span>
                    </span>
                    <button v-if="!c.code" @click="remove(c)" class="rounded p-1.5 text-slate-300 transition hover:text-rose-600" :title="$e('Удалить категорию')">✕</button>
                </div>
                <div v-if="!categories.length" class="py-4 text-center text-sm text-slate-400">{{ $e('Категорий пока нет') }}</div>
            </div>
            <div class="mt-4 flex gap-2">
                <input v-model="newCat" @keyup.enter="add" type="text" :placeholder="$e('Новая категория…')"
                    class="flex-1 rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                <PrimaryButton type="button" @click="add">{{ $e('Добавить') }}</PrimaryButton>
            </div>
            <div class="mt-4 text-right">
                <SecondaryButton @click="emit('close')">{{ $e('Закрыть') }}</SecondaryButton>
            </div>
        </div>
    </Modal>
</template>
