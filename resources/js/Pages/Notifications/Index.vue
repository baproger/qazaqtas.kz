<script setup>
/** Уведомления целиком + лента событий по сделкам, которые человек видит. */
import { ref, computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Pagination from '@/Components/Pagination.vue';
import { useE } from '@/composables/useTranslations';

const tr = useE();
const props = defineProps({ notifications: Object, types: Array, filters: Object, unread: Number, events: Array });

const tab = ref('notifications');
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
const notifDays = computed(() => groupByDay(props.notifications.data, 'created_at'));
const eventDays = computed(() => groupByDay(props.events.filter((e) => !eventKind.value || e.kind === eventKind.value), 'at'));
const eventKind = ref('');

const iconOf = (type) => ({ deal_stage_changed: '📊', task_assigned: '✅', task_overdue: '⏰', department_task_overdue: '⏰', expense_pending: '🧾', expense_confirmed: '✅', expense_handled: '🧾', expense_threshold: '⚠️', company_expense_submitted: '🧾', company_expense_paid: '💸', company_expense_stale: '⏳', finance_deleted: '🗑️', product_shortage: '📦', production_plan_queued: '🏭', site_order: '🛒', chat_mention: '💬', birthday: '🎂', robot: '🤖' })[type] ?? '🔔';
const kindMeta = { stage: { icon: '📊', label: tr('Этапы'), cls: 'bg-indigo-100 text-indigo-700' }, task: { icon: '✅', label: tr('Задачи'), cls: 'bg-emerald-100 text-emerald-700' }, robot: { icon: '🤖', label: tr('Роботы'), cls: 'bg-violet-100 text-violet-700' } };
const openN = (n) => { if (!n.read_at) router.patch(route('notifications.read', n.id), {}, { preserveScroll: true }); if (n.url) router.get(n.url); };
const markAll = () => router.patch(route('notifications.readAll'), {}, { preserveScroll: true });
const extras = (n) => Object.entries(n.data ?? {}).filter(([k]) => !['type', 'title', 'message', 'url', 'deal_id', 'deal_number'].includes(k));
</script>

<template>
    <Head :title="$e('Уведомления')" />
    <AppLayout>
        <template #header>{{ $e('Уведомления и события') }}</template>

        <div class="mb-4 flex flex-wrap items-center gap-2">
            <div class="flex rounded-lg border border-slate-200 bg-white p-0.5 text-sm">
                <button type="button" @click="tab = 'notifications'" class="rounded-md px-3 py-1.5 font-medium" :class="tab === 'notifications' ? 'bg-slate-900 text-white' : 'text-slate-600'">
                    {{ $e('Уведомления') }} <span v-if="unread" class="ml-1 rounded-full bg-rose-500 px-1.5 text-xs text-white">{{ unread }}</span>
                </button>
                <button type="button" @click="tab = 'events'" class="rounded-md px-3 py-1.5 font-medium" :class="tab === 'events' ? 'bg-slate-900 text-white' : 'text-slate-600'">{{ $e('События') }} <span class="opacity-60">{{ events.length }}</span></button>
            </div>
            <template v-if="tab === 'notifications'">
                <select v-model="fType" @change="apply" class="rounded-lg border-slate-300 text-sm shadow-sm">
                    <option value="all">{{ $e('Все типы') }}</option>
                    <option v-for="t in types" :key="t.value" :value="t.value">{{ t.label }} ({{ t.count }})</option>
                </select>
                <button type="button" @click="fOnly = fOnly ? '' : 'unread'; apply()" class="rounded-lg border px-3 py-1.5 text-sm font-medium" :class="fOnly ? 'border-indigo-600 bg-indigo-50 text-indigo-700' : 'border-slate-200 bg-white text-slate-600'">{{ $e('Только непрочитанные') }}</button>
                <button v-if="unread" type="button" @click="markAll" class="ml-auto rounded-lg px-3 py-1.5 text-sm font-medium text-indigo-600 hover:bg-indigo-50">{{ $e('Прочитать все') }}</button>
            </template>
            <template v-else>
                <button v-for="(m, k) in kindMeta" :key="k" type="button" @click="eventKind = eventKind === k ? '' : k"
                    class="rounded-full border px-3 py-1 text-xs font-medium" :class="eventKind === k ? 'border-indigo-600 bg-indigo-600 text-white' : 'border-slate-200 bg-white text-slate-600'">{{ m.icon }} {{ m.label }}</button>
            </template>
        </div>

        <!-- ===== Уведомления ===== -->
        <div v-if="tab === 'notifications'" class="space-y-5">
            <div v-for="g in notifDays" :key="g.day">
                <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">{{ g.day }}</div>
                <div class="divide-y divide-slate-100 overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-sm">
                    <div v-for="n in g.items" :key="n.id" class="flex gap-3 px-5 py-3.5" :class="!n.read_at ? 'bg-indigo-50/40' : ''">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-slate-100 text-base">{{ iconOf(n.type) }}</span>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-sm font-semibold text-slate-900">{{ n.title }}</span>
                                <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-500">{{ n.typeLabel }}</span>
                                <span v-if="!n.read_at" class="h-2 w-2 rounded-full bg-indigo-500" />
                            </div>
                            <div class="mt-0.5 text-sm text-slate-600">{{ n.message }}</div>
                            <div v-if="extras(n).length" class="mt-1 flex flex-wrap gap-x-3 text-xs text-slate-400">
                                <span v-for="[k, v] in extras(n)" :key="k">{{ k }}: {{ typeof v === 'object' ? JSON.stringify(v) : v }}</span>
                            </div>
                            <div class="mt-1 flex flex-wrap items-center gap-3 text-xs text-slate-400">
                                <span>{{ fmt(n.created_at) }}</span>
                                <span v-if="n.read_at">{{ $e('прочитано') }} {{ fmt(n.read_at) }}</span>
                                <button v-if="n.url" type="button" @click="openN(n)" class="font-medium text-indigo-600 hover:underline">{{ n.deal_number ? n.deal_number : $e('Открыть') }} →</button>
                                <button v-else-if="!n.read_at" type="button" @click="openN(n)" class="font-medium text-slate-500 hover:underline">{{ $e('Отметить прочитанным') }}</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div v-if="!notifications.data.length" class="rounded-2xl border border-dashed border-slate-200 bg-white p-12 text-center text-sm text-slate-400">🔕 {{ $e('Нет уведомлений') }}</div>
            <Pagination :links="notifications.links" />
        </div>

        <!-- ===== События ===== -->
        <div v-else class="space-y-5">
            <div v-for="g in eventDays" :key="g.day">
                <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">{{ g.day }}</div>
                <div class="relative ml-4 border-l-2 border-slate-100 pl-5">
                    <div v-for="(e, i) in g.items" :key="i" class="relative mb-3 rounded-2xl border border-slate-100 bg-white px-4 py-3 shadow-sm">
                        <span class="absolute -left-[1.85rem] top-3.5 flex h-6 w-6 items-center justify-center rounded-full text-xs ring-4 ring-white" :class="kindMeta[e.kind]?.cls">{{ kindMeta[e.kind]?.icon }}</span>
                        <div class="flex flex-wrap items-center gap-2 text-sm">
                            <Link v-if="e.deal?.id" :href="route('deals.show', e.deal.id)" class="font-semibold text-indigo-600 hover:underline">{{ e.deal.number }}</Link>
                            <span v-if="e.deal?.company" class="text-slate-500">{{ e.deal.company }}</span>
                            <span class="font-medium text-slate-900">{{ e.title }}</span>
                            <span v-if="e.status === 'failed'" class="rounded-full bg-rose-100 px-2 py-0.5 text-xs text-rose-700">{{ $e('ошибка') }}</span>
                        </div>
                        <div class="mt-0.5 text-xs text-slate-500">{{ e.detail }}</div>
                        <div class="mt-1 text-xs text-slate-400">{{ fmt(e.at) }}<template v-if="e.who"> · {{ e.who }}</template></div>
                    </div>
                </div>
            </div>
            <div v-if="!eventDays.length" class="rounded-2xl border border-dashed border-slate-200 bg-white p-12 text-center text-sm text-slate-400">{{ $e('Событий пока нет') }}</div>
        </div>
    </AppLayout>
</template>
