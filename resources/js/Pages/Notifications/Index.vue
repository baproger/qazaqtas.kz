<script setup>
/** Уведомления целиком + лента событий по сделкам, которые человек видит. */
import { ref, computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Pagination from '@/Components/Pagination.vue';
import { useE } from '@/composables/useTranslations';

const tr = useE();
const props = defineProps({ notifications: Object, types: Array, filters: Object, unread: Number, events: Array });

const TASK_TYPES = ['task_assigned', 'task_overdue', 'department_task_overdue'];
const tab = ref('tasks');
const isTask = (n) => TASK_TYPES.includes(n.type);
const listFor = (which) => props.notifications.data.filter((n) => (which === 'tasks') === isTask(n));
const unreadIn = (which) => listFor(which).filter((n) => !n.read_at).length;
const fType = ref(props.filters?.type ?? 'all');
const fOnly = ref(props.filters?.only ?? '');
const apply = () => router.get(route('notifications.index'), { type: fType.value !== 'all' ? fType.value : undefined, only: fOnly.value || undefined }, { preserveState: true, preserveScroll: true, replace: true });

const fmt = (t) => t ? new Date(t).toLocaleString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—';
const dayKey = (t) => new Date(t).toLocaleDateString('ru-RU', { weekday: 'long', day: '2-digit', month: 'long' });
const groupByDay = (list, key) => {
    const out = [];
    for (const item of list) {
        const d = dayKey(item[key]);
        const g = out.find((x) => x.day === d) ?? (out.push({ day: d, items: [] }), out[out.length - 1]);
        g.items.push(item);
    }
    return out;
};
const notifDays = computed(() => groupByDay(listFor(tab.value), 'created_at'));
const eventDays = computed(() => groupByDay(props.events.filter((e) => !eventKind.value || e.kind === eventKind.value), 'at'));
const eventKind = ref('');

const iconOf = (type) => ({ deal_stage_changed: '📊', task_assigned: '✅', task_overdue: '⏰', department_task_overdue: '⏰', expense_pending: '🧾', expense_confirmed: '✅', expense_handled: '🧾', expense_threshold: '⚠️', company_expense_submitted: '🧾', company_expense_paid: '💸', company_expense_stale: '⏳', finance_deleted: '🗑️', product_shortage: '📦', production_plan_queued: '🏭', site_order: '🛒', chat_mention: '💬', birthday: '🎂', robot: '🤖' })[type] ?? '🔔';
const kindMeta = { stage: { icon: '📊', label: tr('Этапы'), cls: 'bg-indigo-100 text-indigo-700' }, task: { icon: '✅', label: tr('Задачи'), cls: 'bg-emerald-100 text-emerald-700' }, robot: { icon: '🤖', label: tr('Роботы'), cls: 'bg-violet-100 text-violet-700' } };
const openN = (n) => { if (!n.read_at) router.patch(route('notifications.read', n.id), {}, { preserveScroll: true }); if (n.url) router.get(n.url); };
const markAll = () => router.patch(route('notifications.readAll'), {}, { preserveScroll: true });
const extras = (n) => Object.entries(n.data ?? {}).filter(([k, v]) => !['type', 'title', 'message', 'url', 'deal_number', 'from'].includes(k) && !k.endsWith('_id') && typeof v !== 'object');
</script>

<template>
    <Head :title="$e('Уведомления')" />
    <AppLayout>
        <template #header>{{ $e('Уведомления и события') }}</template>

        <!-- Шапка: стеклянная панель с вкладками и фильтрами -->
        <div class="mb-5 rounded-3xl border border-white/60 bg-gradient-to-br from-white/85 via-indigo-50/60 to-violet-50/50 p-4 shadow-soft-lg backdrop-blur-xl">
            <div class="flex flex-wrap items-center gap-3">
                <div class="flex rounded-2xl bg-white/60 p-1 text-sm shadow-soft backdrop-blur">
                    <button v-for="tb in [['tasks', '✅', $e('Задачи'), unreadIn('tasks')], ['other', '🔔', $e('Остальные'), unreadIn('other')], ['events', '🕘', $e('События'), 0]]" :key="tb[0]" type="button" @click="tab = tb[0]"
                        class="flex items-center gap-2 rounded-xl px-4 py-2 font-medium transition"
                        :class="tab === tb[0] ? 'bg-slate-900 text-white shadow-soft-md' : 'text-slate-600 hover:bg-white'">
                        <span>{{ tb[1] }}</span>{{ tb[2] }}
                        <span v-if="tb[3]" class="rounded-full bg-rose-500 px-1.5 text-xs font-bold text-white">{{ tb[3] }}</span>
                    </button>
                </div>
                <template v-if="tab !== 'events'">
                    <select v-model="fType" @change="apply" class="rounded-xl border-white/60 bg-white/70 text-sm shadow-soft backdrop-blur">
                        <option value="all">{{ $e('Все типы') }}</option>
                        <option v-for="t in types" :key="t.value" :value="t.value">{{ t.label }} ({{ t.count }})</option>
                    </select>
                    <button type="button" @click="fOnly = fOnly ? '' : 'unread'; apply()" class="rounded-xl border px-3 py-2 text-sm font-medium backdrop-blur transition" :class="fOnly ? 'border-indigo-300 bg-indigo-500/15 text-indigo-700' : 'border-white/60 bg-white/70 text-slate-600 hover:bg-white'">{{ $e('Только непрочитанные') }}</button>
                    <button v-if="unread" type="button" @click="markAll" class="ml-auto rounded-xl border border-emerald-300/60 bg-emerald-500/15 px-3 py-2 text-sm font-medium text-emerald-700 backdrop-blur transition hover:bg-emerald-500/25">✓ {{ $e('Прочитать все') }}</button>
                </template>
                <template v-else>
                    <button v-for="(m, k) in kindMeta" :key="k" type="button" @click="eventKind = eventKind === k ? '' : k"
                        class="rounded-full border px-3 py-1.5 text-xs font-medium backdrop-blur transition" :class="eventKind === k ? 'border-indigo-300 bg-indigo-500/15 text-indigo-700' : 'border-white/60 bg-white/70 text-slate-600 hover:bg-white'">{{ m.icon }} {{ m.label }}</button>
                </template>
            </div>
        </div>

        <!-- ===== Уведомления (задачи / остальные) ===== -->
        <div v-if="tab !== 'events'" class="space-y-6">
            <div v-for="g in notifDays" :key="g.day">
                <div class="mb-2 flex items-center gap-3 text-xs font-semibold uppercase tracking-wide text-slate-400"><span>{{ g.day }}</span><span class="h-px flex-1 bg-slate-200/70"></span></div>
                <div class="grid gap-2">
                    <div v-for="n in g.items" :key="n.id"
                        class="group flex gap-3 rounded-2xl border px-4 py-3 shadow-soft backdrop-blur-md transition hover:shadow-soft-md"
                        :class="!n.read_at ? 'border-indigo-200/70 bg-indigo-50/60' : 'border-white/60 bg-white/70'">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-white text-lg shadow-soft">{{ iconOf(n.type) }}</span>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-sm font-semibold text-slate-900">{{ n.title }}</span>
                                <span class="rounded-full bg-slate-900/5 px-2 py-0.5 text-xs text-slate-500">{{ n.typeLabel }}</span>
                                <span v-if="!n.read_at" class="h-2 w-2 rounded-full bg-indigo-500" />
                                <span class="ml-auto text-xs text-slate-400">{{ fmt(n.created_at) }}</span>
                            </div>
                            <div class="mt-0.5 text-sm text-slate-600">{{ n.message }}</div>
                            <div v-if="extras(n).length" class="mt-1 flex flex-wrap gap-x-3 text-xs text-slate-400">
                                <span v-for="[k, v] in extras(n)" :key="k">{{ k }}: {{ typeof v === 'object' ? JSON.stringify(v) : v }}</span>
                            </div>
                            <div class="mt-1.5 flex flex-wrap items-center gap-3 text-xs">
                                <span v-if="n.read_at" class="text-slate-400">{{ $e('прочитано') }} {{ fmt(n.read_at) }}</span>
                                <button v-if="n.url" type="button" @click="openN(n)" class="rounded-lg border border-indigo-300/60 bg-indigo-500/10 px-2.5 py-1 font-medium text-indigo-700 transition hover:bg-indigo-500/20">{{ n.deal_number ? n.deal_number : $e('Открыть') }} →</button>
                                <button v-else-if="!n.read_at" type="button" @click="openN(n)" class="font-medium text-slate-500 hover:underline">{{ $e('Отметить прочитанным') }}</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div v-if="!notifDays.length" class="rounded-3xl border border-dashed border-slate-200 bg-white/60 p-12 text-center text-sm text-slate-400 backdrop-blur">🔕 {{ $e('Нет уведомлений') }}</div>
            <Pagination :links="notifications.links" />
        </div>

        <!-- ===== События ===== -->
        <div v-else class="space-y-6">
            <div v-for="g in eventDays" :key="g.day">
                <div class="mb-2 flex items-center gap-3 text-xs font-semibold uppercase tracking-wide text-slate-400"><span>{{ g.day }}</span><span class="h-px flex-1 bg-slate-200/70"></span></div>
                <div class="relative ml-4 border-l-2 border-indigo-100 pl-5">
                    <div v-for="(e, i) in g.items" :key="i" class="relative mb-2 rounded-2xl border border-white/60 bg-white/70 px-4 py-3 shadow-soft backdrop-blur-md">
                        <span class="absolute -left-[1.95rem] top-3 flex h-7 w-7 items-center justify-center rounded-full text-sm ring-4 ring-white" :class="kindMeta[e.kind]?.cls">{{ kindMeta[e.kind]?.icon }}</span>
                        <div class="flex flex-wrap items-center gap-2 text-sm">
                            <Link v-if="e.deal?.id" :href="route('deals.show', e.deal.id)" class="font-semibold text-indigo-600 hover:underline">{{ e.deal.number }}</Link>
                            <span v-if="e.deal?.company" class="text-slate-500">{{ e.deal.company }}</span>
                            <span class="font-medium text-slate-900">{{ e.title }}</span>
                            <span v-if="e.status === 'failed'" class="rounded-full bg-rose-100 px-2 py-0.5 text-xs text-rose-700">{{ $e('ошибка') }}</span>
                            <span class="ml-auto text-xs text-slate-400">{{ fmt(e.at) }}<template v-if="e.who"> · {{ e.who }}</template></span>
                        </div>
                        <div class="mt-0.5 text-xs text-slate-500">{{ e.detail }}</div>
                    </div>
                </div>
            </div>
            <div v-if="!eventDays.length" class="rounded-3xl border border-dashed border-slate-200 bg-white/60 p-12 text-center text-sm text-slate-400 backdrop-blur">{{ $e('Событий пока нет') }}</div>
        </div>
    </AppLayout>
</template>
