<script setup>
import { ref, computed, watch } from 'vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { confirmDialog } from '@/composables/useConfirm';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import { useE } from '@/composables/useTranslations';

const tr = useE();

const props = defineProps({
    dealStages: Array, projectStages: Array,
    companies: Array, selectedCompanyId: Number,
    stageTypes: Object, gateRoles: Object, missingTypes: Object,
    stageTypeHints: { type: Object, default: () => ({}) },
    typeOwners: { type: Object, default: () => ({}) },
});

// Готовая палитра — админ выбирает цвет в один клик, без возни с пипеткой.
const PALETTE = ['#6366F1', '#3B82F6', '#0EA5E9', '#14B8A6', '#10B981', '#84CC16', '#F59E0B', '#F97316', '#EF4444', '#EC4899', '#8B5CF6', '#64748B'];

// Выбор воронки: фирма × вид (сделки | цех).
const funnel = ref(props.selectedCompanyId);
const kindTab = ref('deal');
const isWorkshop = computed(() => kindTab.value === 'project');
const kind = computed(() => kindTab.value);
// Существующие цеха фирмы — подсказки в поле.
const workshopNames = computed(() => [...new Set((props.projectStages ?? []).map((s) => s.workshop).filter(Boolean))]);

// Подвкладки цехов: каждый цех настраивается ОТДЕЛЬНО (свой список этапов).
const workshopTabs = computed(() => {
    const tabs = workshopNames.value.map((w) => ({ key: w, label: w }));
    if (!tabs.length || (props.projectStages ?? []).some((s) => s.company_id && !s.workshop)) tabs.push({ key: '', label: tr('Единый цех') });
    return tabs;
});
const workshopTab = ref(null);
const activeWs = computed(() => {
    const keys = workshopTabs.value.map((t) => t.key);
    return workshopTab.value !== null && keys.includes(workshopTab.value) ? workshopTab.value : (keys[0] ?? '');
});
const stages = computed(() => (isWorkshop.value
    ? (props.projectStages ?? []).filter((s) => (s.workshop ?? '') === activeWs.value)
    : props.dealStages));

const switchFunnel = (v) => {
    funnel.value = v;
    router.get(route('stages.index'), { company: v }, { preserveState: true, preserveScroll: true, replace: true });
};

// Добавление
const newForm = useForm({ kind: 'deal', name: '', color: '#6366F1', workshop: '' });
const adding = ref(false);
const startAdd = () => { adding.value = true; editing.value = null; editingType.value = ''; newForm.reset(); newForm.kind = kind.value; newForm.color = '#6366F1'; newForm.workshop = isWorkshop.value ? activeWs.value : ''; };
const add = () => newForm
    .transform((d) => ({ ...d, kind: kind.value }))
    .post(route('stages.store', { company: funnel.value }), { preserveScroll: true, onSuccess: () => (adding.value = false) });

/*
 * Порядок этапов.
 *
 * Список показывается из локальной копии: перетащили — строки встают на
 * место сразу, не дожидаясь ответа сервера. Наверх уходит весь порядок
 * целиком, поэтому и стрелки, и мышь делают ровно одно и то же действие, а
 * номера всегда получаются 1..N без дыр.
 */
const localOrder = ref(null);
const orderedStages = computed(() => {
    if (!localOrder.value) return stages.value;
    const byId = new Map(stages.value.map((s) => [s.id, s]));

    return localOrder.value.map((id) => byId.get(id)).filter(Boolean);
});

// Сервер прислал новый список — своя раскладка больше не нужна.
watch(() => [stages.value, activeWs.value, funnel.value], () => (localOrder.value = null));

const saveOrder = (ids) => {
    localOrder.value = ids;
    router.patch(route('stages.reorder', kind.value), {
        ids,
        company: funnel.value,
        workshop: isWorkshop.value ? activeWs.value : null,
    }, {
        preserveScroll: true,
        preserveState: true,
        // Сервер отказал (список успели поменять) — снимаем свою раскладку,
        // чтобы на экране не осталось порядка, которого нет в базе.
        onError: () => (localOrder.value = null),
    });
};

const moveBy = (index, delta) => {
    const ids = orderedStages.value.map((s) => s.id);
    const to = index + delta;
    if (to < 0 || to >= ids.length) return;

    ids.splice(to, 0, ids.splice(index, 1)[0]);
    saveOrder(ids);
};

/** Перетаскивание: свой индекс держим в состоянии — данные drag-события в
 *  Safari читаются только при drop, и подсветка строки без него не работает. */
const dragFrom = ref(null);
const dragOver = ref(null);

const onDragStart = (index, event) => {
    dragFrom.value = index;
    event.dataTransfer.effectAllowed = 'move';
    // Firefox не начинает перетаскивание с пустым dataTransfer.
    event.dataTransfer.setData('text/plain', String(index));
};

const onDrop = (index) => {
    const from = dragFrom.value;
    dragFrom.value = null;
    dragOver.value = null;
    if (from === null || from === index) return;

    const ids = orderedStages.value.map((s) => s.id);
    ids.splice(index, 0, ids.splice(from, 1)[0]);
    saveOrder(ids);
};

// Редактор этапа: имя + цвет + (для сделок) тип и гейт / (для цеха) завершающий.
const editing = ref(null);
// Тип, который этап держал на момент открытия формы: его нужно оставить в
// списке, иначе собственный тип этапа пропал бы из выбора.
const editingType = ref('');
const editForm = useForm({ name: '', color: '#6366F1', stage_type: '', gate_task_title: '', gate_task_role: 'financist', gate_task_days: '', is_completed: false, requires_document: false, workshop: '' });
const startEdit = (stage) => {
    editing.value = stage.id;
    adding.value = false;
    editForm.clearErrors();
    editForm.name = stage.name;
    editForm.color = stage.color || '#6366F1';
    editForm.stage_type = stage.stage_type ?? '';
    editingType.value = stage.stage_type ?? '';
    editForm.gate_task_title = stage.gate_task_title ?? '';
    editForm.gate_task_role = stage.gate_task_role ?? 'financist';
    editForm.gate_task_days = stage.gate_task_days ?? '';
    editForm.is_completed = !!stage.is_completed;
    editForm.requires_document = !!stage.requires_document;
    editForm.workshop = stage.workshop ?? '';
};
const saveEdit = (stage) => editForm
    .transform((d) => isWorkshop.value
        ? { name: d.name, color: d.color, is_completed: d.is_completed, workshop: d.workshop || null }
        : {
            name: d.name, color: d.color,
            stage_type: d.stage_type || null,
            gate_task_title: d.gate_task_title || null,
            gate_task_role: d.gate_task_role || null,
            gate_task_days: d.gate_task_days || null,
            requires_document: d.requires_document,
        })
    .put(route('stages.update', [kind.value, stage.id]), { preserveScroll: true, onSuccess: () => (editing.value = null) });

// Удаление: если на этапе есть активные сделки/заказы — выбор этапа для переноса.
const removing = ref(null);
const transferTo = ref('');
const removeErr = ref('');
const occupants = (s) => (isWorkshop.value ? (s.projects_count ?? 0) : (s.active_deals_count ?? 0));
const startRemove = async (stage) => {
    removeErr.value = '';
    if (!occupants(stage)) {
        if (await confirmDialog({ title: tr('Удалить этап'), message: `Этап «${stage.name}» будет удалён.`, confirmText: tr('Удалить'), danger: true })) {
            router.delete(route('stages.destroy', [kind.value, stage.id]), { preserveScroll: true });
        }
        return;
    }
    removing.value = stage.id;
    transferTo.value = '';
};
const confirmRemove = (stage) => router.delete(route('stages.destroy', [kind.value, stage.id]), {
    data: { transfer_to: transferTo.value },
    preserveScroll: true,
    onSuccess: () => (removing.value = null),
    onError: (e) => (removeErr.value = e.transfer_to ?? ''),
});

const typeBadge = (s) => s.stage_type ? (props.stageTypes[s.stage_type] ?? s.stage_type) : null;
// Тип уникален в воронке. Занятый другим этапом выбрать нельзя, поэтому в
// списке его нет — вместо неактивного пункта подписываем, кто его держит.
const availableTypes = computed(() => Object.fromEntries(
    Object.entries(props.stageTypes).filter(([t]) => !props.typeOwners[t] || t === editingType.value),
));
const takenTypes = computed(() => Object.entries(props.typeOwners)
    .filter(([t]) => t !== editingType.value)
    .map(([type, stage]) => ({ type, stage, label: props.stageTypes[type] ?? type })));
const companyName = computed(() => props.companies.find((c) => c.id === funnel.value)?.name ?? '');
</script>

<template>
    <Head :title="$e('Этапы')" />
    <AppLayout>
        <template #header>{{ $t('page.settings_stages', 'Настройки · Этапы') }}</template>

        <div class="mb-5 flex gap-1 border-b border-slate-200">
            <Link :href="route('settings.index')" class="px-3 py-2 text-sm text-slate-500 hover:text-slate-700">{{ $e('Общие') }}</Link>
            <Link :href="route('stages.index')" class="border-b-2 border-indigo-600 px-3 py-2 text-sm font-medium text-indigo-600">{{ $e('Этапы') }}</Link>
            <Link :href="route('screens.index')" class="px-3 py-2 text-sm text-slate-500 hover:text-slate-700">{{ $e('Экраны') }}</Link>
            <Link :href="route('custom-fields.index')" class="px-3 py-2 text-sm text-slate-500 hover:text-slate-700">{{ $e('Доп. поля') }}</Link>
            <Link :href="route('siteSettings.index')" class="px-3 py-2 text-sm text-slate-500 hover:text-slate-700">{{ $e('Сайт') }}</Link>
            <Link v-if="$page.props.auth.user?.roles?.includes('admin')" :href="route('access.index')" class="px-3 py-2 text-sm text-slate-500 hover:text-slate-700">{{ $e('Права доступа') }}</Link>
            <Link :href="route('structure.index')" class="px-3 py-2 text-sm text-slate-500 hover:text-slate-700">{{ $e('Структура') }}</Link>
        </div>

        <!-- Выбор воронки: компания + (сделки | цех) -->
        <div class="mb-5 flex flex-wrap items-center gap-3">
            <div class="inline-flex rounded-xl bg-slate-100 p-1">
                <button v-for="c in companies" :key="c.id" type="button" @click="switchFunnel(c.id)"
                    class="rounded-lg px-4 py-1.5 text-sm font-semibold transition-all"
                    :class="funnel === c.id ? 'bg-white text-indigo-700 shadow-sm' : 'text-slate-500 hover:text-slate-700'">
                    {{ c.name }}
                </button>
            </div>
            <div class="inline-flex rounded-xl bg-slate-100 p-1">
                <button type="button" @click="kindTab = 'deal'"
                    class="rounded-lg px-4 py-1.5 text-sm font-semibold transition-all"
                    :class="!isWorkshop ? 'bg-white text-indigo-700 shadow-sm' : 'text-slate-500 hover:text-slate-700'">
                    {{ $e('Воронка сделок') }}
                </button>
                <button type="button" @click="kindTab = 'project'"
                    class="rounded-lg px-4 py-1.5 text-sm font-semibold transition-all"
                    :class="isWorkshop ? 'bg-white text-indigo-700 shadow-sm' : 'text-slate-500 hover:text-slate-700'">
                    {{ $e('Цех') }}
                </button>
            </div>
            <!-- Выбор цеха: если цехов несколько — настраиваются отдельно -->
            <div v-if="isWorkshop && workshopTabs.length > 1" class="inline-flex rounded-xl bg-slate-100 p-1">
                <button v-for="t in workshopTabs" :key="t.key" type="button" @click="workshopTab = t.key"
                    class="rounded-lg px-4 py-1.5 text-sm font-semibold transition-all"
                    :class="activeWs === t.key ? 'bg-white text-sky-700 shadow-sm' : 'text-slate-500 hover:text-slate-700'">
                    {{ t.label }}
                </button>
            </div>
        </div>

        <!-- Предупреждение о незаданных обязательных типах -->
        <div v-if="!isWorkshop && Object.keys(missingTypes).length" class="mb-4 flex gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm">
            <span class="text-lg leading-none">⚠️</span>
            <div class="text-amber-800">
                <b>{{ $e('Не назначены системные типы:') }}</b> {{ Object.values(missingTypes).join(' · ') }}.
                <div class="mt-1 text-xs text-amber-700">{{ $e('Без «Оплата успешно» сделки не считаются успешными (деньги/ЗП/аналитика); без «Закуп/цех» и «Логистика» не работает отправка в цех и возврат. Назначьте тип через «Изменить».') }}</div>
            </div>
        </div>

        <div class="rounded-2xl border border-slate-100 bg-white shadow-sm">
            <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4">
                <div>
                    <h3 class="font-semibold text-slate-900">{{ isWorkshop ? $e('Этапы — ') + (activeWs || $e('единый цех')) : $e('Воронка сделок') }}</h3>
                    <p class="text-xs text-slate-400">{{ companyName }} {{ $e('· порядок — перетаскиванием за ⠿ или стрелками') }}</p>
                </div>
                <PrimaryButton @click="startAdd">{{ $e('+ Добавить этап') }}</PrimaryButton>
            </div>

            <!-- Форма добавления -->
            <div v-if="adding" class="border-b border-slate-100 bg-indigo-50/40 px-5 py-4">
                <div class="flex flex-wrap items-end gap-3">
                    <div class="flex-1 min-w-[200px]">
                        <InputLabel :value="$e('Название этапа')" />
                        <TextInput v-model="newForm.name" :placeholder="$e('Например: Замер')" class="mt-1 w-full" @keyup.enter="add" />
                    </div>
                    <div>
                        <InputLabel :value="$e('Цвет')" />
                        <div class="mt-1 flex items-center gap-1.5">
                            <button v-for="c in PALETTE" :key="c" type="button" @click="newForm.color = c"
                                class="h-6 w-6 rounded-full ring-offset-1 transition-transform hover:scale-110"
                                :class="newForm.color === c ? 'ring-2 ring-slate-800' : ''" :style="{ backgroundColor: c }"></button>
                            <input type="color" v-model="newForm.color" class="h-7 w-7 cursor-pointer rounded border-0 bg-transparent p-0" :title="$e('Свой цвет')" />
                        </div>
                    </div>
                    <div v-if="isWorkshop" class="min-w-[160px]">
                        <InputLabel :value="$e('Цех (если производство разделено на участки)')" />
                        <TextInput v-model="newForm.workshop" list="workshop-names" :placeholder="$e('Пусто = единый цех')" class="mt-1 w-full" />
                        <datalist id="workshop-names"><option v-for="w in workshopNames" :key="w" :value="w" /></datalist>
                    </div>
                    <PrimaryButton :disabled="newForm.processing || !newForm.name" @click="add">{{ $e('Добавить') }}</PrimaryButton>
                    <SecondaryButton @click="adding = false">{{ $e('Отмена') }}</SecondaryButton>
                </div>
            </div>

            <!-- Список этапов -->
            <div class="divide-y divide-slate-50">
                <div
                    v-for="(stage, idx) in orderedStages"
                    :key="stage.id"
                    class="group"
                    :class="dragOver === idx && dragFrom !== idx ? 'border-t-2 border-indigo-400' : ''"
                    @dragover.prevent="dragOver = idx"
                    @dragleave="dragOver === idx && (dragOver = null)"
                    @drop.prevent="onDrop(idx)"
                >
                    <div
                        class="flex items-center gap-3 px-5 py-3 transition-colors hover:bg-slate-50/70"
                        :class="dragFrom === idx ? 'opacity-40' : ''"
                    >
                        <!-- Порядок: тянуть за ручку или двигать стрелками -->
                        <div class="flex items-center gap-1.5">
                            <span
                                class="cursor-grab select-none text-slate-300 transition-colors hover:text-indigo-500 active:cursor-grabbing"
                                draggable="true"
                                :title="$e('Перетащите, чтобы изменить порядок')"
                                @dragstart="onDragStart(idx, $event)"
                                @dragend="dragFrom = null; dragOver = null"
                            >⠿</span>
                            <div class="flex flex-col text-[10px] leading-none text-slate-300 opacity-0 transition-opacity group-hover:opacity-100">
                                <button class="transition-colors hover:text-indigo-600 disabled:opacity-25" :disabled="idx === 0" @click="moveBy(idx, -1)" :title="$e('Выше')">▲</button>
                                <button class="transition-colors hover:text-indigo-600 disabled:opacity-25" :disabled="idx === orderedStages.length - 1" @click="moveBy(idx, 1)" :title="$e('Ниже')">▼</button>
                            </div>
                        </div>
                        <!-- Номер -->
                        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-slate-100 text-xs font-bold text-slate-500">{{ idx + 1 }}</span>
                        <!-- Цвет -->
                        <span class="h-4 w-4 shrink-0 rounded-full ring-2 ring-white shadow" :style="{ backgroundColor: stage.color || '#94a3b8' }"></span>
                        <!-- Название + бейджи -->
                        <div class="flex flex-1 flex-wrap items-center gap-1.5">
                            <span class="font-medium text-slate-800">{{ stage.name }}</span>
                            <span v-if="typeBadge(stage)" class="rounded-full bg-indigo-100 px-2 py-0.5 text-[10px] font-semibold text-indigo-700">{{ typeBadge(stage) }}</span>
                            <span v-if="stage.gate_task_title" class="rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold text-amber-700" :title="`${$e('Задача')}: ${stage.gate_task_title} · ${gateRoles[stage.gate_task_role] ?? stage.gate_task_role} · ${stage.gate_task_days} ${$e('дн.')}`">{{ $e('🔒 гейт') }}</span>
                            <span v-if="stage.is_completed" class="rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-semibold text-emerald-700" :title="$e('Заказ готов → сделка на Логистику')">{{ $e('🏁 завершающий') }}</span>
                        <span v-if="stage.requires_document" class="rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold text-amber-700" :title="$e('Без прикреплённого документа сделка дальше не идёт')">{{ $e('📎 нужен документ') }}</span>
                            <span v-if="stage.workshop" class="rounded-full bg-sky-100 px-2 py-0.5 text-[10px] font-semibold text-sky-700">{{ stage.workshop }}</span>
                            <span v-if="occupants(stage)" class="text-[11px] text-slate-400">· {{ occupants(stage) }} {{ isWorkshop ? $e('заказ.') : $e('сдел.') }}</span>
                        </div>
                        <!-- Действия -->
                        <div class="flex items-center gap-1 opacity-0 transition-opacity group-hover:opacity-100">
                            <button class="rounded-lg px-2.5 py-1 text-xs font-medium text-slate-600 hover:bg-indigo-50 hover:text-indigo-700" @click="startEdit(stage)">{{ $e('Изменить') }}</button>
                            <button class="rounded-lg px-2.5 py-1 text-xs font-medium text-slate-400 hover:bg-rose-50 hover:text-rose-600" @click="startRemove(stage)">{{ $e('Удалить') }}</button>
                        </div>
                    </div>

                    <!-- Редактор -->
                    <div v-if="editing === stage.id" class="border-l-2 border-indigo-400 bg-indigo-50/40 px-5 py-4">
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div>
                                <InputLabel :value="$e('Название')" />
                                <TextInput v-model="editForm.name" class="mt-1 w-full" />
                            </div>
                            <div>
                                <InputLabel :value="$e('Цвет')" />
                                <div class="mt-1 flex items-center gap-1.5">
                                    <button v-for="c in PALETTE" :key="c" type="button" @click="editForm.color = c"
                                        class="h-6 w-6 rounded-full ring-offset-1 transition-transform hover:scale-110"
                                        :class="editForm.color?.toUpperCase() === c ? 'ring-2 ring-slate-800' : ''" :style="{ backgroundColor: c }"></button>
                                    <input type="color" v-model="editForm.color" class="h-7 w-7 cursor-pointer rounded border-0 bg-transparent p-0" :title="$e('Свой цвет')" />
                                </div>
                            </div>
                            <div v-if="!isWorkshop">
                                <InputLabel :value="$e('Системный тип (логика этапа)')" />
                                <select v-model="editForm.stage_type" class="mt-1 w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-400 focus:ring-indigo-400">
                                    <option value="">{{ $e('— обычный этап —') }}</option>
                                    <option v-for="(label, t) in availableTypes" :key="t" :value="t">{{ label }}</option>
                                </select>
                                <div v-if="editForm.errors.stage_type" class="mt-1 text-xs text-red-600">{{ editForm.errors.stage_type }}</div>
                                <!-- Что делает выбранный тип: владелец не должен угадывать. -->
                                <p v-if="editForm.stage_type" class="mt-1 text-[11px] leading-snug text-slate-500">{{ stageTypeHints[editForm.stage_type] }}</p>
                                <p v-else class="mt-1 text-[11px] leading-snug text-slate-400">{{ $e('Обычный этап: логики не несёт — название, цвет и порядок настраиваются здесь же.') }}</p>
                                <!-- Занятые типы в списке не показываем: выбрать их всё равно
                                     нельзя (тип уникален в воронке), поэтому подписываем, где они. -->
                                <p v-if="takenTypes.length" class="mt-1 text-[11px] leading-snug text-slate-400">
                                    {{ $e('Уже заняты:') }}
                                    <span v-for="(t, i) in takenTypes" :key="t.type">{{ i ? ' · ' : '' }}{{ t.label }} — «{{ t.stage }}»</span>
                                </p>
                            </div>
                        </div>

                        <div v-if="isWorkshop" class="mt-3">
                            <InputLabel :value="$e('Цех этапа (пусто — единое производство)')" />
                            <TextInput v-model="editForm.workshop" list="workshop-names" :placeholder="$e('Пусто = единый цех компании')" class="mt-1 w-full sm:w-72" />
                        </div>

                        <!-- Цех: завершающий этап -->
                        <label v-if="isWorkshop" class="mt-3 flex items-center gap-2 rounded-lg bg-white/60 px-3 py-2 text-sm text-slate-700">
                            <input type="checkbox" v-model="editForm.is_completed" class="rounded border-slate-300 text-emerald-600 focus:ring-emerald-500" />
                            {{ $e('🏁 Завершающий этап — заказ считается готовым, сделка возвращается на «Логистику»') }}
                        </label>

                        <!-- Сделки: гейт-задача -->
                        <template v-if="!isWorkshop">
                            <div class="mt-3 text-xs font-semibold text-slate-500">{{ $e('🔒 Гейт: задача при входе на этап (пока не закрыта — сделка дальше не идёт). Пусто = без гейта.') }}</div>
                            <div class="mt-1.5 grid grid-cols-1 gap-3 sm:grid-cols-3">
                                <div>
                                    <InputLabel :value="$e('Текст задачи')" />
                                    <TextInput v-model="editForm.gate_task_title" :placeholder="$e('Выставить акт')" class="mt-1 w-full" />
                                </div>
                                <div>
                                    <InputLabel :value="$e('Кому (роль)')" />
                                    <select v-model="editForm.gate_task_role" class="mt-1 w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-400 focus:ring-indigo-400">
                                        <option v-for="(label, r) in gateRoles" :key="r" :value="r">{{ label }}</option>
                                    </select>
                                </div>
                                <div>
                                    <InputLabel :value="$e('Срок, дней')" />
                                    <TextInput v-model="editForm.gate_task_days" type="number" min="1" max="365" class="mt-1 w-full" />
                                </div>
                            </div>

                            <label class="mt-3 flex cursor-pointer items-start gap-2 text-sm text-slate-700">
                                <input type="checkbox" v-model="editForm.requires_document" class="mt-0.5 rounded border-slate-300 text-amber-600 focus:ring-amber-500" />
                                <span>
                                    {{ $e('Требуется прикреплённый документ') }}
                                    <span class="block text-xs text-slate-400">
                                        {{ $e('Пока к сделке не приложен файл, с этого этапа она дальше не перейдёт') }}
                                    </span>
                                </span>
                            </label>
                        </template>

                        <div class="mt-4 flex gap-2">
                            <PrimaryButton :disabled="editForm.processing || !editForm.name" @click="saveEdit(stage)">{{ $e('Сохранить') }}</PrimaryButton>
                            <SecondaryButton @click="editing = null">{{ $e('Отмена') }}</SecondaryButton>
                        </div>
                    </div>

                    <!-- Удаление с переносом -->
                    <div v-if="removing === stage.id" class="border-l-2 border-rose-400 bg-rose-50/50 px-5 py-4">
                        <div class="text-sm text-rose-700">{{ $e('На этапе «') }}{{ stage.name }}» — {{ occupants(stage) }} {{ isWorkshop ? $e('заказ(ов)') : $e('активных сделок') }}{{ $e('. Куда их перенести перед удалением?') }}</div>
                        <div class="mt-2 flex flex-wrap items-center gap-2">
                            <select v-model="transferTo" class="rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-400 focus:ring-indigo-400">
                                <option value="">{{ $e('— выберите этап —') }}</option>
                                <option v-for="s in stages.filter((x) => x.id !== stage.id)" :key="s.id" :value="s.id">{{ s.name }}</option>
                            </select>
                            <PrimaryButton :disabled="!transferTo" @click="confirmRemove(stage)">{{ $e('Перенести и удалить') }}</PrimaryButton>
                            <SecondaryButton @click="removing = null">{{ $e('Отмена') }}</SecondaryButton>
                        </div>
                        <div v-if="removeErr" class="mt-1 text-xs text-red-600">{{ removeErr }}</div>
                    </div>
                </div>

                <div v-if="!stages.length" class="px-5 py-12 text-center text-sm text-slate-400">
                    {{ $e('Этапов нет — нажмите «+ Добавить этап»') }}
                </div>
            </div>
        </div>
    </AppLayout>
</template>
