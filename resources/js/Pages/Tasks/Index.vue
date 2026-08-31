<script setup>
/**
 * Задачи: мои / поручил я / все. Быстрое закрытие чекбоксом, автосохранение
 * названия и срока (debounce 600 мс), обновление раз в 30 с (и по WebSocket,
 * если подключён Echo).
 */
import { ref, computed, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Pagination from '@/Components/Pagination.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import Avatar from '@/Components/Avatar.vue';
import { useE } from '@/composables/useTranslations';
import { useLive } from '@/composables/useLive';

const tr = useE();
const props = defineProps({ tasks: Object, filters: Object, counts: Object, canSeeAll: Boolean, statuses: Object, types: Object, priorities: Object, users: Array });

const view = ref(props.filters.view);
const mode = ref(props.filters.mode || 'list');
const fStatus = ref(props.filters.status);
const fType = ref(props.filters.type);
const search = ref(props.filters.search);

// Адрес — источник правды. При «назад/вперёд» и повторном входе из меню
// Inertia обновляет props, а локальные refs без синхронизации оставались
// от прошлого адреса: вкладка и режим показывали не то, что открыто.
watch(() => props.filters, (f) => {
    view.value = f.view;
    mode.value = f.mode || 'list';
    fStatus.value = f.status;
    fType.value = f.type;
    search.value = f.search;
});

/** Активен ли фильтр, из-за которого список мог опустеть. */
const filtered = computed(() => view.value !== 'mine' || fStatus.value !== 'open' || !!fType.value || !!search.value);
const resetFilters = () => { view.value = 'mine'; fStatus.value = 'open'; fType.value = ''; search.value = ''; apply(); };

let searchTimer = null;
const apply = () => router.get(route('tasks.index'), {
    view: view.value, mode: mode.value !== 'list' ? mode.value : undefined, status: fStatus.value !== 'open' ? fStatus.value : undefined, type: fType.value || undefined, search: search.value || undefined,
}, { preserveState: true, preserveScroll: true, replace: true });
const onSearch = () => { clearTimeout(searchTimer); searchTimer = setTimeout(apply, 350); };
const setView = (v) => { view.value = v; apply(); };
const setMode = (m) => { mode.value = m; if (m === 'board') fStatus.value = 'all'; apply(); };

// ---- канбан: колонки по статусам, перенос drag-and-drop ----
const COLS = [['new', '🆕', 'from-sky-50'], ['in_progress', '⚡', 'from-amber-50'], ['review', '👀', 'from-violet-50'], ['done', '✅', 'from-emerald-50'], ['canceled', '🚫', 'from-slate-100']];
const columns = computed(() => COLS.map(([k, icon, bg]) => ({ key: k, icon, bg, label: props.statuses[k], items: props.tasks.data.filter((t) => t.status === k).sort((a, b) => a.position - b.position) })));
const drag = ref(null); // { id, from }
const overCol = ref(null);
const onDragStart = (t) => { drag.value = { id: t.id, from: t.status }; };
const onDropCol = (col, beforeId = null) => {
    if (!drag.value) return;
    const ids = col.items.map((t) => t.id).filter((id) => id !== drag.value.id);
    const at = beforeId ? ids.indexOf(beforeId) : ids.length;
    ids.splice(at < 0 ? ids.length : at, 0, drag.value.id);
    router.patch(route('tasks.move', drag.value.id), { status: col.key, order: ids }, { preserveScroll: true, only: ['tasks', 'counts'] });
    drag.value = null; overCol.value = null;
};

// ---- группы: просрочено / сегодня / позже / без срока / закрыто ----
const today = new Date(new Date().toDateString());
const groupOf = (t) => {
    if (['done', 'canceled'].includes(t.status)) return 'closed';
    if (!t.due_date) return 'nodate';
    const d = new Date(t.due_date);
    if (d < today) return 'overdue';
    if (d.getTime() === today.getTime()) return 'today';
    return 'later';
};
const GROUPS = [['overdue', tr('Просрочено'), 'text-rose-600'], ['today', tr('Сегодня'), 'text-amber-600'], ['later', tr('Позже'), 'text-slate-600'], ['nodate', tr('Без срока'), 'text-slate-400'], ['closed', tr('Закрыто'), 'text-emerald-600']];
const groups = computed(() => GROUPS.map(([k, label, cls]) => ({ key: k, label, cls, items: props.tasks.data.filter((t) => groupOf(t) === k) })).filter((g) => g.items.length));

// ---- быстрые действия ----
const toggle = (t) => router.patch(route('tasks.toggle', t.id), {}, { preserveScroll: true, only: ['tasks', 'counts'] });
const setStatus = (t, status) => router.patch(route('tasks.status', t.id), { status }, { preserveScroll: true, only: ['tasks', 'counts'] });
const timers = {};
const autosave = (t, field, value) => {
    clearTimeout(timers[t.id + field]);
    timers[t.id + field] = setTimeout(() => router.patch(route('tasks.autosave', t.id), { [field]: value }, { preserveScroll: true, preserveState: true, only: ['tasks'] }), 600);
};

// ---- новая задача ----
const adding = ref(false);
const form = useForm({ title: '', assignee_id: '', due_date: '', priority: 'medium', description: '' });
const create = () => form.post(route('tasks.store'), { preserveScroll: true, onSuccess: () => { form.reset(); adding.value = false; } });

// Живые обновления: штамп раз в 10 с, данные — только когда что-то изменилось.
useLive({ tasks: ['tasks', 'counts'] });

const prioCls = { high: 'bg-rose-100 text-rose-700', medium: 'bg-slate-100 text-slate-600', low: 'bg-sky-100 text-sky-700' };
const typeCls = { crm_deal: 'bg-indigo-100 text-indigo-700', erp_process: 'bg-amber-100 text-amber-700', corporate: 'bg-emerald-100 text-emerald-700' };
const fmt = (d) => d ? new Date(d).toLocaleDateString('ru-RU', { day: '2-digit', month: 'short' }) : '';
const field = 'rounded-xl border-white/60 bg-white/70 text-sm shadow-soft backdrop-blur focus:border-indigo-400 focus:ring-indigo-400';
</script>

<template>
    <Head :title="$e('Задачи')" />
    <AppLayout>
        <template #header>{{ $e('Задачи') }}</template>

        <!-- Сводка -->
        <div class="mb-4 grid grid-cols-3 gap-3">
            <div v-for="c in [['mine', $e('Открытых у меня'), 'text-slate-900'], ['today', $e('На сегодня'), 'text-amber-600'], ['overdue', $e('Просрочено'), 'text-rose-600']]" :key="c[0]"
                class="rounded-2xl border border-white/60 bg-white/70 px-4 py-3 shadow-soft backdrop-blur-md">
                <div class="text-xs uppercase tracking-wide text-slate-400">{{ c[1] }}</div>
                <div class="mt-1 text-2xl font-bold tabular-nums" :class="c[2]">{{ counts[c[0]] }}</div>
            </div>
        </div>

        <!-- Панель: вкладки + фильтры -->
        <div class="mb-4 rounded-3xl border border-white/60 bg-gradient-to-br from-white/85 via-indigo-50/60 to-violet-50/50 p-3 shadow-soft-lg backdrop-blur-xl">
            <div class="flex flex-wrap items-center gap-2">
                <div class="flex rounded-2xl bg-white/60 p-1 text-sm shadow-soft">
                    <button v-for="v in [['mine', $e('Мои')], ['created', $e('Поручил я')], ...(canSeeAll ? [['all', $e('Все')]] : [])]" :key="v[0]" type="button" @click="setView(v[0])"
                        class="rounded-xl px-4 py-1.5 font-medium transition" :class="view === v[0] ? 'bg-slate-900 text-white shadow-soft-md' : 'text-slate-600 hover:bg-white'">{{ v[1] }}</button>
                </div>
                <div class="flex rounded-2xl bg-white/60 p-1 text-sm shadow-soft">
                    <button type="button" @click="setMode('list')" class="rounded-xl px-3 py-1.5 font-medium transition" :class="mode === 'list' ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-white'" :title="$e('Список')">☰</button>
                    <button type="button" @click="setMode('board')" class="rounded-xl px-3 py-1.5 font-medium transition" :class="mode === 'board' ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-white'" :title="$e('Канбан')">▦</button>
                </div>
                <select v-if="mode === 'list'" v-model="fStatus" @change="apply" :class="field">
                    <option value="open">{{ $e('Открытые') }}</option>
                    <option v-for="(l, k) in statuses" :key="k" :value="k">{{ l }}</option>
                    <option value="all">{{ $e('Все статусы') }}</option>
                </select>
                <select v-model="fType" @change="apply" :class="field">
                    <option value="">{{ $e('Все типы') }}</option>
                    <option v-for="(l, k) in types" :key="k" :value="k">{{ l }}</option>
                </select>
                <input v-model="search" @input="onSearch" type="search" :placeholder="$e('Поиск по названию')" :class="field + ' w-56'" />
                <PrimaryButton class="ml-auto" @click="adding = !adding">+ {{ $e('Задача') }}</PrimaryButton>
            </div>

            <!-- Новая задача -->
            <div v-if="adding" class="mt-3 grid grid-cols-1 gap-2 rounded-2xl bg-white/60 p-3 sm:grid-cols-[1fr_12rem_9rem_8rem_auto]">
                <input v-model="form.title" type="text" :placeholder="$e('Что нужно сделать')" :class="field + ' w-full'" @keydown.enter="create" />
                <select v-model="form.assignee_id" :class="field + ' w-full'"><option value="">{{ $e('Себе') }}</option><option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option></select>
                <input v-model="form.due_date" type="date" :class="field + ' w-full'" />
                <select v-model="form.priority" :class="field + ' w-full'"><option v-for="(l, k) in priorities" :key="k" :value="k">{{ l }}</option></select>
                <PrimaryButton :disabled="form.processing || !form.title" @click="create">{{ $e('Создать') }}</PrimaryButton>
                <div v-if="form.errors.title" class="text-xs text-rose-600 sm:col-span-5">{{ form.errors.title }}</div>
            </div>
        </div>

        <!-- Канбан -->
        <div v-if="mode === 'board'" class="-mx-1 flex gap-3 overflow-x-auto pb-3">
            <div v-for="col in columns" :key="col.key"
                class="flex w-72 shrink-0 flex-col rounded-3xl border border-white/60 bg-gradient-to-b to-white/50 p-2 shadow-soft backdrop-blur-md transition"
                :class="[col.bg, overCol === col.key ? 'ring-2 ring-indigo-400' : '']"
                @dragover.prevent="overCol = col.key" @dragleave="overCol === col.key && (overCol = null)" @drop.prevent="onDropCol(col)">
                <div class="flex items-center justify-between px-2 py-1.5">
                    <div class="flex items-center gap-1.5 text-sm font-semibold text-slate-800"><span>{{ col.icon }}</span>{{ col.label }}</div>
                    <span class="rounded-full bg-white/80 px-2 py-0.5 text-xs font-semibold text-slate-500">{{ col.items.length }}</span>
                </div>
                <div class="flex min-h-[8rem] flex-col gap-2">
                    <a v-for="t in col.items" :key="t.id" :href="route('tasks.show', t.id)"
                        :draggable="t.can_edit" @dragstart="onDragStart(t)" @dragover.prevent.stop="overCol = col.key" @drop.prevent.stop="onDropCol(col, t.id)"
                        class="group block cursor-grab rounded-2xl border border-white/70 bg-white/85 p-3 shadow-soft transition hover:shadow-soft-md active:cursor-grabbing"
                        :class="drag?.id === t.id ? 'opacity-40' : ''">
                        <div class="flex items-start gap-2">
                            <span class="mt-0.5 h-2 w-2 shrink-0 rounded-full" :class="t.priority === 'high' ? 'bg-rose-500' : t.priority === 'low' ? 'bg-sky-400' : 'bg-slate-300'"></span>
                            <div class="min-w-0 flex-1 text-sm font-medium leading-snug text-slate-900" :class="t.status === 'done' ? 'line-through opacity-60' : ''">{{ t.title }}</div>
                        </div>
                        <div class="mt-2 flex flex-wrap items-center gap-1.5 text-xs">
                            <span v-if="t.link" class="rounded-md bg-indigo-50 px-1.5 py-0.5 font-semibold text-indigo-700">{{ t.link.label }}</span>
                            <span class="rounded-md px-1.5 py-0.5" :class="typeCls[t.type]">{{ types[t.type] }}</span>
                            <span v-if="t.checklist" class="rounded-md bg-slate-100 px-1.5 py-0.5 text-slate-600">☑ {{ t.checklist.done }}/{{ t.checklist.total }}</span>
                            <span v-if="t.due_date" class="ml-auto rounded-md px-1.5 py-0.5" :class="groupOf(t) === 'overdue' ? 'bg-rose-100 font-semibold text-rose-700' : 'bg-slate-100 text-slate-600'">📅 {{ fmt(t.due_date) }}</span>
                        </div>
                        <div v-if="t.assignee" class="mt-2 flex items-center gap-1.5 text-xs text-slate-500">
                            <Avatar :name="t.assignee.name" :src="t.assignee.avatar" :size="20" /><span class="truncate">{{ t.assignee.name }}</span>
                        </div>
                    </a>
                    <div v-if="!col.items.length" class="flex flex-1 items-center justify-center rounded-2xl border border-dashed border-slate-200/80 text-xs text-slate-300">{{ $e('Перетащите сюда') }}</div>
                </div>
            </div>
        </div>

        <!-- Список -->
        <div v-else class="space-y-5">
            <section v-for="g in groups" :key="g.key">
                <div class="mb-2 flex items-center gap-3 text-xs font-semibold uppercase tracking-wide" :class="g.cls"><span>{{ g.label }} · {{ g.items.length }}</span><span class="h-px flex-1 bg-slate-200/70"></span></div>
                <div class="grid gap-2">
                    <div v-for="t in g.items" :key="t.id"
                        class="group flex items-center gap-3 rounded-2xl border border-white/60 bg-white/70 px-4 py-2.5 shadow-soft backdrop-blur-md transition hover:shadow-soft-md"
                        :class="['done', 'canceled'].includes(t.status) ? 'opacity-60' : ''">
                        <button type="button" :disabled="!t.can_edit" @click="toggle(t)"
                            class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full border-2 transition"
                            :class="t.status === 'done' ? 'border-emerald-500 bg-emerald-500 text-white' : 'border-slate-300 hover:border-emerald-500'">
                            <svg v-if="t.status === 'done'" class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                        </button>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <input :value="t.title" :readonly="!t.can_edit" @input="autosave(t, 'title', $event.target.value)"
                                    class="w-full border-0 bg-transparent p-0 text-sm font-medium text-slate-900 focus:ring-0" :class="t.status === 'done' ? 'line-through' : ''" />
                                <a :href="route('tasks.show', t.id)" class="shrink-0 rounded-md px-1.5 py-0.5 text-xs font-medium text-indigo-600 opacity-0 transition hover:bg-indigo-50 group-hover:opacity-100">{{ $e('Открыть') }} →</a>
                            </div>
                            <div class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-slate-500">
                                <a v-if="t.link" :href="t.link.url" class="font-semibold text-indigo-600 hover:underline">{{ t.link.label }}</a>
                                <span class="rounded-full px-1.5 py-0.5" :class="typeCls[t.type]">{{ types[t.type] }}</span>
                                <span v-if="t.priority !== 'medium'" class="rounded-full px-1.5 py-0.5" :class="prioCls[t.priority]">{{ priorities[t.priority] }}</span>
                                <span v-if="t.creator && view !== 'created'">{{ $e('от') }} {{ t.creator }}</span>
                            </div>
                        </div>
                        <select :value="t.status" :disabled="!t.can_edit" @change="setStatus(t, $event.target.value)" class="rounded-lg border-0 bg-slate-100/70 py-1 pl-2 pr-7 text-xs text-slate-600 focus:ring-indigo-400">
                            <option v-for="(l, k) in statuses" :key="k" :value="k">{{ l }}</option>
                        </select>
                        <input type="date" :value="t.due_date ?? ''" :disabled="!t.can_edit" @change="autosave(t, 'due_date', $event.target.value || null)"
                            class="w-32 rounded-lg border-0 bg-slate-100/70 py-1 text-xs focus:ring-indigo-400" :class="g.key === 'overdue' ? 'text-rose-600 font-semibold' : 'text-slate-600'" />
                        <Avatar v-if="t.assignee" :name="t.assignee.name" :src="t.assignee.avatar" :size="28" :title="t.assignee.name" />
                    </div>
                </div>
            </section>
            <div v-if="!tasks.data.length" class="rounded-3xl border border-dashed border-slate-200 bg-white/60 p-12 text-center text-sm text-slate-400 backdrop-blur">
                <p>✅ {{ $e('Задач нет') }}</p>
                <template v-if="filtered">
                    <p class="mt-1 text-xs">{{ $e('По выбранным фильтрам ничего не нашлось') }}</p>
                    <button type="button" class="mt-3 rounded-xl bg-slate-900 px-4 py-1.5 text-xs font-medium text-white transition hover:bg-slate-700" @click="resetFilters">{{ $e('Сбросить фильтры') }}</button>
                </template>
            </div>
            <Pagination :links="tasks.links" />
        </div>
    </AppLayout>
</template>
