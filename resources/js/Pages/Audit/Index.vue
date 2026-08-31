<script setup>
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Pagination from '@/Components/Pagination.vue';
import { useE } from '@/composables/useTranslations';

const tr = useE();

const props = defineProps({ logs: Object, filters: Object, tables: Array, users: Array });
const actionLabel = { created: tr('Создание'), updated: tr('Изменение'), deleted: tr('Удаление') };
const actionColor = { created: 'text-green-600 dark:text-green-400', updated: 'text-amber-600 dark:text-amber-400', deleted: 'text-red-600 dark:text-rose-400' };
const fmt = (t) => new Date(t).toLocaleString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });

// Фильтры: раздел, действие, пользователь, период.
const fTable = ref(props.filters?.table ?? '');
const fAction = ref(props.filters?.action ?? '');
const fUser = ref(props.filters?.user ?? '');
const fFrom = ref(props.filters?.from ?? '');
const fTo = ref(props.filters?.to ?? '');
const apply = () => router.get(route('audit.index'), {
    table: fTable.value || undefined,
    action: fAction.value || undefined,
    user: fUser.value || undefined,
    from: fFrom.value || undefined,
    to: fTo.value || undefined,
}, { preserveState: true, preserveScroll: true, replace: true });
const resetFilters = () => { fTable.value = ''; fAction.value = ''; fUser.value = ''; fFrom.value = ''; fTo.value = ''; apply(); };
const hasFilters = () => fTable.value || fAction.value || fUser.value || fFrom.value || fTo.value;
</script>

<template>
    <Head :title="$e('Аудит')" />
    <AppLayout>
        <template #header>{{ $t('page.audit', 'Журнал аудита') }}</template>

        <!-- Фильтры -->
        <div class="mb-4 flex flex-wrap items-center gap-2">
            <select v-model="fTable" @change="apply" class="rounded-lg border-slate-300 text-sm shadow-sm">
                <option value="">{{ $e('Все разделы') }}</option>
                <option v-for="t in tables" :key="t.value" :value="t.value">{{ t.label }}</option>
            </select>
            <select v-model="fAction" @change="apply" class="rounded-lg border-slate-300 text-sm shadow-sm">
                <option value="">{{ $e('Все действия') }}</option>
                <option value="created">{{ $e('Создание') }}</option>
                <option value="updated">{{ $e('Изменение') }}</option>
                <option value="deleted">{{ $e('Удаление') }}</option>
            </select>
            <select v-model="fUser" @change="apply" class="rounded-lg border-slate-300 text-sm shadow-sm">
                <option value="">{{ $e('Все пользователи') }}</option>
                <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option>
            </select>
            <input v-model="fFrom" @change="apply" type="date" class="rounded-lg border-slate-300 text-sm shadow-sm" :title="$e('Период с')" />
            <span class="text-xs text-slate-400">—</span>
            <input v-model="fTo" @change="apply" type="date" class="rounded-lg border-slate-300 text-sm shadow-sm" :title="$e('Период по')" />
            <button v-if="hasFilters()" @click="resetFilters"
                class="rounded-lg px-3 py-2 text-xs font-medium text-slate-500 dark:text-slate-400 transition hover:bg-slate-100 dark:hover:bg-slate-800/60">{{ $e('Сбросить') }}</button>
            <span class="ml-auto text-xs text-slate-400">{{ $e('записей:') }} {{ logs.total ?? logs.data.length }}</span>
        </div>

        <div class="overflow-hidden rounded-xl border border-slate-100 dark:border-slate-800 bg-white dark:bg-slate-900/70 shadow-sm">
            <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-100 dark:divide-slate-800 text-sm">
                <thead class="bg-slate-50 dark:bg-slate-800/50 text-left text-xs uppercase text-slate-500 dark:text-slate-400">
                    <tr>
                        <th class="px-4 py-3">{{ $e('Время') }}</th><th class="px-4 py-3">{{ $e('Пользователь') }}</th>
                        <th class="px-4 py-3">{{ $e('Раздел') }}</th><th class="px-4 py-3">{{ $e('Запись') }}</th>
                        <th class="px-4 py-3">{{ $e('Сделка') }}</th>
                        <th class="px-4 py-3">{{ $e('Действие') }}</th><th class="px-4 py-3">{{ $e('Что изменили') }}</th>
                        <th class="px-4 py-3">{{ $e('Было → Стало') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    <tr v-for="log in logs.data" :key="log.id" class="hover:bg-slate-50 dark:hover:bg-slate-800/60">
                        <td class="whitespace-nowrap px-4 py-3 text-slate-400">{{ fmt(log.created_at) }}</td>
                        <td class="px-4 py-3">
                            {{ log.user ?? $e('Система') }}
                            <span v-if="log.ip" class="block text-xs text-slate-300 dark:text-slate-600">{{ log.ip }}</span>
                        </td>
                        <td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ log.table }}</td>
                        <td class="px-4 py-3">
                            <Link v-if="log.link" :href="log.link" class="text-indigo-600 dark:text-indigo-400 hover:underline">#{{ log.record_id }}</Link>
                            <span v-else class="text-slate-400">#{{ log.record_id }}</span>
                        </td>
                        <!-- По какой сделке действие; у удалённой — номер серым без ссылки -->
                        <td class="px-4 py-3">
                            <Link v-if="log.deal && !log.deal.deleted" :href="route('deals.show', log.deal.id)" class="font-medium text-indigo-600 dark:text-indigo-400 hover:underline">{{ log.deal.number }}</Link>
                            <span v-else-if="log.deal" class="text-slate-400" :title="$e('Сделка удалена')">{{ log.deal.number }}</span>
                            <span v-else class="text-slate-300 dark:text-slate-600">—</span>
                        </td>
                        <td class="px-4 py-3 font-medium" :class="actionColor[log.action]">{{ actionLabel[log.action] ?? log.action }}</td>
                        <td class="px-4 py-3 text-slate-600 dark:text-slate-300">
                            {{ log.field ?? (log.snapshot.length ? $e('вся запись') : '—') }}
                        </td>
                        <td class="px-4 py-3 text-xs text-slate-500 dark:text-slate-400">
                            <!-- «не было» — поле раньше не заполнялось; «убрано» — значение очистили -->
                            <span v-if="log.field"><span :class="log.old ? 'text-red-500' : 'text-slate-300 dark:text-slate-600 italic'">{{ log.old ?? $e('не было') }}</span> → <span :class="log.new ? 'text-green-600 dark:text-green-400' : 'text-slate-300 dark:text-slate-600 italic'">{{ log.new ?? $e('убрано') }}</span></span>
                            <!-- Снимок записи: что именно ввели в модальном окне -->
                            <div v-else-if="log.snapshot.length" class="flex flex-wrap gap-1">
                                <span v-for="f in log.snapshot" :key="f.label"
                                    class="rounded border px-1.5 py-0.5"
                                    :class="log.action === 'deleted' ? 'border-red-100 bg-red-50/60 text-red-700 dark:text-red-400' : 'border-slate-200 dark:border-slate-800/80 bg-slate-50 dark:bg-slate-800/50 text-slate-600 dark:text-slate-300'">
                                    <span class="text-slate-400">{{ f.label }}:</span> <b class="font-medium">{{ f.value }}</b>
                                </span>
                            </div>
                            <span v-else>—</span>
                        </td>
                    </tr>
                    <tr v-if="!logs.data.length"><td colspan="8" class="px-4 py-8 text-center text-slate-400">{{ $e('Записей нет — измените фильтры') }}</td></tr>
                </tbody>
            </table>
            </div>
            <div class="p-4"><Pagination :links="logs.links" /></div>
        </div>
    </AppLayout>
</template>
