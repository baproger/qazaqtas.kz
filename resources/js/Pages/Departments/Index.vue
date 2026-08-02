<script setup>
import { ref } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { confirmDialog } from '@/composables/useConfirm';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import Pagination from '@/Components/Pagination.vue';

const props = defineProps({
    departments: Object,
    filters: Object,
    can: Object,
    users: { type: Array, default: () => [] },
});

const showModal = ref(false);
const editing = ref(null);
const search = ref(props.filters.search ?? '');

const form = useForm({ name: '', description: '', head_user_id: '', is_active: true });

const openCreate = () => {
    editing.value = null;
    form.reset();
    form.is_active = true;
    showModal.value = true;
};
const openEdit = (d) => {
    editing.value = d;
    form.name = d.name;
    form.description = d.description ?? '';
    form.head_user_id = d.head_user_id ?? '';
    form.is_active = d.is_active;
    showModal.value = true;
};
const submit = () => {
    const opts = { onSuccess: () => (showModal.value = false), preserveScroll: true };
    if (editing.value) form.put(route('departments.update', editing.value.id), opts);
    else form.post(route('departments.store'), opts);
};
const destroy = async (d) => {
    if (await confirmDialog({ title: 'Удалить отдел', message: `Отдел «${d.name}» будет удалён.`, confirmText: 'Удалить', danger: true })) {
        router.delete(route('departments.destroy', d.id), { preserveScroll: true });
    }
};
const doSearch = () => router.get(route('departments.index'), { search: search.value }, { preserveState: true, replace: true });
</script>

<template>
    <Head title="Отделы" />
    <AppLayout>
        <template #header>{{ $t('page.departments', 'Отделы') }}</template>

        <div class="mb-4 flex items-center justify-between gap-3">
            <TextInput v-model="search" placeholder="Поиск..." class="w-64" @keyup.enter="doSearch" />
            <PrimaryButton v-if="can.create" @click="openCreate">+ Добавить отдел</PrimaryButton>
        </div>

        <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-slate-100 text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase text-slate-500">
                    <tr>
                        <th class="px-4 py-3">Название</th>
                        <th class="px-4 py-3">Описание</th>
                        <th class="px-4 py-3">Руководитель</th>
                        <th class="px-4 py-3">Сотрудников</th>
                        <th class="px-4 py-3">Статус</th>
                        <th class="px-4 py-3 text-right">Действия</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="d in departments.data" :key="d.id" class="hover:bg-slate-50">
                        <td class="px-4 py-3 font-medium text-slate-900">{{ d.name }}</td>
                        <td class="px-4 py-3 text-slate-500">{{ d.description }}</td>
                        <td class="px-4 py-3 text-slate-600">
                            <span v-if="d.head">⭐ {{ d.head.name }}</span>
                            <span v-else class="text-slate-400">—</span>
                        </td>
                        <td class="px-4 py-3">{{ d.members_count }}</td>
                        <td class="px-4 py-3">
                            <span :class="d.is_active ? 'text-green-600' : 'text-slate-400'">
                                {{ d.is_active ? 'Активен' : 'Отключён' }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-right space-x-2">
                            <button v-if="can.update" class="text-indigo-600 hover:underline" @click="openEdit(d)">Изменить</button>
                            <button v-if="can.delete" class="text-red-600 hover:underline" @click="destroy(d)">Удалить</button>
                        </td>
                    </tr>
                    <tr v-if="!departments.data.length">
                        <td colspan="6" class="px-4 py-8 text-center text-slate-400">Нет данных</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            <Pagination :links="departments.links" />
        </div>

        <Modal :show="showModal" @close="showModal = false">
            <div class="p-6">
                <h2 class="mb-4 text-lg font-semibold">{{ editing ? 'Изменить отдел' : 'Новый отдел' }}</h2>
                <div class="space-y-4">
                    <div>
                        <InputLabel value="Название" />
                        <TextInput v-model="form.name" class="mt-1 w-full" />
                        <InputError :message="form.errors.name" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel value="Описание" />
                        <textarea v-model="form.description" rows="3" class="mt-1 w-full rounded-md border-slate-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"></textarea>
                    </div>
                    <div>
                        <InputLabel value="Руководитель отдела (⭐ + уведомления о просрочках отдела)" />
                        <select v-model="form.head_user_id" class="mt-1 w-full rounded-md border-slate-300 shadow-sm">
                            <option value="">—</option>
                            <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option>
                        </select>
                        <InputError :message="form.errors.head_user_id" class="mt-1" />
                    </div>
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" v-model="form.is_active" class="rounded border-slate-300 text-indigo-600" />
                        Активен
                    </label>
                </div>
                <div class="mt-6 flex justify-end gap-2">
                    <SecondaryButton @click="showModal = false">Отмена</SecondaryButton>
                    <PrimaryButton :disabled="form.processing" @click="submit">Сохранить</PrimaryButton>
                </div>
            </div>
        </Modal>
    </AppLayout>
</template>
