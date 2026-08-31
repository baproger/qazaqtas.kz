<script setup>
/**
 * Журнал ошибок — только админ. Всё, что ломалось на сайте и в ERP:
 * от 404 до падения базы. Повторы схлопнуты в одну строку со счётчиком.
 */
import { ref, computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Pagination from '@/Components/Pagination.vue';
import { useE } from '@/composables/useTranslations';
import { confirmDialog } from '@/composables/useConfirm';

const tr = useE();
const props = defineProps({ logs: Object, filters: Object, stats: Object, openTotal: Number });

const levels = {
    critical: { label: tr('Критические'), one: tr('Критическая'), dot: 'bg-rose-600', badge: 'bg-rose-50 dark:bg-rose-500/10 text-rose-700 dark:text-rose-400 ring-rose-200 dark:ring-rose-500/30', tile: 'from-rose-50 to-white border-rose-100 text-rose-700' },
    error: { label: tr('Ошибки'), one: tr('Ошибка'), dot: 'bg-orange-500', badge: 'bg-orange-50 dark:bg-orange-500/10 text-orange-700 dark:text-orange-300 ring-orange-200', tile: 'from-orange-50 to-white border-orange-100 text-orange-700' },
    warning: { label: tr('Предупреждения'), one: tr('Предупреждение'), dot: 'bg-amber-400', badge: 'bg-amber-50 dark:bg-amber-500/10 text-amber-700 dark:text-amber-400 ring-amber-200 dark:ring-amber-500/30', tile: 'from-amber-50 to-white border-amber-100 text-amber-700' },
    info: { label: tr('Простые'), one: tr('Простая'), dot: 'bg-sky-400', badge: 'bg-sky-50 dark:bg-sky-500/10 text-sky-700 dark:text-sky-400 ring-sky-200', tile: 'from-sky-50 to-white border-sky-100 text-sky-700' },
};
const sourceLabel = { server: tr('Сервер'), browser: tr('Браузер') };

const fLevel = ref(props.filters?.level ?? '');
const fStatus = ref(props.filters?.status ?? 'open');
const fSource = ref(props.filters?.source ?? '');
const fSearch = ref(props.filters?.search ?? '');
let searchTimer = null;
const apply = () => router.get(route('errors.index'), {
    level: fLevel.value || undefined, status: fStatus.value !== 'open' ? fStatus.value : undefined,
    source: fSource.value || undefined, search: fSearch.value || undefined,
}, { preserveState: true, preserveScroll: true, replace: true });
const onSearch = () => { clearTimeout(searchTimer); searchTimer = setTimeout(apply, 350); };
const setLevel = (l) => { fLevel.value = fLevel.value === l ? '' : l; apply(); };

const open = ref(new Set());
const toggle = (id) => { const s = new Set(open.value); s.has(id) ? s.delete(id) : s.add(id); open.value = s; };

const fmt = (t) => t ? new Date(t).toLocaleString('ru-RU', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }) : '—';
const ago = (t) => {
    if (!t) return '';
    const m = Math.round((Date.now() - new Date(t)) / 60000);
    if (m < 1) return tr('только что');
    if (m < 60) return `${m} ${tr('мин назад')}`;
    if (m < 1440) return `${Math.round(m / 60)} ${tr('ч назад')}`;
    return `${Math.round(m / 1440)} ${tr('дн назад')}`;
};
const shortUrl = (u) => { try { const x = new URL(u); return x.pathname + x.search; } catch { return u; } };

const resolve = (e) => router.patch(route('errors.resolve', e.id), {}, { preserveScroll: true });
const resolveAll = async () => {
    if (await confirmDialog({ title: tr('Отметить все открытые как разобранные?'), confirmText: tr('Отметить') })) {
        router.post(route('errors.resolveAll'), { level: fLevel.value || null }, { preserveScroll: true });
    }
};
const purge = async () => {
    if (await confirmDialog({ title: tr('Удалить разобранные ошибки старше 30 дней?'), confirmText: tr('Удалить'), danger: true })) {
        router.delete(route('errors.purge'), { preserveScroll: true });
    }
};
const total = computed(() => props.logs.total ?? props.logs.data.length);
</script>

<template>
    <Head :title="$e('Ошибки')" />
    <AppLayout>
        <template #header>{{ $e('Журнал ошибок') }}</template>

        <!-- Сводка за сутки -->
        <div class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
            <button v-for="(l, key) in levels" :key="key" type="button" @click="setLevel(key)"
                class="rounded-2xl border bg-gradient-to-br p-4 text-left shadow-sm transition hover:shadow-md"
                :class="[l.tile, fLevel === key ? 'ring-2 ring-indigo-500' : '']">
                <div class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide">
                    <span class="h-2 w-2 rounded-full" :class="l.dot" />{{ l.label }}
                </div>
                <div class="mt-2 text-3xl font-bold tabular-nums text-slate-900 dark:text-slate-100">{{ stats[key]?.n ?? 0 }}</div>
                <div class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{{ $e('за сутки') }} · {{ stats[key]?.hits ?? 0 }} {{ $e('повторов') }}</div>
            </button>
        </div>

        <!-- Фильтры -->
        <div class="mb-4 flex flex-wrap items-center gap-2">
            <div class="flex rounded-lg border border-slate-200 dark:border-slate-800/80 bg-white dark:bg-slate-900/70 p-0.5 text-sm">
                <button v-for="s in [['open', tr('Открытые')], ['resolved', tr('Разобранные')], ['all', tr('Все')]]" :key="s[0]" type="button"
                    @click="fStatus = s[0]; apply()"
                    class="rounded-md px-3 py-1.5 font-medium transition"
                    :class="fStatus === s[0] ? 'bg-slate-900 text-white dark:bg-slate-100 dark:text-slate-900' : 'text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800/60'">{{ s[1] }}</button>
            </div>
            <select v-model="fSource" @change="apply" class="rounded-lg border-slate-300 text-sm shadow-sm">
                <option value="">{{ $e('Сервер и браузер') }}</option>
                <option value="server">{{ $e('Сервер') }}</option>
                <option value="browser">{{ $e('Браузер') }}</option>
            </select>
            <input v-model="fSearch" @input="onSearch" type="search" :placeholder="$e('Поиск по тексту или адресу')"
                class="w-64 rounded-lg border-slate-300 text-sm shadow-sm" />
            <span class="ml-auto text-xs text-slate-400">{{ $e('записей:') }} {{ total }}</span>
            <button v-if="openTotal" type="button" @click="resolveAll"
                class="rounded-lg border border-slate-200 dark:border-slate-800/80 bg-white dark:bg-slate-900/70 px-3 py-1.5 text-xs font-medium text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800/60">{{ $e('Разобрать все') }}</button>
            <button type="button" @click="purge"
                class="rounded-lg px-3 py-1.5 text-xs font-medium text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800/60 hover:text-rose-600">{{ $e('Очистить старые') }}</button>
        </div>

        <!-- Список -->
        <div v-if="logs.data.length" class="divide-y divide-slate-100 dark:divide-slate-800 overflow-hidden rounded-2xl border border-slate-100 dark:border-slate-800 bg-white dark:bg-slate-900/70 shadow-sm">
            <div v-for="e in logs.data" :key="e.id" :class="e.resolved_at ? 'opacity-60' : ''">
                <button type="button" @click="toggle(e.id)" class="flex w-full items-start gap-3 px-5 py-3.5 text-left transition hover:bg-slate-50 dark:hover:bg-slate-800/60">
                    <span class="mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full" :class="levels[e.level]?.dot" />
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="rounded-md px-1.5 py-0.5 text-xs font-semibold ring-1" :class="levels[e.level]?.badge">{{ e.kind }}</span>
                            <span class="text-xs text-slate-400">{{ sourceLabel[e.source] }}</span>
                            <span v-if="e.count > 1" class="rounded-full bg-slate-900 px-2 py-0.5 text-xs font-semibold text-white">× {{ e.count }}</span>
                            <span v-if="e.resolved_at" class="rounded-full bg-emerald-50 dark:bg-emerald-500/10 px-2 py-0.5 text-xs font-semibold text-emerald-700 dark:text-emerald-400 ring-1 ring-emerald-200 dark:ring-emerald-500/30">{{ $e('Разобрано') }}</span>
                        </div>
                        <div class="mt-1 truncate text-sm font-medium text-slate-900 dark:text-slate-100">{{ e.message }}</div>
                        <div class="mt-0.5 flex flex-wrap gap-x-3 text-xs text-slate-500 dark:text-slate-400">
                            <span v-if="e.url" class="truncate font-mono">{{ e.method }} {{ shortUrl(e.url) }}</span>
                            <span v-if="e.file" class="font-mono">{{ e.file }}:{{ e.line }}</span>
                            <span v-if="e.user">👤 {{ e.user }}</span>
                        </div>
                    </div>
                    <div class="shrink-0 text-right text-xs text-slate-400">
                        <div class="font-medium text-slate-600 dark:text-slate-300">{{ ago(e.last_seen_at) }}</div>
                        <div>{{ fmt(e.last_seen_at) }}</div>
                    </div>
                </button>

                <div v-if="open.has(e.id)" class="border-t border-slate-100 dark:border-slate-800 bg-slate-50/70 dark:bg-slate-800/50 px-5 py-4 text-sm">
                    <dl class="grid gap-x-6 gap-y-2 sm:grid-cols-2 lg:grid-cols-3">
                        <div><dt class="text-xs uppercase text-slate-400">{{ $e('Уровень') }}</dt><dd class="font-medium">{{ levels[e.level]?.one }}<template v-if="e.status"> · HTTP {{ e.status }}</template></dd></div>
                        <div><dt class="text-xs uppercase text-slate-400">{{ $e('Впервые') }}</dt><dd>{{ fmt(e.first_seen_at) }}</dd></div>
                        <div><dt class="text-xs uppercase text-slate-400">{{ $e('Последний раз') }}</dt><dd>{{ fmt(e.last_seen_at) }}</dd></div>
                        <div v-if="e.ip"><dt class="text-xs uppercase text-slate-400">IP</dt><dd class="font-mono">{{ e.ip }}</dd></div>
                        <div v-if="e.context?.route"><dt class="text-xs uppercase text-slate-400">{{ $e('Маршрут') }}</dt><dd class="font-mono">{{ e.context.route }}</dd></div>
                        <div v-if="e.context?.referer" class="lg:col-span-3"><dt class="text-xs uppercase text-slate-400">{{ $e('Откуда пришли') }}</dt><dd class="truncate font-mono">{{ e.context.referer }}</dd></div>
                        <div v-if="e.url" class="lg:col-span-3"><dt class="text-xs uppercase text-slate-400">{{ $e('Адрес') }}</dt><dd class="break-all font-mono">{{ e.url }}</dd></div>
                        <div v-if="e.user_agent" class="lg:col-span-3"><dt class="text-xs uppercase text-slate-400">{{ $e('Браузер') }}</dt><dd class="truncate text-slate-500 dark:text-slate-400">{{ e.user_agent }}</dd></div>
                        <div v-if="e.resolved_at" class="lg:col-span-3"><dt class="text-xs uppercase text-slate-400">{{ $e('Разобрано') }}</dt><dd>{{ fmt(e.resolved_at) }} · {{ e.resolved_by }}</dd></div>
                    </dl>
                    <pre v-if="e.trace" class="mt-3 max-h-72 overflow-auto rounded-xl bg-slate-900 p-4 font-mono text-xs leading-relaxed text-slate-200">{{ e.trace }}</pre>
                    <div class="mt-3 flex justify-end">
                        <button type="button" @click="resolve(e)"
                            class="rounded-lg px-3 py-1.5 text-sm font-medium transition"
                            :class="e.resolved_at ? 'border border-slate-200 dark:border-slate-800/80 bg-white dark:bg-slate-900/70 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800/60' : 'bg-emerald-600 text-white hover:bg-emerald-700'">
                            {{ e.resolved_at ? $e('Открыть снова') : $e('Разобрано') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <div v-else class="rounded-2xl border border-dashed border-slate-200 dark:border-slate-800/80 bg-white dark:bg-slate-900/70 p-12 text-center">
            <div class="text-3xl">✅</div>
            <div class="mt-2 text-sm font-medium text-slate-700 dark:text-slate-300">{{ $e('Ошибок нет') }}</div>
            <div class="mt-1 text-xs text-slate-400">{{ $e('Сюда попадает всё, что ломается на сайте и в ERP: от 404 до падения базы.') }}</div>
        </div>

        <Pagination :links="logs.links" class="mt-4" />
    </AppLayout>
</template>
