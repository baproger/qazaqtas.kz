<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { formatDate, money } from '@/utils/format';
import { useE } from '@/composables/useTranslations';

const tr = useE();

defineProps({ deals: Array, projects: { type: Array, default: () => [] } });

const open = (id) => router.get(route('deals.show', id));
const openProject = (id) => router.get(route('projects.show', id));
const dayLabel = (n) => {
    const abs = Math.abs(n) % 100;
    const last = abs % 10;
    if (abs > 10 && abs < 20) return tr('дней');
    if (last === 1) return tr('день');
    if (last >= 2 && last <= 4) return tr('дня');
    return tr('дней');
};
</script>

<template>
    <Head :title="$e('Просроченные сделки')" />
    <AppLayout>
        <template #header>
            <div class="flex items-center gap-3">
                <Link :href="route('deals.index')" class="text-slate-400 hover:text-slate-600">← {{ $t('page.deals', 'Сделки') }}</Link>
                <span>{{ $t('page.overdue', 'Просроченные сделки') }}</span>
            </div>
        </template>

        <!-- Две колонки: слева сделки, справа заказы цеха -->
        <div class="grid grid-cols-1 items-start gap-4 lg:grid-cols-2">
            <!-- Сделки -->
            <div class="rounded-2xl border border-slate-100 dark:border-slate-800 bg-white dark:bg-slate-900/70 shadow-sm">
                <div class="flex items-center gap-2 border-b border-slate-100 dark:border-slate-800 px-5 py-3.5">
                    <h3 class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ $e('Сделки') }}</h3>
                    <span class="rounded-full px-2 py-0.5 text-xs font-bold" :class="deals.length ? 'bg-red-100 dark:bg-red-500/20 text-red-700 dark:text-red-400' : 'bg-slate-100 dark:bg-slate-800/60 text-slate-400'">{{ deals.length }}</span>
                </div>
                <div class="divide-y divide-slate-50 dark:divide-slate-800">
                    <button v-for="deal in deals" :key="deal.id" type="button" @click="open(deal.id)"
                        class="block w-full px-5 py-3 text-left transition-colors hover:bg-red-50/50">
                        <div class="flex items-center justify-between gap-3">
                            <div class="flex min-w-0 items-center gap-2">
                                <span class="flex-shrink-0 rounded-full bg-red-100 dark:bg-red-500/20 px-2 py-0.5 text-xs font-bold text-red-700 dark:text-red-400">{{ deal.overdue_days }} {{ dayLabel(deal.overdue_days) }}</span>
                                <span class="truncate text-sm font-semibold text-slate-800 dark:text-slate-200">{{ deal.company_name || deal.name }}</span>
                            </div>
                            <span class="flex-shrink-0 whitespace-nowrap text-sm font-semibold tabular-nums text-indigo-600 dark:text-indigo-400">{{ money(deal.budget) }}</span>
                        </div>
                        <div class="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-400">
                            <span>{{ deal.number }}</span>
                            <StatusBadge :status="deal.stage?.name" :color="deal.stage?.color" />
                            <span class="whitespace-nowrap font-semibold text-red-600 dark:text-rose-400">{{ $e('срок') }} {{ formatDate(deal.deadline) }}</span>
                            <span class="ml-auto">{{ deal.responsible?.name ?? '—' }}</span>
                        </div>
                    </button>
                    <div v-if="!deals.length" class="px-5 py-12 text-center">
                        <div class="text-3xl">✅</div>
                        <p class="mt-2 text-sm text-slate-400">{{ $e('Просроченных сделок нет') }}</p>
                    </div>
                </div>
            </div>

            <!-- Цех -->
            <div class="rounded-2xl border border-slate-100 dark:border-slate-800 bg-white dark:bg-slate-900/70 shadow-sm">
                <div class="flex items-center gap-2 border-b border-slate-100 dark:border-slate-800 px-5 py-3.5">
                    <h3 class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ $e('Цех — заказы') }}</h3>
                    <span class="rounded-full px-2 py-0.5 text-xs font-bold" :class="projects.length ? 'bg-red-100 dark:bg-red-500/20 text-red-700 dark:text-red-400' : 'bg-slate-100 dark:bg-slate-800/60 text-slate-400'">{{ projects.length }}</span>
                </div>
                <div class="divide-y divide-slate-50 dark:divide-slate-800">
                    <button v-for="p in projects" :key="p.id" type="button" @click="openProject(p.id)"
                        class="block w-full px-5 py-3 text-left transition-colors hover:bg-red-50/50">
                        <div class="flex items-center justify-between gap-3">
                            <div class="flex min-w-0 items-center gap-2">
                                <span class="flex-shrink-0 rounded-full bg-red-100 dark:bg-red-500/20 px-2 py-0.5 text-xs font-bold text-red-700 dark:text-red-400">{{ p.overdue_days }} {{ dayLabel(p.overdue_days) }}</span>
                                <span class="truncate text-sm font-semibold text-slate-800 dark:text-slate-200">{{ p.deal?.company_name || p.name }}</span>
                            </div>
                            <span class="flex-shrink-0 text-xs font-medium text-slate-400">{{ p.number }}</span>
                        </div>
                        <div class="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-400">
                            <span>{{ p.deal?.number }}</span>
                            <StatusBadge :status="p.stage?.name" :color="p.stage?.color" />
                            <span class="whitespace-nowrap font-semibold text-red-600 dark:text-rose-400">{{ $e('срок') }} {{ formatDate(p.deadline) }}</span>
                            <span class="ml-auto">{{ p.responsible?.name ?? '—' }}</span>
                        </div>
                    </button>
                    <div v-if="!projects.length" class="px-5 py-12 text-center">
                        <div class="text-3xl">✅</div>
                        <p class="mt-2 text-sm text-slate-400">{{ $e('Просроченных заказов цеха нет') }}</p>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
