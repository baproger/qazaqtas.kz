<script setup>
/**
 * Страница задачи: шапка со статусом, заголовок и описание с автосохранением
 * (debounce 500 мс), чек-лист, справа — контекст (сделка / заказ), люди, срок,
 * приоритет; внизу — комментарии, файлы и история изменений.
 */
import { ref, computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Avatar from '@/Components/Avatar.vue';
import CommentPanel from '@/Components/CommentPanel.vue';
import DocumentPanel from '@/Components/DocumentPanel.vue';
import { useE } from '@/composables/useTranslations';
import { useLive } from '@/composables/useLive';

const tr = useE();
const props = defineProps({ task: Object, context: Object, comments: Array, documents: Array, history: Array, canEdit: Boolean, statuses: Object, types: Object, priorities: Object, users: Array });

const title = ref(props.task.title);
const description = ref(props.task.description ?? '');
const checklist = ref(JSON.parse(JSON.stringify(props.task.checklist ?? [])));
const saving = ref(false);
const savedAt = ref(null);
const timers = {};
const save = (field, value, delay = 500) => {
    if (!props.canEdit) return;
    clearTimeout(timers[field]);
    timers[field] = setTimeout(() => {
        saving.value = true;
        router.patch(route('tasks.autosave', props.task.id), { [field]: value }, { preserveScroll: true, preserveState: true, only: ['task', 'history'],
            onFinish: () => { saving.value = false; savedAt.value = new Date(); } });
    }, delay);
};
const setStatus = (status) => router.patch(route('tasks.status', props.task.id), { status }, { preserveScroll: true });
const toggle = () => router.patch(route('tasks.toggle', props.task.id), {}, { preserveScroll: true });
const newItem = ref('');
const addItem = () => { if (!newItem.value.trim()) return; checklist.value.push({ text: newItem.value.trim(), done: false }); newItem.value = ''; save('checklist', checklist.value, 0); };
const toggleItem = (i) => { checklist.value[i].done = !checklist.value[i].done; save('checklist', checklist.value, 0); };
const removeItem = (i) => { checklist.value.splice(i, 1); save('checklist', checklist.value, 0); };
const checkDone = computed(() => checklist.value.filter((c) => c.done).length);

const isOverdue = computed(() => props.task.due_date && !['done', 'canceled'].includes(props.task.status) && new Date(props.task.due_date) < new Date(new Date().toDateString()));
const fmt = (t) => t ? new Date(t).toLocaleString('ru-RU', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : '—';
const statusCls = { new: 'bg-sky-100 text-sky-700', in_progress: 'bg-amber-100 text-amber-700', review: 'bg-violet-100 text-violet-700', done: 'bg-emerald-100 text-emerald-700', canceled: 'bg-slate-100 text-slate-500' };
const prioCls = { high: 'text-rose-600', medium: 'text-slate-600', low: 'text-sky-600' };
const field = 'w-full rounded-xl border-white/60 bg-white/70 text-sm shadow-soft backdrop-blur focus:border-indigo-400 focus:ring-indigo-400';

useLive({ tasks: ['task', 'comments', 'history'] });
</script>

<template>
    <Head :title="task.title" />
    <AppLayout>
        <template #header>
            <span class="flex items-center gap-2"><Link :href="route('tasks.index')" class="text-slate-400 hover:text-slate-700">{{ $e('Задачи') }}</Link><span class="text-slate-300">›</span><span class="truncate font-medium text-slate-500">#{{ task.id }}</span></span>
        </template>

        <div class="grid grid-cols-1 gap-5 lg:grid-cols-[minmax(0,1fr)_20rem]">
            <!-- ===== Основная область ===== -->
            <div class="space-y-4">
                <div class="rounded-3xl border border-white/60 bg-white/75 p-5 shadow-soft-lg backdrop-blur-xl sm:p-6">
                    <!-- Шапка: статус, галочка, сохранение -->
                    <div class="flex flex-wrap items-center gap-2">
                        <button type="button" :disabled="!canEdit" @click="toggle"
                            class="flex h-8 w-8 items-center justify-center rounded-full border-2 transition"
                            :class="task.status === 'done' ? 'border-emerald-500 bg-emerald-500 text-white' : 'border-slate-300 hover:border-emerald-500'">
                            <svg v-if="task.status === 'done'" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                        </button>
                        <select :value="task.status" :disabled="!canEdit" @change="setStatus($event.target.value)" class="rounded-full border-0 py-1 pl-3 pr-8 text-xs font-semibold focus:ring-indigo-400" :class="statusCls[task.status]">
                            <option v-for="(l, k) in statuses" :key="k" :value="k">{{ l }}</option>
                        </select>
                        <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs text-slate-600">{{ types[task.type] }}</span>
                        <a v-if="context" :href="context.url" class="rounded-full bg-indigo-50 px-2.5 py-1 text-xs font-semibold text-indigo-700 hover:bg-indigo-100">🔗 {{ context.number }}</a>
                        <span class="ml-auto text-xs text-slate-400">
                            <template v-if="saving">{{ $e('Сохраняю…') }}</template>
                            <template v-else-if="savedAt">✓ {{ $e('сохранено') }} {{ savedAt.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' }) }}</template>
                            <template v-else-if="canEdit">{{ $e('изменения сохраняются сами') }}</template>
                        </span>
                    </div>

                    <!-- Заголовок -->
                    <input v-model="title" :readonly="!canEdit" @input="save('title', title)"
                        class="mt-4 w-full border-0 bg-transparent p-0 text-2xl font-bold tracking-tight text-slate-900 focus:ring-0" :class="task.status === 'done' ? 'line-through opacity-60' : ''" :placeholder="$e('Название задачи')" />

                    <!-- Описание -->
                    <textarea v-model="description" :readonly="!canEdit" @input="save('description', description)" rows="6"
                        class="mt-3 w-full resize-y rounded-2xl border border-slate-100 bg-slate-50/60 p-3 text-sm leading-relaxed text-slate-700 focus:border-indigo-300 focus:ring-indigo-300"
                        :placeholder="$e('Описание: что именно сделать, детали, ссылки…')"></textarea>

                    <!-- Чек-лист -->
                    <div class="mt-4">
                        <div class="flex items-center justify-between text-xs font-semibold uppercase tracking-wide text-slate-400">
                            <span>☑ {{ $e('Чек-лист') }}<span v-if="checklist.length"> · {{ checkDone }}/{{ checklist.length }}</span></span>
                        </div>
                        <div v-if="checklist.length" class="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-emerald-500 transition-all" :style="{ width: (checkDone / checklist.length * 100) + '%' }"></div></div>
                        <div class="mt-2 space-y-1">
                            <div v-for="(c, i) in checklist" :key="i" class="group flex items-center gap-2 rounded-lg px-1 py-1 hover:bg-slate-50">
                                <button type="button" :disabled="!canEdit" @click="toggleItem(i)" class="flex h-4 w-4 items-center justify-center rounded border" :class="c.done ? 'border-emerald-500 bg-emerald-500 text-white' : 'border-slate-300'">
                                    <svg v-if="c.done" class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                                </button>
                                <span class="flex-1 text-sm" :class="c.done ? 'text-slate-400 line-through' : 'text-slate-700'">{{ c.text }}</span>
                                <button v-if="canEdit" type="button" @click="removeItem(i)" class="text-slate-300 opacity-0 hover:text-rose-500 group-hover:opacity-100">×</button>
                            </div>
                        </div>
                        <input v-if="canEdit" v-model="newItem" @keydown.enter.prevent="addItem" type="text" :placeholder="$e('+ пункт чек-листа, Enter')" class="mt-1 w-full border-0 bg-transparent p-1 text-sm text-slate-600 placeholder-slate-300 focus:ring-0" />
                    </div>
                </div>

                <!-- Комментарии и файлы -->
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div class="rounded-3xl border border-white/60 bg-white/75 p-4 shadow-soft backdrop-blur-xl"><CommentPanel :comments="comments" entity-type="task" :entity-id="task.id" /></div>
                    <div class="rounded-3xl border border-white/60 bg-white/75 p-4 shadow-soft backdrop-blur-xl"><DocumentPanel :documents="documents" entity-type="task" :entity-id="task.id" /></div>
                </div>

                <!-- История -->
                <div class="rounded-3xl border border-white/60 bg-white/75 p-4 shadow-soft backdrop-blur-xl">
                    <div class="text-xs font-semibold uppercase tracking-wide text-slate-400">🕘 {{ $e('История изменений') }}</div>
                    <div class="mt-2 divide-y divide-slate-100">
                        <div v-for="h in history" :key="h.id" class="flex flex-wrap items-baseline gap-x-2 py-1.5 text-sm">
                            <span class="text-xs text-slate-400">{{ fmt(h.at) }}</span>
                            <span class="font-medium text-slate-700">{{ h.user ?? $e('Система') }}</span>
                            <span class="text-slate-500">{{ h.action === 'created' ? $e('создал(а) задачу') : h.action === 'deleted' ? $e('удалил(а)') : (h.field ?? $e('изменил(а)')) }}</span>
                            <span v-if="h.old || h.new" class="text-slate-500"><span class="text-slate-400 line-through">{{ h.old ?? '—' }}</span> → <span class="font-medium text-slate-800">{{ h.new ?? '—' }}</span></span>
                        </div>
                        <div v-if="!history.length" class="py-3 text-xs text-slate-300">{{ $e('Изменений пока нет') }}</div>
                    </div>
                </div>
            </div>

            <!-- ===== Боковая панель ===== -->
            <aside class="space-y-4">
                <div v-if="context" class="rounded-3xl border border-indigo-100 bg-gradient-to-br from-indigo-50/80 to-white/70 p-4 shadow-soft backdrop-blur-xl">
                    <div class="text-xs font-semibold uppercase tracking-wide text-indigo-500"><template v-if="context.kind === 'deal'">📊 {{ $e('Сделка') }}</template><template v-else>🏭 {{ $e('Заказ цеха') }}</template></div>
                    <a :href="context.url" class="mt-1 block text-lg font-bold text-slate-900 hover:text-indigo-700">{{ context.number }}</a>
                    <div v-if="context.title" class="text-sm text-slate-600">{{ context.title }}</div>
                    <div v-if="context.sub" class="mt-1 inline-block rounded-full bg-white px-2 py-0.5 text-xs text-slate-500 ring-1 ring-slate-200">{{ context.sub }}</div>
                    <a :href="context.url" class="mt-3 block rounded-xl border border-indigo-300/60 bg-indigo-500/10 px-3 py-1.5 text-center text-sm font-medium text-indigo-700 transition hover:bg-indigo-500/20">{{ $e('Открыть') }} →</a>
                </div>

                <div class="space-y-3 rounded-3xl border border-white/60 bg-white/75 p-4 shadow-soft backdrop-blur-xl">
                    <div>
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-400">👤 {{ $e('Исполнитель') }}</div>
                        <select :value="task.assignee?.id ?? ''" :disabled="!canEdit" @change="save('assignee_id', $event.target.value || null, 0)" :class="field + ' mt-1'">
                            <option value="">—</option><option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option>
                        </select>
                    </div>
                    <div>
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-400">✍️ {{ $e('Постановщик') }}</div>
                        <div class="mt-1 flex items-center gap-2 text-sm text-slate-700"><Avatar v-if="task.creator" :name="task.creator.name" :src="task.creator.avatar" :size="24" />{{ task.creator?.name ?? $e('Система') }}</div>
                    </div>
                    <div>
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-400">📅 {{ $e('Срок') }}</div>
                        <input type="date" :value="task.due_date ?? ''" :disabled="!canEdit" @change="save('due_date', $event.target.value || null, 0)" :class="field + ' mt-1 ' + (isOverdue ? 'text-rose-600 font-semibold' : '')" />
                        <div v-if="isOverdue" class="mt-1 text-xs font-medium text-rose-600">⏰ {{ $e('Просрочено') }}</div>
                    </div>
                    <div>
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-400">🚩 {{ $e('Приоритет') }}</div>
                        <select :value="task.priority" :disabled="!canEdit" @change="save('priority', $event.target.value, 0)" :class="field + ' mt-1 font-medium ' + prioCls[task.priority]">
                            <option v-for="(l, k) in priorities" :key="k" :value="k">{{ l }}</option>
                        </select>
                    </div>
                    <div class="border-t border-slate-100 pt-3 text-xs text-slate-400">
                        {{ $e('Создана') }} {{ fmt(task.created_at) }}<template v-if="task.completed_at"> · {{ $e('закрыта') }} {{ fmt(task.completed_at) }}</template>
                    </div>
                </div>
            </aside>
        </div>
    </AppLayout>
</template>
