<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import Pagination from '@/Components/Pagination.vue';
import { onMounted, onUnmounted } from 'vue';
import { formatDate, formatDuration } from '@/utils/format';

const props = defineProps({ projects: [Array, Object], stages: Array, view: String, filters: Object, canSeeMoney: Boolean });

const money = (v) => new Intl.NumberFormat('ru-RU').format(v ?? 0) + ' ₸';
const list = computed(() => Array.isArray(props.projects) ? props.projects : props.projects.data);
const byStage = (id) => list.value.filter((p) => p.project_stage_id === id);

const draggingId = ref(null);
const onDrop = (stage) => {
    const id = draggingId.value; draggingId.value = null;
    if (!id) return;
    const p = list.value.find((x) => x.id === id);
    if (!p || p.project_stage_id === stage.id) return;
    router.patch(route('projects.stage', id), { project_stage_id: stage.id }, { preserveScroll: true, preserveState: false });
};
const switchView = (v) => router.get(route('projects.index'), { ...props.filters, view: v }, { preserveState: true });
const advance = (p) => router.patch(route('projects.advance', p.id), {}, { preserveScroll: true, preserveState: false });
// Секции канбана: если цехов несколько — своя строка этапов на каждый;
// при едином производстве (workshop=null) — одна секция без шапки.
const workshopGroups = computed(() => {
    const groups = [];
    for (const s of props.stages) {
        const key = s.workshop ?? '';
        let g = groups.find((x) => x.key === key);
        if (!g) groups.push(g = { key, name: s.workshop, stages: [] });
        g.stages.push(s);
    }
    return groups;
});
const lastStageOf = (g) => [...g.stages].reverse().find((s) => s.is_completed)?.id ?? g.stages[g.stages.length - 1]?.id;
const sendToAct = (p) => router.post(route('projects.toAct', p.id), {}, { preserveScroll: true, preserveState: false });
// Тайминг этапа: сколько заказ уже на текущем этапе (тикает раз в минуту).
const nowTs = ref(Date.now());
let durTimer = null;
onMounted(() => (durTimer = setInterval(() => (nowTs.value = Date.now()), 60000)));
onUnmounted(() => clearInterval(durTimer));
const onStage = (p) => p.stage_entered_at ? formatDuration((nowTs.value - new Date(p.stage_entered_at).getTime()) / 1000) : null;
// Сколько заказ ВСЕГО находится в цехе (с момента отправки) — крупно на карточке.
const inWorkshop = (p) => p.created_at ? formatDuration((nowTs.value - new Date(p.created_at).getTime()) / 1000) : null;
</script>

<template>
    <Head :title="$e('Проекты')" />
    <AppLayout>
        <template #header>{{ $t('page.workshop', 'Цех') }}</template>

        <div class="mb-4 inline-flex rounded-md bg-white dark:bg-slate-900/70 shadow-sm border border-slate-200 dark:border-slate-800/80">
            <button :class="view === 'kanban' ? 'bg-indigo-600 text-white' : 'text-slate-600 dark:text-slate-300'" class="rounded-l-md px-4 py-1.5 text-sm" @click="switchView('kanban')">{{ $e('Канбан') }}</button>
            <button :class="view === 'list' ? 'bg-indigo-600 text-white' : 'text-slate-600 dark:text-slate-300'" class="rounded-r-md px-4 py-1.5 text-sm" @click="switchView('list')">{{ $e('Список') }}</button>
        </div>

        <div v-if="view === 'kanban'" class="space-y-6">
        <div v-for="g in workshopGroups" :key="g.key">
            <div v-if="g.name" class="mb-2 flex items-center gap-2">
                <span class="text-sm font-bold text-slate-800 dark:text-slate-200">{{ g.name }}</span>
                <span class="rounded-full bg-slate-100 dark:bg-slate-800/60 px-2 py-0.5 text-xs tabular-nums text-slate-500 dark:text-slate-400">{{ g.stages.reduce((n, s) => n + byStage(s.id).length, 0) }}</span>
            </div>
            <div class="flex gap-4 overflow-x-auto pb-4">
            <div v-for="stage in g.stages" :key="stage.id" class="flex w-72 flex-shrink-0 flex-col rounded-lg bg-slate-200/60" @dragover.prevent @drop="onDrop(stage)">
                <div class="flex items-center justify-between px-3 py-2">
                    <div class="flex items-center gap-2">
                        <span class="h-2.5 w-2.5 rounded-full" :style="{ backgroundColor: stage.color }"></span>
                        <span class="text-sm font-semibold text-slate-700 dark:text-slate-300">{{ stage.name }}</span>
                        <span class="text-xs text-slate-400">{{ byStage(stage.id).length }}</span>
                    </div>
                </div>
                <div class="flex-1 space-y-2 px-2 pb-2">
                    <Link v-for="p in byStage(stage.id)" :key="p.id" :href="route('projects.show', p.id)" draggable="true" @dragstart="draggingId = p.id"
                        class="spotlight block cursor-move rounded-xl bg-white dark:bg-slate-900/70 p-3 shadow-sm border border-slate-200 dark:border-slate-800/80 transition-all hover:-translate-y-0.5 hover:shadow-md">
                        <!-- Для цеха главное: КТО, ЧТО делаем (все позиции с количеством),
                             КУДА везём и СКОЛЬКО заказ уже в работе. -->
                        <div class="flex items-start justify-between gap-2">
                            <div class="text-sm font-bold leading-snug text-slate-900 dark:text-slate-100">{{ p.deal?.client_name || p.deal?.company_name || p.name }}</div>
                            <span class="shrink-0 rounded-md bg-indigo-50 px-1.5 py-0.5 text-[11px] font-semibold tracking-wide text-indigo-500 dark:bg-indigo-500/10 dark:text-indigo-400">{{ p.deal?.number || p.number }}</span>
                        </div>
                        <div v-if="p.deal?.items?.length" class="mt-2 space-y-1 rounded-lg bg-slate-50 px-2.5 py-2 dark:bg-slate-800/40">
                            <div v-for="it in p.deal.items.slice(0, 3)" :key="it.id" class="flex items-baseline justify-between gap-2 text-xs">
                                <span class="truncate font-medium text-slate-700 dark:text-slate-300">🧱 {{ it.name }}</span>
                                <span class="shrink-0 font-semibold tabular-nums text-slate-600 dark:text-slate-400">{{ Number(it.quantity) }} {{ it.unit }}</span>
                            </div>
                            <div v-if="p.deal.items.length > 3" class="text-[11px] font-medium text-indigo-400">+{{ p.deal.items.length - 3 }}</div>
                        </div>
                        <div class="mt-2 space-y-0.5 text-xs leading-snug text-slate-500 dark:text-slate-400">
                            <div v-if="p.deal?.address">📍 {{ p.deal.address }}</div>
                            <div v-if="p.deal?.foreman" class="truncate" :title="$e('Бригадир')">👷 {{ p.deal.foreman.name }}</div>
                            <div v-if="p.deal?.deadline">🗓 {{ formatDate(p.deal.deadline) }}</div>
                        </div>
                        <!-- Таймеры — два градиентных блока в одну строку:
                             яркий индиго-фиолетовый «в цехе» и спокойный «на этапе». -->
                        <div class="mt-2 grid grid-cols-2 gap-1.5">
                            <div class="rounded-lg bg-gradient-to-br from-indigo-500/15 via-indigo-500/10 to-violet-500/5 px-2.5 py-1.5" :title="$e('Сколько заказ находится в цехе')">
                                <div class="text-[10px] font-semibold uppercase tracking-wide text-indigo-400">{{ $e('⏱ в цехе') }}</div>
                                <div class="mt-0.5 text-lg font-bold leading-none tabular-nums text-indigo-700 dark:text-indigo-300">{{ inWorkshop(p) ?? '—' }}</div>
                            </div>
                            <div class="rounded-lg bg-gradient-to-br from-slate-500/10 via-slate-500/5 to-transparent px-2.5 py-1.5">
                                <div class="text-[10px] font-semibold uppercase tracking-wide text-slate-400">{{ $e('на этапе') }}</div>
                                <div class="mt-0.5 text-lg font-bold leading-none tabular-nums text-slate-600 dark:text-slate-300">{{ onStage(p) ?? '—' }}</div>
                            </div>
                        </div>
                        <button v-if="p.project_stage_id === lastStageOf(g)" @click.prevent.stop="sendToAct(p)" class="mt-2 w-full rounded bg-teal-600 py-1 text-xs font-semibold text-white hover:bg-teal-700">{{ $e('🚚 Готово → Логистика') }}</button>
                        <button v-else @click.prevent.stop="advance(p)" class="mt-2 w-full rounded bg-slate-100 dark:bg-slate-800/60 py-1 text-xs text-slate-600 dark:text-slate-300 hover:bg-indigo-100 hover:text-indigo-700">{{ $e('Далее →') }}</button>
                    </Link>
                    <div v-if="!byStage(stage.id).length" class="py-6 text-center text-xs text-slate-400">{{ $e('Пусто') }}</div>
                </div>
            </div>
            </div>
        </div>
        </div>

        <div v-else class="overflow-hidden rounded-xl border border-slate-100 dark:border-slate-800 bg-white dark:bg-slate-900/70 shadow-sm">
            <table class="min-w-full divide-y divide-slate-100 dark:divide-slate-800 text-sm">
                <thead class="bg-slate-50 dark:bg-slate-800/50 text-left text-xs uppercase text-slate-500 dark:text-slate-400">
                    <tr>
                        <th class="px-4 py-3">{{ $e('Номер') }}</th><th class="px-4 py-3">{{ $e('Компания') }}</th><th class="px-4 py-3">{{ $e('Клиент') }}</th>
                        <th class="px-4 py-3">{{ $e('Этап') }}</th><th v-if="canSeeMoney" class="px-4 py-3">{{ $e('Бюджет') }}</th><th class="px-4 py-3">{{ $e('Статус') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    <tr v-for="p in projects.data" :key="p.id" class="cursor-pointer hover:bg-slate-50 dark:hover:bg-slate-800/60" @click="router.get(route('projects.show', p.id))">
                        <td class="px-4 py-3 text-slate-400">{{ p.number }}</td>
                        <td class="px-4 py-3 font-medium text-slate-900 dark:text-slate-100">{{ p.deal?.company_name || p.name }}</td>
                        <td class="px-4 py-3 text-slate-500 dark:text-slate-400">{{ p.client?.name ?? '—' }}</td>
                        <td class="px-4 py-3"><StatusBadge :status="p.stage?.name" :color="p.stage?.color" /></td>
                        <td v-if="canSeeMoney" class="px-4 py-3">{{ money(p.budget) }}</td>
                        <td class="px-4 py-3"><StatusBadge :status="p.status" /></td>
                    </tr>
                </tbody>
            </table>
            <div class="p-4"><Pagination :links="projects.links" /></div>
        </div>
    </AppLayout>
</template>
