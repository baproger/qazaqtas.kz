<script setup>
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Pagination from '@/Components/Pagination.vue';

const props = defineProps({ logs: Object, filters: Object, tables: Array, users: Array });
const actionLabel = { created: 'Создание', updated: 'Изменение', deleted: 'Удаление' };
const actionColor = { created: 'text-green-600', updated: 'text-amber-600', deleted: 'text-red-600' };
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
    <Head title="Аудит" />
    <AppLayout>
        <template #header>{{ $t('page.audit', 'Журнал аудита') }}</template>

        <!-- Фильтры -->
        <div class="mb-4 flex flex-wrap items-center gap-2">
            <select v-model="fTable" @change="apply" class="rounded-lg border-slate-300 text-sm shadow-sm">
                <option value="">Все разделы</option>
                <option v-for="t in tables" :key="t.value" :value="t.value">{{ t.label }}</option>
            </select>
            <select v-model="fAction" @change="apply" class="rounded-lg border-slate-300 text-sm shadow-sm">
                <option value="">Все действия</option>
                <option value="created">Создание</option>
                <option value="updated">Изменение</option>
                <option value="deleted">Удаление</option>
            </select>
            <select v-model="fUser" @change="apply" class="rounded-lg border-slate-300 text-sm shadow-sm">
                <option value="">Все пользователи</option>
                <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option>
            </select>
            <input v-model="fFrom" @change="apply" type="date" class="rounded-lg border-slate-300 text-sm shadow-sm" title="Период с" />
            <span class="text-xs text-slate-400">—</span>
            <input v-model="fTo" @change="apply" type="date" class="rounded-lg border-slate-300 text-sm shadow-sm" title="Период по" />
            <button v-if="hasFilters()" @click="resetFilters"
                class="rounded-lg px-3 py-2 text-xs font-medium text-slate-500 transition hover:bg-slate-100">Сбросить</button>
            <span class="ml-auto text-xs text-slate-400">записей: {{ logs.total ?? logs.data.length }}</span>
        </div>

        <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
            <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-100 text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase text-slate-500">
                    <tr>
                        <th class="px-4 py-3">Время</th><th class="px-4 py-3">Пользователь</th>
                        <th class="px-4 py-3">Раздел</th><th class="px-4 py-3">Запись</th>
                        <th class="px-4 py-3">Сделка</th>
                        <th class="px-4 py-3">Действие</th><th class="px-4 py-3">Что изменили</th>
                        <th class="px-4 py-3">Было → Стало</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="log in logs.data" :key="log.id" class="hover:bg-slate-50">
                        <td class="whitespace-nowrap px-4 py-3 text-slate-400">{{ fmt(log.created_at) }}</td>
                        <td class="px-4 py-3">
                            {{ log.user ?? 'Система' }}
                            <span v-if="log.ip" class="block text-[10px] text-slate-300">{{ log.ip }}</span>
                        </td>
                        <td class="px-4 py-3 text-slate-600">{{ log.table }}</td>
                        <td class="px-4 py-3">
                            <Link v-if="log.link" :href="log.link" class="text-indigo-600 hover:underline">#{{ log.record_id }}</Link>
                            <span v-else class="text-slate-400">#{{ log.record_id }}</span>
                        </td>
                        <!-- По какой сделке действие; у удалённой — номер серым без ссылки -->
                        <td class="px-4 py-3">
                            <Link v-if="log.deal && !log.deal.deleted" :href="route('deals.show', log.deal.id)" class="font-medium text-indigo-600 hover:underline">{{ log.deal.number }}</Link>
                            <span v-else-if="log.deal" class="text-slate-400" title="Сделка удалена">{{ log.deal.number }}</span>
                            <span v-else class="text-slate-300">—</span>
                        </td>
                        <td class="px-4 py-3 font-medium" :class="actionColor[log.action]">{{ actionLabel[log.action] ?? log.action }}</td>
                        <td class="px-4 py-3 text-slate-600">{{ log.field ?? '—' }}</td>
                        <td class="px-4 py-3 text-xs text-slate-500">
                            <!-- «не было» — поле раньше не заполнялось; «убрано» — значение очистили -->
                            <span v-if="log.field"><span :class="log.old ? 'text-red-500' : 'text-slate-300 italic'">{{ log.old ?? 'не было' }}</span> → <span :class="log.new ? 'text-green-600' : 'text-slate-300 italic'">{{ log.new ?? 'убрано' }}</span></span>
                            <span v-else>—</span>
                        </td>
                    </tr>
                    <tr v-if="!logs.data.length"><td colspan="8" class="px-4 py-8 text-center text-slate-400">Записей нет — измените фильтры</td></tr>
                </tbody>
            </table>
            </div>
            <div class="p-4"><Pagination :links="logs.links" /></div>
        </div>
    </AppLayout>
</template>
