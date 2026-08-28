<script setup>
/**
 * Структура компании: дерево отделов, руководители и люди.
 *
 * Это не оргсхема ради красоты. Дерево отвечает на вопрос, который задаёт
 * страница «Права доступа»: что значит «Отдел» и «Отдел и подчинённые».
 * Пока структура плоская, обе области совпадают со «Своими», и руководителю
 * отдела показать нечего — поэтому связь между страницами названа прямо.
 *
 * Рисуем вертикальными уровнями с направляющими, а не свободным полотном:
 * узлы разной глубины должны читаться сверху вниз без панорамирования.
 */
import { computed, provide, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Avatar from '@/Components/Avatar.vue';
import StructureNode from '@/Components/StructureNode.vue';
import Modal from '@/Components/Modal.vue';
import PeoplePicker from '@/Components/PeoplePicker.vue';
import { confirmDialog } from '@/composables/useConfirm';
import { useE } from '@/composables/useTranslations';

const tr = useE();

const props = defineProps({
    departments: { type: Array, default: () => [] },
    unassigned: { type: Array, default: () => [] },
    people: { type: Array, default: () => [] },
    company: { type: String, default: '' },
    can: { type: Object, default: () => ({ manage: false }) },
});

// Дерево из плоского списка: сервер отдаёт строки, форму им придаёт клиент —
// иначе пришлось бы гонять вложенный JSON и повторять сортировку на двух
// сторонах.
const tree = computed(() => {
    const byId = new Map(props.departments.map((d) => [d.id, { ...d, children: [] }]));
    const roots = [];

    for (const node of byId.values()) {
        const parent = node.parent_id ? byId.get(node.parent_id) : null;
        parent ? parent.children.push(node) : roots.push(node);
    }
    return roots;
});

const collapsed = ref(new Set());
const toggle = (id) => {
    const set = new Set(collapsed.value);
    set.has(id) ? set.delete(id) : set.add(id);
    collapsed.value = set;
};

const selected = ref(null);
const pick = (node) => (selected.value = selected.value?.id === node.id ? null : node);

// Обработчики узлам — через provide: прокидывать их пропсами через каждый
// уровень вложенности значит повторять одно и то же на всю глубину дерева.
provide('structure', {
    collapsed, selected, toggle, pick,
    canManage: props.can.manage,
    openCreate: (parentId) => openCreate(parentId),
    addPerson: (node) => (pickerFor.value = node),
});

// Кого добавить в отдел: тот же выбор с вкладками, что в правах доступа.
const pickerFor = ref(null);
const addToDepartment = (ids) => {
    if (!pickerFor.value) return;
    ids.forEach((id) => moveTo(id, pickerFor.value.id));
};

// ---- Форма отдела ----
const showForm = ref(false);
const form = useForm({ id: null, name: '', parent_id: null, head_user_id: '', description: '' });

const openCreate = (parentId = null) => {
    form.reset();
    form.clearErrors();
    form.parent_id = parentId;
    showForm.value = true;
};
const openEdit = (node) => {
    form.clearErrors();
    Object.assign(form, {
        id: node.id, name: node.name, parent_id: node.parent_id,
        head_user_id: node.head_user_id ?? '', description: node.description ?? '',
    });
    showForm.value = true;
};
const submit = () => {
    const done = { preserveScroll: true, onSuccess: () => (showForm.value = false) };
    form.id ? form.put(route('structure.update', form.id), done) : form.post(route('structure.store'), done);
};
const remove = async (node) => {
    if (!(await confirmDialog({
        title: tr('Удалить отдел?'),
        message: `«${node.name}»: подразделения поднимутся на уровень выше, сотрудники останутся без отдела.`,
        confirmText: tr('Удалить'), danger: true,
    }))) return;
    router.delete(route('structure.destroy', node.id), { preserveScroll: true });
};

// Перевод человека в отдел: выпадающий список, а не перетаскивание — мышью
// на телефоне сотрудника не перетащишь, а отдел меняют раз в полгода.
const moveTo = (userId, departmentId) =>
    router.put(route('structure.assign', userId), { department_id: departmentId || null }, { preserveScroll: true });

// Отделы для селекта «подчинён»: себя и своих потомков в список не пускаем —
// такая ссылка порвала бы дерево (сервер это тоже проверяет).
// Кандидаты в отдел: все, кого знает страница. Уже состоящие показаны
// отмеченными — видно, что человек здесь есть, и не надо гадать.
const pickerPeople = computed(() => props.people.map((p) => ({ ...p, roles: [] })));

const parentOptions = computed(() => {
    if (!form.id) return props.departments;
    const banned = new Set();
    const walk = (id) => {
        banned.add(id);
        props.departments.filter((d) => d.parent_id === id).forEach((d) => walk(d.id));
    };
    walk(form.id);
    return props.departments.filter((d) => !banned.has(d.id));
});
</script>

<template>
    <Head :title="$e('Структура компании')" />
    <AppLayout>
        <template #header>{{ $e('Структура компании') }}</template>

        <div class="mx-auto max-w-7xl">
            <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold text-slate-900">{{ company }}</h2>
                    <p class="mt-0.5 text-xs text-slate-400">
                        {{ $e('Дерево отделов задаёт границу областей доступа «Отдел» и «Отдел и подчинённые» —') }}
                        <Link :href="route('access.index')" class="font-semibold text-bx-600 hover:underline">{{ $e('Права доступа') }}</Link>.
                    </p>
                </div>
                <button v-if="can.manage" @click="openCreate(null)"
                    class="rounded-lg bg-bx-500 px-3 py-1.5 text-xs font-semibold text-white transition-colors duration-150 hover:bg-bx-600">{{ $e('+ Отдел') }}</button>
            </div>

            <div class="grid gap-4 lg:grid-cols-[1fr_20rem]">
                <!-- ===== Дерево ===== -->
                <div class="space-y-2">
                    <template v-for="(root, i) in tree" :key="root.id">
                        <StructureNode :node="root" :depth="0" :is-last="i === tree.length - 1" />
                    </template>

                    <div v-if="!tree.length" class="rounded-2xl border border-dashed border-slate-200 px-6 py-16 text-center">
                        <p class="text-sm text-slate-500">{{ $e('Отделов пока нет.') }}</p>
                        <button v-if="can.manage" @click="openCreate(null)" class="mt-3 text-sm font-semibold text-bx-600 hover:underline">{{ $e('Создать первый отдел') }}</button>
                    </div>
                </div>

                <!-- ===== Панель отдела ===== -->
                <div class="lg:sticky lg:top-4 lg:self-start">
                    <div v-if="selected" class="rounded-2xl border border-slate-200/60 bg-white p-5 shadow-sm">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <h3 class="truncate text-[12px] font-semibold text-slate-900">{{ selected.name }}</h3>
                                <p v-if="selected.description" class="mt-0.5 text-xs text-slate-400">{{ selected.description }}</p>
                            </div>
                            <button class="rounded p-1 text-slate-300 hover:text-slate-600" @click="selected = null">✕</button>
                        </div>

                        <div class="mt-4 text-[11px] uppercase tracking-wide text-slate-400">{{ $e('Руководитель') }}</div>
                        <div v-if="selected.head" class="mt-1.5 flex items-center gap-2">
                            <Avatar :name="selected.head.name" :src="selected.head.avatar" :size="28" />
                            <Link :href="route('users.show', selected.head.id)" class="text-[12px] font-medium text-slate-800 hover:text-bx-600">{{ selected.head.name }}</Link>
                        </div>
                        <p v-else class="mt-1.5 text-xs text-slate-400">{{ $e('не назначен — уведомления о просрочках идут в никуда') }}</p>

                        <div class="mt-4 flex items-baseline justify-between">
                            <span class="text-[11px] uppercase tracking-wide text-slate-400">{{ $e('Сотрудники') }}</span>
                            <span class="text-xs font-semibold text-slate-400">{{ selected.people.length }}</span>
                        </div>
                        <div class="mt-1.5 space-y-1">
                            <div v-for="p in selected.people" :key="p.id" class="flex items-center gap-2">
                                <Avatar :name="p.name" :src="p.avatar" :size="24" />
                                <div class="min-w-0 flex-1">
                                    <Link :href="route('users.show', p.id)" class="block truncate text-[12px] text-slate-700 hover:text-bx-600">{{ p.name }}</Link>
                                    <div class="truncate text-[11px] text-slate-400">{{ p.role || $e('роль не назначена') }}</div>
                                </div>
                                <button v-if="can.manage" @click="moveTo(p.id, null)" :title="$e('Убрать из отдела')"
                                    class="rounded p-1 text-slate-300 transition-colors duration-150 hover:text-rose-600">✕</button>
                            </div>
                            <p v-if="!selected.people.length" class="py-2 text-xs text-slate-400">{{ $e('В отделе пока никого нет') }}</p>
                        </div>

                        <div v-if="can.manage" class="mt-5 flex gap-2 border-t border-slate-100 pt-4">
                            <button @click="openEdit(selected)" class="rounded-lg border border-slate-200 px-2.5 py-1 text-[11px] font-semibold text-slate-600 hover:bg-slate-50">{{ $e('Изменить') }}</button>
                            <button @click="openCreate(selected.id)" class="rounded-lg border border-slate-200 px-2.5 py-1 text-[11px] font-semibold text-slate-600 hover:bg-slate-50">{{ $e('+ Подотдел') }}</button>
                            <button @click="remove(selected)" class="ml-auto rounded-lg px-2.5 py-1 text-[11px] font-semibold text-slate-400 hover:text-rose-600">{{ $e('Удалить') }}</button>
                        </div>
                    </div>

                    <!-- Без отдела: иначе эти люди тихо выпадают из структуры
                         и ни в одну область доступа не попадают. -->
                    <div v-if="unassigned.length" class="mt-4 rounded-2xl border border-amber-200 bg-amber-50/50 p-5">
                        <div class="text-[12px] font-semibold text-amber-900">{{ $e('Без отдела') }} · {{ unassigned.length }}</div>
                        <p class="mt-0.5 text-[11px] text-amber-700">{{ $e('Их не видно в дереве, и области «Отдел» на них не действуют.') }}</p>
                        <div class="mt-3 space-y-2">
                            <div v-for="p in unassigned" :key="p.id" class="flex items-center gap-2">
                                <Avatar :name="p.name" :src="p.avatar" :size="24" />
                                <span class="min-w-0 flex-1 truncate text-[12px] text-slate-700">{{ p.name }}</span>
                                <select v-if="can.manage" @change="moveTo(p.id, $event.target.value)"
                                    class="w-28 rounded-lg border-slate-200 py-1 text-xs shadow-sm">
                                    <option value="">{{ $e('в отдел…') }}</option>
                                    <option v-for="d in departments" :key="d.id" :value="d.id">{{ d.name }}</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div v-if="!selected && !unassigned.length" class="rounded-2xl border border-dashed border-slate-200 px-5 py-10 text-center text-sm text-slate-400">
                        {{ $e('Выберите отдел, чтобы увидеть людей') }}
                    </div>
                </div>
            </div>
        </div>

        <PeoplePicker :show="!!pickerFor" :title="pickerFor ? $e('В отдел') + ' «' + pickerFor.name + '»' : ''"
            :people="pickerPeople" :departments="departments" :roles="[]"
            :members="pickerFor?.people ?? []"
            :selected="pickerFor ? pickerFor.people.map((p) => p.id) : []"
            @close="pickerFor = null" @pick="addToDepartment"
            @remove="(id) => moveTo(id, null)" />

        <!-- Отдел -->
        <Modal :show="showForm" max-width="lg" @close="showForm = false">
            <div class="p-6">
                <h2 class="mb-1 text-lg font-semibold text-slate-900">{{ form.id ? $e('Изменить отдел') : $e('Новый отдел') }}</h2>
                <p class="mb-5 text-xs text-slate-400">{{ $e('Руководитель получает уведомления о просрочках отдела и его область доступа.') }}</p>

                <div class="space-y-4">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">{{ $e('Название') }}</label>
                        <input v-model="form.name" type="text" class="w-full rounded-lg border-slate-200 text-sm shadow-sm focus:border-bx-500 focus:ring-2 focus:ring-bx-500/20" />
                        <p v-if="form.errors.name" class="mt-1 text-xs text-rose-600">{{ form.errors.name }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">{{ $e('Подчинён отделу') }}</label>
                        <select v-model="form.parent_id" class="w-full rounded-lg border-slate-200 text-sm shadow-sm">
                            <option :value="null">{{ $e('— верхний уровень') }}</option>
                            <option v-for="d in parentOptions" :key="d.id" :value="d.id">{{ d.name }}</option>
                        </select>
                        <p v-if="form.errors.parent_id" class="mt-1 text-xs text-rose-600">{{ form.errors.parent_id }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">{{ $e('Руководитель') }}</label>
                        <select v-model="form.head_user_id" class="w-full rounded-lg border-slate-200 text-sm shadow-sm">
                            <option value="">{{ $e('— не назначен') }}</option>
                            <option v-for="p in people" :key="p.id" :value="p.id">{{ p.name }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">{{ $e('Описание') }}</label>
                        <input v-model="form.description" type="text" class="w-full rounded-lg border-slate-200 text-sm shadow-sm" />
                    </div>
                </div>

                <div class="mt-6 flex justify-end gap-2">
                    <button @click="showForm = false" class="rounded-lg px-4 py-2 text-sm font-medium text-slate-500 hover:text-slate-700">{{ $e('Отмена') }}</button>
                    <button :disabled="form.processing" @click="submit"
                        class="rounded-lg bg-bx-500 px-4 py-2 text-sm font-semibold text-white transition-colors duration-150 hover:bg-bx-600 disabled:opacity-50">
                        {{ form.id ? $e('Сохранить') : $e('Создать отдел') }}
                    </button>
                </div>
            </div>
        </Modal>
    </AppLayout>
</template>
