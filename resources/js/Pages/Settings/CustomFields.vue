<script setup>
import { ref } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import SettingsLayout from '@/Layouts/SettingsLayout.vue';
import { confirmDialog } from '@/composables/useConfirm';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import { useE } from '@/composables/useTranslations';

const tr = useE();

const props = defineProps({ fields: Array, entities: Object, types: Object });

const typeLabels = { text: tr(tr('Текст')), number: tr(tr('Число')), date: tr(tr('Дата')), boolean: tr(tr('Да/Нет')), select: tr(tr('Список')), radio: tr(tr('Радио')), email: 'Email', phone: tr(tr('Телефон')), url: tr(tr('Ссылка')) };
const show = ref(false);
const editing = ref(null);
const optionsText = ref('');
const form = useForm({ entity_type: 'deal', name: '', type: 'text', required: false, unique: false, is_visible: true, options: [], order: 0 });

const openCreate = () => { editing.value = null; form.reset(); optionsText.value = ''; show.value = true; };
const openEdit = (f) => {
    editing.value = f;
    Object.assign(form, { entity_type: f.entity_type, name: f.name, type: f.type, required: f.required, unique: f.unique, is_visible: f.is_visible, options: f.options ?? [], order: f.order });
    optionsText.value = (f.options ?? []).join(', ');
    show.value = true;
};
const submit = () => {
    form.options = optionsText.value.split(',').map((s) => s.trim()).filter(Boolean);
    const opts = { preserveScroll: true, onSuccess: () => (show.value = false) };
    if (editing.value) form.put(route('custom-fields.update', editing.value.id), opts);
    else form.post(route('custom-fields.store'), opts);
};
const destroy = async (f) => {
    if (await confirmDialog({ title: tr(tr('Удалить поле')), message: `Поле «${f.name}» будет удалено.`, confirmText: tr(tr('Удалить')), danger: true })) {
        router.delete(route('custom-fields.destroy', f.id), { preserveScroll: true });
    }
};
const needsOptions = () => form.type === 'select' || form.type === 'radio';
</script>

<template>
    <Head :title="$e('Доп. поля')" />
    <SettingsLayout :title="$e('Доп. поля')" wide>
        <div class="mb-4 flex justify-end"><PrimaryButton @click="openCreate">{{ $e('+ Новое поле') }}</PrimaryButton></div>

        <div class="overflow-hidden rounded-xl border border-slate-100 dark:border-slate-800 bg-white dark:bg-slate-900/70 shadow-sm">
            <table class="min-w-full divide-y divide-slate-100 dark:divide-slate-800 text-sm">
                <thead class="bg-slate-50 dark:bg-slate-800/50 text-left text-xs uppercase text-slate-500 dark:text-slate-400">
                    <tr><th class="px-4 py-3">{{ $e('Сущность') }}</th><th class="px-4 py-3">{{ $e('Название') }}</th><th class="px-4 py-3">{{ $e('Тип') }}</th><th class="px-4 py-3">{{ $e('Обязательное') }}</th><th class="px-4 py-3 text-right">{{ $e('Действия') }}</th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    <tr v-for="f in fields" :key="f.id" class="hover:bg-slate-50 dark:hover:bg-slate-800/60">
                        <td class="px-4 py-3">{{ entities[f.entity_type] }}</td>
                        <td class="px-4 py-3 font-medium text-slate-900 dark:text-slate-100">{{ f.name }}</td>
                        <td class="px-4 py-3 text-slate-500 dark:text-slate-400">{{ typeLabels[f.type] }}</td>
                        <td class="px-4 py-3">{{ f.required ? $e('Да') : $e('Нет') }}</td>
                        <td class="px-4 py-3 text-right space-x-2">
                            <button class="text-indigo-600 dark:text-indigo-400 hover:underline" @click="openEdit(f)">{{ $e('Изменить') }}</button>
                            <button class="text-red-600 dark:text-rose-400 hover:underline" @click="destroy(f)">{{ $e('Удалить') }}</button>
                        </td>
                    </tr>
                    <tr v-if="!fields.length"><td colspan="5" class="px-4 py-8 text-center text-slate-400">{{ $e('Полей нет') }}</td></tr>
                </tbody>
            </table>
        </div>

        <Modal :show="show" @close="show = false">
            <div class="p-6">
                <h2 class="mb-4 text-lg font-semibold">{{ editing ? $e('Изменить поле') : $e('Новое поле') }}</h2>
                <div class="space-y-4">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <InputLabel :value="$e('Сущность')" />
                            <select v-model="form.entity_type" class="mt-1 w-full rounded-md border-slate-300 shadow-sm">
                                <option v-for="(label, key) in entities" :key="key" :value="key">{{ label }}</option>
                            </select>
                        </div>
                        <div>
                            <InputLabel :value="$e('Тип')" />
                            <select v-model="form.type" class="mt-1 w-full rounded-md border-slate-300 shadow-sm">
                                <option v-for="t in types" :key="t" :value="t">{{ typeLabels[t] }}</option>
                            </select>
                        </div>
                    </div>
                    <div><InputLabel :value="$e('Название')" /><TextInput v-model="form.name" class="mt-1 w-full" /></div>
                    <div v-if="needsOptions()">
                        <InputLabel :value="$e('Варианты (через запятую)')" />
                        <TextInput v-model="optionsText" class="mt-1 w-full" :placeholder="$e('Вариант1, Вариант2')" />
                    </div>
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" v-model="form.required" class="rounded border-slate-300 text-indigo-600" /> {{ $e('Обязательное') }}
                    </label>
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" v-model="form.is_visible" class="rounded border-slate-300 text-indigo-600" /> {{ $e('Показывать в карточке всегда') }}
                    </label>
                </div>
                <div class="mt-6 flex justify-end gap-2">
                    <SecondaryButton @click="show = false">{{ $e('Отмена') }}</SecondaryButton>
                    <PrimaryButton :disabled="form.processing" @click="submit">{{ $e('Сохранить') }}</PrimaryButton>
                </div>
            </div>
        </Modal>
    </SettingsLayout>
</template>
