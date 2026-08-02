<script setup>
import { ref } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import { confirmDialog } from '@/composables/useConfirm';
import Modal from '@/Components/Modal.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import { deadlineClass, isPastDue } from '@/utils/deadline';
import { formatDateTime } from '@/utils/format';

const props = defineProps({
    tasks: { type: Array, default: () => [] },
    taskableType: String,
    taskableId: Number,
    users: { type: Array, default: () => [] },
});

const adding = ref(false);
const form = useForm({
    title: '', assignee_id: '', priority: 'medium', due_date: '',
    taskable_type: props.taskableType, taskable_id: props.taskableId,
});
const add = () => form.post(route('tasks.store'), {
    preserveScroll: true,
    onSuccess: () => { form.reset('title', 'assignee_id', 'due_date'); adding.value = false; },
});

const cycle = { new: 'in_progress', in_progress: 'review', review: 'done', done: 'new' };
const advance = (t) => router.patch(route('tasks.status', t.id), { status: cycle[t.status] }, { preserveScroll: true });
const remove = async (t) => { if (await confirmDialog({ title: 'Удалить задачу', message: 'Задача будет удалена.', confirmText: 'Удалить', danger: true })) router.delete(route('tasks.destroy', t.id), { preserveScroll: true }); };
const forInput = (v) => v ? new Date(v).toISOString().slice(0, 16) : '';

// Edit
const editing = ref(null);
const editForm = useForm({ title: '', assignee_id: '', priority: 'medium', status: 'new', due_date: '', note: '', description: '' });
const openEdit = (t) => {
    editing.value = t;
    Object.assign(editForm, {
        title: t.title, assignee_id: t.assignee_id ?? '', priority: t.priority, status: t.status,
        due_date: forInput(t.due_date), note: t.note ?? '', description: t.description ?? '',
    });
};
const saveEdit = () => editForm.put(route('tasks.update', editing.value.id), { preserveScroll: true, onSuccess: () => (editing.value = null) });
</script>

<template>
    <div class="space-y-2">
        <div v-for="t in tasks" :key="t.id" class="flex items-center justify-between rounded-xl px-4 py-3 text-sm transition-colors duration-150"
            :class="isPastDue(t.due_date, t.status==='done') ? 'bg-rose-50 ring-1 ring-inset ring-rose-200' : 'bg-slate-50'">
            <div class="min-w-0">
                <div class="font-medium text-slate-900">{{ t.title }}</div>
                <div class="text-xs text-slate-400">
                    {{ t.assignee?.name ?? 'Без исполнителя' }}<span v-if="t.due_date" :class="deadlineClass(t.due_date, t.status==='done')"> · {{ isPastDue(t.due_date, t.status==='done') ? 'просрочено ' : '' }}{{ formatDateTime(t.due_date) }}</span>
                </div>
            </div>
            <div class="flex items-center gap-1.5">
                <button @click="advance(t)" title="Сменить статус"><StatusBadge :status="t.status" /></button>
                <button class="rounded p-1 text-slate-400 transition-colors duration-150 hover:text-indigo-600" title="Редактировать" @click="openEdit(t)">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.85 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5z"/></svg>
                </button>
                <button class="rounded p-1 text-slate-400 transition-colors duration-150 hover:text-rose-600" title="Удалить" @click="remove(t)">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6M10 11v6M14 11v6"/></svg>
                </button>
            </div>
        </div>
        <div v-if="!tasks.length" class="flex flex-col items-center gap-2 py-6 text-center">
            <svg class="h-10 w-10 text-slate-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="6" height="6" rx="1"/><path d="m4 16 2 2 4-4M11 6h10M11 11h10M11 18h10"/></svg>
            <span class="text-sm text-slate-400">Задач пока нет</span>
        </div>

        <div v-if="adding" class="rounded-xl border border-dashed border-slate-300 p-4">
            <TextInput v-model="form.title" placeholder="Название задачи" class="mb-2 w-full" />
            <div class="mb-2 grid grid-cols-2 gap-2">
                <select v-model="form.assignee_id" class="rounded-lg border-slate-300 text-sm transition duration-150 focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20">
                    <option value="">Исполнитель…</option>
                    <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option>
                </select>
                <select v-model="form.priority" class="rounded-lg border-slate-300 text-sm transition duration-150 focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20">
                    <option value="low">Низкий</option><option value="medium">Средний</option><option value="high">Высокий</option>
                </select>
            </div>
            <TextInput v-model="form.due_date" type="datetime-local" class="mb-2 w-full" />
            <div class="flex gap-2">
                <PrimaryButton :disabled="form.processing || !form.title" @click="add">Добавить</PrimaryButton>
                <button class="text-sm text-slate-500 transition-colors duration-150 hover:text-slate-700" @click="adding = false">Отмена</button>
            </div>
        </div>
        <button v-else
            :class="!tasks.length
                ? 'mx-auto flex rounded-lg bg-indigo-600 px-4 py-2 text-xs font-semibold text-white transition-colors duration-150 hover:bg-indigo-700'
                : 'text-sm font-medium text-indigo-600 transition-colors duration-150 hover:text-indigo-800'"
            @click="adding = true">+ Добавить задачу</button>

        <!-- Edit modal -->
        <Modal :show="!!editing" @close="editing = null">
            <div class="p-6">
                <h2 class="mb-4 text-lg font-semibold">Редактировать задачу</h2>
                <div class="space-y-3">
                    <div><InputLabel value="Название" /><TextInput v-model="editForm.title" class="mt-1 w-full" /></div>
                    <div class="grid grid-cols-3 gap-3">
                        <div>
                            <InputLabel value="Исполнитель" />
                            <select v-model="editForm.assignee_id" class="mt-1 w-full rounded-lg border-slate-300 text-sm transition duration-150 focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20">
                                <option value="">—</option>
                                <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option>
                            </select>
                        </div>
                        <div>
                            <InputLabel value="Приоритет" />
                            <select v-model="editForm.priority" class="mt-1 w-full rounded-lg border-slate-300 text-sm transition duration-150 focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20">
                                <option value="low">Низкий</option><option value="medium">Средний</option><option value="high">Высокий</option>
                            </select>
                        </div>
                        <div>
                            <InputLabel value="Статус" />
                            <select v-model="editForm.status" class="mt-1 w-full rounded-lg border-slate-300 text-sm transition duration-150 focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20">
                                <option value="new">Новая</option><option value="in_progress">В работе</option>
                                <option value="review">Проверка</option><option value="done">Готово</option>
                            </select>
                        </div>
                    </div>
                    <div><InputLabel value="Срок (дата и время)" /><TextInput v-model="editForm.due_date" type="datetime-local" class="mt-1 w-full" /></div>
                    <div><InputLabel value="Заметка" /><textarea v-model="editForm.note" rows="2" class="mt-1 w-full rounded-lg border-slate-300 text-sm transition duration-150 focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20"></textarea></div>
                    <div><InputLabel value="Описание" /><textarea v-model="editForm.description" rows="2" class="mt-1 w-full rounded-lg border-slate-300 text-sm transition duration-150 focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20"></textarea></div>
                </div>
                <div class="mt-6 flex justify-end gap-2">
                    <SecondaryButton @click="editing = null">Отмена</SecondaryButton>
                    <PrimaryButton :disabled="editForm.processing" @click="saveEdit">Сохранить</PrimaryButton>
                </div>
            </div>
        </Modal>
    </div>
</template>
