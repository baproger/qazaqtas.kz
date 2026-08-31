<script setup>
import { ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { confirmDialog } from '@/composables/useConfirm';
import Pagination from '@/Components/Pagination.vue';
import { useE } from '@/composables/useTranslations';

const tr = useE();

const props = defineProps({ orders: Object, filters: Object, statuses: Object, stats: Object });

const money = (v) => new Intl.NumberFormat('ru-RU').format(Math.round(v ?? 0)) + ' ₸';
const search = ref(props.filters?.search ?? '');
const status = ref(props.filters?.status ?? '');
const expanded = ref(null);
let timer = null;

const apply = () => router.get(route('siteOrders.index'), {
    search: search.value || undefined,
    status: status.value || undefined,
}, { preserveState: true, preserveScroll: true, replace: true });

watch(search, () => { clearTimeout(timer); timer = setTimeout(apply, 350); });
watch(status, apply);

const statusClass = (s) => ({
    new: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-400',
    in_work: 'bg-indigo-100 text-indigo-700 dark:bg-indigo-500/20 dark:text-indigo-300',
    done: 'bg-slate-100 text-slate-600 dark:bg-slate-800/60 dark:text-slate-300',
    cancelled: 'bg-rose-100 text-rose-700 dark:bg-rose-500/20 dark:text-rose-400',
}[s] ?? 'bg-slate-100 text-slate-600 dark:bg-slate-800/60 dark:text-slate-300');

const setStatus = (order, value) => router.patch(route('siteOrders.update', order.id), { status: value }, { preserveScroll: true });

const toDeal = async (order) => {
    if (!(await confirmDialog({
        title: `Создать сделку из заказа ${order.number}?`,
        message: tr('Сделка появится на первом этапе воронки, состав заказа уйдёт в описание.'),
        confirmText: tr('Создать сделку'),
    }))) return;
    router.post(route('siteOrders.convert', order.id), {}, { preserveScroll: true });
};

const remove = async (order) => {
    if (!(await confirmDialog({ title: `Удалить заказ ${order.number}?`, confirmText: tr('Удалить'), danger: true }))) return;
    router.delete(route('siteOrders.destroy', order.id), { preserveScroll: true });
};
</script>

<template>
    <AppLayout>
        <template #header>{{ $e('Заказы с сайта') }}</template>

        <div class="mb-4 grid gap-3 sm:grid-cols-3">
            <div class="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900/70">
                <p class="text-xs uppercase tracking-wide text-slate-400">{{ $e('Новых') }}</p>
                <p class="mt-1 text-2xl font-bold text-emerald-600 dark:text-emerald-400">{{ stats.new }}</p>
            </div>
            <div class="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900/70">
                <p class="text-xs uppercase tracking-wide text-slate-400">{{ $e('Заказов за месяц') }}</p>
                <p class="mt-1 text-2xl font-bold text-slate-900 dark:text-slate-100">{{ stats.month }}</p>
            </div>
            <div class="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900/70">
                <p class="text-xs uppercase tracking-wide text-slate-400">{{ $e('Сумма за месяц') }}</p>
                <p class="mt-1 whitespace-nowrap text-2xl font-bold text-slate-900 dark:text-slate-100">{{ money(stats.monthSum) }}</p>
            </div>
        </div>

        <div class="mb-4 flex flex-wrap items-center gap-2">
            <input v-model="search" type="search" :placeholder="$e('Номер, имя или телефон…')" class="w-64 rounded-lg border-slate-200 py-2 text-sm shadow-sm" />
            <select v-model="status" class="rounded-lg border-slate-200 py-2 text-sm text-slate-600 shadow-sm">
                <option value="">{{ $e('Все статусы') }}</option>
                <option v-for="(label, key) in statuses" :key="key" :value="key">{{ label }}</option>
            </select>
            <span class="ml-auto text-xs text-slate-400">{{ $e('Всего:') }} {{ orders.total }}</span>
        </div>

        <div class="overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900/70">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-100 text-sm dark:divide-slate-800">
                    <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-400 dark:bg-slate-800/50">
                        <tr>
                            <th class="px-4 py-2.5">{{ $e('Заказ') }}</th>
                            <th class="px-4 py-2.5">{{ $e('Клиент') }}</th>
                            <th class="px-4 py-2.5">{{ $e('Город') }}</th>
                            <th class="px-4 py-2.5 text-right">{{ $e('Сумма') }}</th>
                            <th class="px-4 py-2.5">{{ $e('Статус') }}</th>
                            <th class="px-4 py-2.5">{{ $e('Сделка') }}</th>
                            <th class="px-4 py-2.5"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50 dark:divide-slate-800">
                        <template v-for="order in orders.data" :key="order.id">
                            <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/60">
                                <td class="px-4 py-3">
                                    <button class="font-medium text-indigo-600 hover:underline dark:text-indigo-400" @click="expanded = expanded === order.id ? null : order.id">{{ order.number }}</button>
                                    <span class="block text-xs text-slate-400">{{ new Date(order.created_at).toLocaleString('ru-RU') }}</span>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="block text-slate-800 dark:text-slate-200">{{ order.name }}</span>
                                    <a :href="`tel:${order.phone}`" class="text-xs text-indigo-600 hover:underline dark:text-indigo-400">{{ order.phone }}</a>
                                </td>
                                <td class="px-4 py-3 text-slate-500 dark:text-slate-400">{{ order.city ?? '—' }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-right font-semibold tabular-nums text-slate-900 dark:text-slate-100">{{ money(order.total) }}</td>
                                <td class="px-4 py-3">
                                    <select :value="order.status" class="rounded-full border-0 px-2.5 py-1 text-xs font-semibold focus:ring-0" :class="statusClass(order.status)" @change="setStatus(order, $event.target.value)">
                                        <option v-for="(label, key) in statuses" :key="key" :value="key">{{ label }}</option>
                                    </select>
                                </td>
                                <td class="px-4 py-3">
                                    <Link v-if="order.deal" :href="route('deals.show', order.deal.id)" class="text-xs font-semibold text-indigo-600 hover:underline dark:text-indigo-400">{{ order.deal.number }}</Link>
                                    <span v-else class="text-xs text-slate-300 dark:text-slate-600">—</span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-right">
                                    <button v-if="!order.deal" class="rounded-lg bg-emerald-600 px-2.5 py-1.5 text-xs font-semibold text-white transition hover:bg-emerald-700" @click="toDeal(order)">{{ $e('В сделку') }}</button>
                                    <button class="ml-1 rounded p-1 text-slate-300 transition hover:text-rose-600 dark:text-slate-600 dark:hover:text-rose-400" :title="$e('Удалить')" @click="remove(order)">✕</button>
                                </td>
                            </tr>
                            <tr v-if="expanded === order.id" class="bg-slate-50/60 dark:bg-slate-800/40 dark:bg-slate-800/50">
                                <td colspan="7" class="px-6 py-4">
                                    <div class="grid gap-6 md:grid-cols-[1fr_260px]">
                                        <div>
                                            <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">{{ $e('Состав заказа') }}</p>
                                            <table class="w-full text-sm">
                                                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                                    <tr v-for="item in order.items" :key="item.id">
                                                        <td class="py-2 text-slate-700 dark:text-slate-300">{{ item.name }}<span v-if="item.color" class="text-slate-400"> · {{ item.color }}</span></td>
                                                        <td class="py-2 text-right text-slate-500 dark:text-slate-400">{{ Number(item.quantity) }} {{ item.unit }}</td>
                                                        <td class="whitespace-nowrap py-2 text-right font-medium text-slate-800 dark:text-slate-200">{{ money(item.sum) }}</td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </div>
                                        <div class="space-y-1 text-sm text-slate-600 dark:text-slate-300">
                                            <p><span class="text-slate-400">{{ $e('Получение:') }}</span> {{ order.delivery === 'pickup' ? $e('самовывоз') : $e('доставка') }}</p>
                                            <p v-if="order.address"><span class="text-slate-400">{{ $e('Адрес:') }}</span> {{ order.address }}</p>
                                            <p v-if="order.email"><span class="text-slate-400">Email:</span> {{ order.email }}</p>
                                            <p v-if="order.comment" class="rounded-lg bg-white p-3 text-slate-700 ring-1 ring-slate-100 dark:bg-slate-900/70 dark:text-slate-300 dark:ring-slate-800">{{ order.comment }}</p>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        </template>
                        <tr v-if="!orders.data.length"><td colspan="7" class="px-6 py-12 text-center text-slate-400">{{ $e('Заказов пока нет') }}</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div v-if="orders.last_page > 1" class="mt-4 flex justify-center">
            <Pagination :links="orders.links" />
        </div>
    </AppLayout>
</template>
