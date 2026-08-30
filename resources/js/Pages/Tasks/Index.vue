<script setup>
/**
 * Задачи: мои / поручил я / все. Быстрое закрытие чекбоксом, автосохранение
 * названия и срока (debounce 600 мс), обновление раз в 30 с (и по WebSocket,
 * если подключён Echo).
 */
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Pagination from '@/Components/Pagination.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import Avatar from '@/Components/Avatar.vue';
import { useE } from '@/composables/useTranslations';

const tr = useE();
const props = defineProps({ tasks: Object, filters: Object, counts: Object, canSeeAll: Boolean, statuses: Object, types: Object, priorities: Object, users: Array });

const view = ref(props.filters.view);
const fStatus = ref(props.filters.status);
const fType = ref(props.filters.type);
const search = ref(props.filters.search);
let searchTimer = null;
const apply = () => router.get(route('tasks.index'), {
    view: view.value, status: fStatus.value !== 'open' ? fStatus.value : undefined, type: fType.value || undefined, search: search.value || undefined,
}, { preserveState: true, preserveScroll: true, replace: true });
const onSearch = () => { clearTimeout(searchTimer); searchTimer = setTimeout(apply, 350); };
const setView = (v) => { view.value = v; apply(); };

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

// ---- обновление: WebSocket, если есть, иначе опрос ----
let poll = null;
onMounted(() => {
    poll = setInterval(() => router.reload({ only: ['tasks', 'counts'] }), 30000);
    const uid = usePageUser();
    if (window.Echo && uid) window.Echo.private(`user.${uid}`).listen('.task.status', () => router.reload({ only: ['tasks', 'counts'] }));
});
onUnmounted(() => clearInterval(poll));
import { usePage } from '@inertiajs/vue3';
const usePageUser = () => usePage().props.auth.user?.id;

const prioCls = { high: 'bg-rose-100 text-rose-700', medium: 'bg-slate-100 text-slate-600', low: 'bg-sky-100 text-sky-700' };
const typeCls = { crm_deal: 'bg-indigo-100 text-indigo-700', erp_process: 'bg-amber-100 text-amber-700', corporate: 'bg-emerald-100 text-emerald-700' };
const fmt = (d) => d ? new Date(d).toLocaleDateString('ru-RU', { day: '2-digit', month: 'short' }) : '';
const field = 'w-full rounded-xl border-white/60 bg-white/70 text-sm shadow-soft backdrop-blur focus:border-indigo-400 focus:ring-indigo-400';
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
                <select v-model="fStatus" @change="apply" :class="field + ' w-auto'">
                    <option value="open">{{ $e('Открытые') }}</option>
                    <option v-for="(l, k) in statuses" :key="k" :value="k">{{ l }}</option>
                    <option value="all">{{ $e('Все статусы') }}</option>
                </select>
                <select v-model="fType" @change="apply" :class="field + ' w-auto'">
                    <option value="">{{ $e('Все типы') }}</option>
                    <option v-for="(l, k) in types" :key="k" :value="k">{{ l }}</option>
                </select>
                <input v-model="search" @input="onSearch" type="search" :placeholder="$e('Поиск по названию')" :class="field + ' w-56'" />
                <PrimaryButton class="ml-auto" @click="adding = !adding">+ {{ $e('Задача') }}</PrimaryButton>
            </div>

            <!-- Новая задача -->
            <div v-if="adding" class="mt-3 grid grid-cols-1 gap-2 rounded-2xl bg-white/60 p-3 sm:grid-cols-[1fr_12rem_9rem_8rem_auto]">
                <input v-model="form.title" type="text" :placeholder="$e('Что нужно сделать')" :class="field" @keydown.enter="create" />
                <select v-model="form.assignee_id" :class="field"><option value="">{{ $e('Себе') }}</option><option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option></select>
                <input v-model="form.due_date" type="date" :class="field" />
                <select v-model="form.priority" :class="field"><option v-for="(l, k) in priorities" :key="k" :value="k">{{ l }}</option></select>
                <PrimaryButton :disabled="form.processing || !form.title" @click="create">{{ $e('Создать') }}</PrimaryButton>
                <div v-if="form.errors.title" class="text-xs text-rose-600 sm:col-span-5">{{ form.errors.title }}</div>
            </div>
        </div>

        <!-- Список -->
        <div class="space-y-5">
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
                            <input :value="t.title" :readonly="!t.can_edit" @input="autosave(t, 'title', $event.target.value)"
                                class="w-full border-0 bg-transparent p-0 text-sm font-medium text-slate-900 focus:ring-0" :class="t.status === 'done' ? 'line-through' : ''" />
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
            <div v-if="!tasks.data.length" class="rounded-3xl border border-dashed border-slate-200 bg-white/60 p-12 text-center text-sm text-slate-400 backdrop-blur">✅ {{ $e('Задач нет') }}</div>
            <Pagination :links="tasks.links" />
        </div>
    </AppLayout>
</template>
