<script setup>
import { ref, computed } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { confirmDialog } from '@/composables/useConfirm';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';

const props = defineProps({ items: Array });

// Editable copy of all rows.
const rows = ref(props.items.map((i) => ({ ...i })));
const saveForm = useForm({ items: [] });
const save = () => {
    saveForm.items = rows.value.map((r) => ({ id: r.id, ru: r.ru, kk: r.kk }));
    saveForm.put(route('translations.update'), { preserveScroll: true });
};

// Grouped view.
const groups = computed(() => {
    const map = {};
    for (const r of rows.value) (map[r.group] ??= []).push(r);
    return map;
});

// Add new key.
const addForm = useForm({ key: '', group: 'common', ru: '', kk: '' });
const showAdd = ref(false);
const add = () => addForm.post(route('translations.store'), { preserveScroll: true, onSuccess: () => { showAdd.value = false; addForm.reset(); router.reload({ only: ['items'] }); } });

const destroy = async (r) => {
    if (await confirmDialog({ title: 'Удалить ключ', message: `Ключ «${r.key}» будет удалён.`, confirmText: 'Удалить', danger: true })) {
        router.delete(route('translations.destroy', r.id), { preserveScroll: true, onSuccess: () => router.reload({ only: ['items'] }) });
    }
};
</script>

<template>
    <Head title="Переводы" />
    <AppLayout>
        <template #header>{{ $t('page.translations', 'Переводы интерфейса') }}</template>

        <div class="mx-auto max-w-5xl space-y-6">
            <div class="flex items-center justify-between">
                <p class="text-sm text-slate-500">Редактируйте тексты интерфейса для русского и казахского языков. Изменения применяются сразу после сохранения.</p>
                <button @click="showAdd = !showAdd" class="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 shadow-sm hover:bg-slate-50">+ Ключ</button>
            </div>

            <!-- Add key -->
            <div v-if="showAdd" class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-4">
                    <div><InputLabel value="Ключ" /><TextInput v-model="addForm.key" class="mt-1 w-full" placeholder="напр. deals.title" /><div v-if="addForm.errors.key" class="mt-1 text-xs text-red-600">{{ addForm.errors.key }}</div></div>
                    <div><InputLabel value="Группа" /><TextInput v-model="addForm.group" class="mt-1 w-full" placeholder="common" /></div>
                    <div><InputLabel value="RU" /><TextInput v-model="addForm.ru" class="mt-1 w-full" /></div>
                    <div><InputLabel value="KK" /><TextInput v-model="addForm.kk" class="mt-1 w-full" /></div>
                </div>
                <div class="mt-3 flex justify-end"><PrimaryButton :disabled="addForm.processing" @click="add">Добавить</PrimaryButton></div>
            </div>

            <!-- Groups -->
            <div v-for="(list, group) in groups" :key="group" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-100 bg-slate-50 px-5 py-2.5 text-xs font-semibold uppercase tracking-wide text-slate-500">{{ group }}</div>
                <table class="min-w-full text-sm">
                    <thead class="text-left text-xs uppercase tracking-wide text-slate-400">
                        <tr><th class="px-5 py-2 w-1/4">Ключ</th><th class="px-5 py-2">Русский</th><th class="px-5 py-2">Қазақша</th><th class="px-5 py-2 w-10"></th></tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        <tr v-for="r in list" :key="r.id" class="hover:bg-slate-50/60">
                            <td class="px-5 py-2 font-mono text-xs text-slate-400">{{ r.key }}</td>
                            <td class="px-5 py-2"><input v-model="r.ru" class="w-full rounded-md border-slate-200 py-1.5 text-sm focus:border-indigo-400 focus:ring-indigo-400" /></td>
                            <td class="px-5 py-2"><input v-model="r.kk" class="w-full rounded-md border-slate-200 py-1.5 text-sm focus:border-indigo-400 focus:ring-indigo-400" /></td>
                            <td class="px-5 py-2 text-right"><button @click="destroy(r)" class="text-slate-300 transition-colors hover:text-red-500" title="Удалить">✕</button></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Sticky save -->
            <div class="sticky bottom-4 flex items-center justify-end gap-3">
                <transition enter-active-class="transition duration-300" enter-from-class="opacity-0" leave-active-class="transition" leave-to-class="opacity-0">
                    <span v-if="saveForm.recentlySuccessful" class="text-sm font-medium text-emerald-600">✓ Сохранено</span>
                </transition>
                <PrimaryButton :disabled="saveForm.processing" @click="save">Сохранить переводы</PrimaryButton>
            </div>
        </div>
    </AppLayout>
</template>
