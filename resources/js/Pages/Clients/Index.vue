<script setup>
import { ref } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { confirmDialog } from '@/composables/useConfirm';
import AppLayout from '@/Layouts/AppLayout.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import Pagination from '@/Components/Pagination.vue';

const props = defineProps({ clients: Object, filters: Object, users: Array, can: Object });

const showModal = ref(false);
const editing = ref(null);
const search = ref(props.filters.search ?? '');

const form = useForm({
    name: '', type: 'legal', inn: '', kpp: '', phone: '', email: '',
    address: '', website: '', note: '', responsible_user_id: '',
});

const openCreate = () => { editing.value = null; form.reset(); form.type = 'legal'; showModal.value = true; };
const openEdit = (c) => {
    editing.value = c;
    Object.assign(form, {
        name: c.name, type: c.type, inn: c.inn ?? '', kpp: c.kpp ?? '', phone: c.phone ?? '',
        email: c.email ?? '', address: c.address ?? '', website: c.website ?? '', note: c.note ?? '',
        responsible_user_id: c.responsible_user_id ?? '',
    });
    showModal.value = true;
};
const submit = () => {
    const opts = { onSuccess: () => (showModal.value = false), preserveScroll: true };
    if (editing.value) form.put(route('clients.update', editing.value.id), opts);
    else form.post(route('clients.store'), opts);
};
const destroy = async (c) => { if (await confirmDialog({ title: 'Удалить контрагента', message: `Контрагент «${c.name}» будет удалён.`, confirmText: 'Удалить', danger: true })) router.delete(route('clients.destroy', c.id), { preserveScroll: true }); };
const doSearch = () => router.get(route('clients.index'), { search: search.value }, { preserveState: true, replace: true });
</script>

<template>
    <Head title="Контрагенты" />
    <AppLayout>
        <template #header>{{ $t('page.clients', 'Контрагенты') }}</template>

        <div class="mb-4 flex items-center justify-between gap-3">
            <TextInput v-model="search" placeholder="Поиск по имени/ИНН/телефону..." class="w-80" @keyup.enter="doSearch" />
            <PrimaryButton v-if="can.create" @click="openCreate">+ Добавить контрагента</PrimaryButton>
        </div>

        <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-slate-100 text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase text-slate-500">
                    <tr>
                        <th class="px-4 py-3">Название</th>
                        <th class="px-4 py-3">Тип</th>
                        <th class="px-4 py-3">ИНН</th>
                        <th class="px-4 py-3">Контакты</th>
                        <th class="px-4 py-3">Ответственный</th>
                        <th class="px-4 py-3 text-right">Действия</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="c in clients.data" :key="c.id" class="hover:bg-slate-50">
                        <td class="px-4 py-3 font-medium text-slate-900">{{ c.name }}</td>
                        <td class="px-4 py-3"><StatusBadge :status="c.type" /></td>
                        <td class="px-4 py-3 text-slate-500">{{ c.inn }}</td>
                        <td class="px-4 py-3 text-slate-500">
                            <div>{{ c.phone }}</div>
                            <div class="text-xs">{{ c.email }}</div>
                        </td>
                        <td class="px-4 py-3 text-slate-500">{{ c.responsible?.name ?? '—' }}</td>
                        <td class="px-4 py-3 text-right space-x-2">
                            <button v-if="can.update" class="text-indigo-600 hover:underline" @click="openEdit(c)">Изменить</button>
                            <button v-if="can.delete" class="text-red-600 hover:underline" @click="destroy(c)">Удалить</button>
                        </td>
                    </tr>
                    <tr v-if="!clients.data.length"><td colspan="6" class="px-4 py-8 text-center text-slate-400">Нет данных</td></tr>
                </tbody>
            </table>
        </div>
        <div class="mt-4"><Pagination :links="clients.links" /></div>

        <Modal :show="showModal" @close="showModal = false" max-width="2xl">
            <div class="p-6">
                <h2 class="mb-4 text-lg font-semibold">{{ editing ? 'Изменить контрагента' : 'Новый контрагент' }}</h2>
                <div class="grid grid-cols-2 gap-4">
                    <div class="col-span-2">
                        <InputLabel value="Название" />
                        <TextInput v-model="form.name" class="mt-1 w-full" />
                        <InputError :message="form.errors.name" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel value="Тип" />
                        <select v-model="form.type" class="mt-1 w-full rounded-md border-slate-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <option value="legal">Юридическое лицо</option>
                            <option value="individual">Физическое лицо</option>
                        </select>
                    </div>
                    <div>
                        <InputLabel value="Ответственный" />
                        <select v-model="form.responsible_user_id" class="mt-1 w-full rounded-md border-slate-300 shadow-sm">
                            <option value="">—</option>
                            <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option>
                        </select>
                    </div>
                    <div><InputLabel value="ИНН/БИН" /><TextInput v-model="form.inn" class="mt-1 w-full" /></div>
                    <div><InputLabel value="КПП" /><TextInput v-model="form.kpp" class="mt-1 w-full" /></div>
                    <div><InputLabel value="Телефон" /><TextInput v-model="form.phone" class="mt-1 w-full" /></div>
                    <div>
                        <InputLabel value="Email" />
                        <TextInput v-model="form.email" type="email" class="mt-1 w-full" />
                        <InputError :message="form.errors.email" class="mt-1" />
                    </div>
                    <div><InputLabel value="Адрес" /><TextInput v-model="form.address" class="mt-1 w-full" /></div>
                    <div><InputLabel value="Сайт" /><TextInput v-model="form.website" class="mt-1 w-full" /></div>
                    <div class="col-span-2">
                        <InputLabel value="Заметка" />
                        <textarea v-model="form.note" rows="2" class="mt-1 w-full rounded-md border-slate-300 shadow-sm"></textarea>
                    </div>
                </div>
                <div class="mt-6 flex justify-end gap-2">
                    <SecondaryButton @click="showModal = false">Отмена</SecondaryButton>
                    <PrimaryButton :disabled="form.processing" @click="submit">Сохранить</PrimaryButton>
                </div>
            </div>
        </Modal>
    </AppLayout>
</template>
