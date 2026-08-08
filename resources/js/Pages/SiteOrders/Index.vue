<script setup>
import { ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { confirmDialog } from '@/composables/useConfirm';

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
    new: 'bg-emerald-100 text-emerald-700',
    in_work: 'bg-indigo-100 text-indigo-700',
    done: 'bg-slate-100 text-slate-600',
    cancelled: 'bg-rose-100 text-rose-700',
}[s] ?? 'bg-slate-100 text-slate-600');

const setStatus = (order, value) => router.patch(route('siteOrders.update', order.id), { status: value }, { preserveScroll: true });

const toDeal = async (order) => {
    if (!(await confirmDialog({
        title: `Создать сделку из заказа ${order.number}?`,
        message: 'Сделка появится на первом этапе воронки, состав заказа уйдёт в описание.',
        confirmText: 'Создать сделку',
    }))) return;
    router.post(route('siteOrders.convert', order.id), {}, { preserveScroll: true });
};

const remove = async (order) => {
    if (!(await confirmDialog({ title: `Удалить заказ ${order.number}?`, confirmText: 'Удалить', danger: true }))) return;
    router.delete(route('siteOrders.destroy', order.id), { preserveScroll: true });
};
</script>

<template>
    <AppLayout>
        <template #header>Заказы с сайта</template>

        <div class="mb-4 grid gap-3 sm:grid-cols-3">
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-xs uppercase tracking-wide text-slate-400">Новых</p>
                <p class="mt-1 text-2xl font-bold text-emerald-600">{{ stats.new }}</p>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-xs uppercase tracking-wide text-slate-400">Заказов за месяц</p>
                <p class="mt-1 text-2xl font-bold text-slate-900">{{ stats.month }}</p>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-xs uppercase tracking-wide text-slate-400">Сумма за месяц</p>
                <p class="mt-1 text-2xl font-bold text-slate-900">{{ money(stats.monthSum) }}</p>
            </div>
        </div>

        <div class="mb-4 flex flex-wrap items-center gap-2">
            <input v-model="search" type="search" placeholder="Номер, имя или телефон…" class="w-64 rounded-lg border-slate-200 py-2 text-sm shadow-sm" />
            <select v-model="status" class="rounded-lg border-slate-200 py-2 text-sm text-slate-600 shadow-sm">
                <option value="">Все статусы</option>
                <option v-for="(label, key) in statuses" :key="key" :value="key">{{ label }}</option>
            </select>
            <span class="ml-auto text-xs text-slate-400">Всего: {{ orders.total }}</span>
        </div>

        <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-100 text-sm">
                    <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-400">
                        <tr>
                            <th class="px-4 py-2.5">Заказ</th>
                            <th class="px-4 py-2.5">Клиент</th>
                            <th class="px-4 py-2.5">Город</th>
                            <th class="px-4 py-2.5 text-right">Сумма</th>
                            <th class="px-4 py-2.5">Статус</th>
                            <th class="px-4 py-2.5">Сделка</th>
                            <th class="px-4 py-2.5"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        <template v-for="order in orders.data" :key="order.id">
                            <tr class="hover:bg-slate-50">
                                <td class="px-4 py-3">
                                    <button class="font-medium text-indigo-600 hover:underline" @click="expanded = expanded === order.id ? null : order.id">{{ order.number }}</button>
                                    <span class="block text-[10px] text-slate-400">{{ new Date(order.created_at).toLocaleString('ru-RU') }}</span>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="block text-slate-800">{{ order.name }}</span>
                                    <a :href="`tel:${order.phone}`" class="text-xs text-indigo-600 hover:underline">{{ order.phone }}</a>
                                </td>
                                <td class="px-4 py-3 text-slate-500">{{ order.city ?? '—' }}</td>
                                <td class="px-4 py-3 text-right font-semibold tabular-nums text-slate-900">{{ money(order.total) }}</td>
                                <td class="px-4 py-3">
                                    <select :value="order.status" class="rounded-full border-0 px-2.5 py-1 text-xs font-semibold focus:ring-0" :class="statusClass(order.status)" @change="setStatus(order, $event.target.value)">
                                        <option v-for="(label, key) in statuses" :key="key" :value="key">{{ label }}</option>
                                    </select>
                                </td>
                                <td class="px-4 py-3">
                                    <Link v-if="order.deal" :href="route('deals.show', order.deal.id)" class="text-xs font-semibold text-indigo-600 hover:underline">{{ order.deal.number }}</Link>
                                    <span v-else class="text-xs text-slate-300">—</span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-right">
                                    <button v-if="!order.deal" class="rounded-lg bg-emerald-600 px-2.5 py-1.5 text-xs font-semibold text-white transition hover:bg-emerald-700" @click="toDeal(order)">В сделку</button>
                                    <button class="ml-1 rounded p-1 text-slate-300 transition hover:text-rose-600" title="Удалить" @click="remove(order)">✕</button>
                                </td>
                            </tr>
                            <tr v-if="expanded === order.id" class="bg-slate-50/60">
                                <td colspan="7" class="px-6 py-4">
                                    <div class="grid gap-6 md:grid-cols-[1fr_260px]">
                                        <div>
                                            <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">Состав заказа</p>
                                            <table class="w-full text-sm">
                                                <tbody class="divide-y divide-slate-100">
                                                    <tr v-for="item in order.items" :key="item.id">
                                                        <td class="py-2 text-slate-700">{{ item.name }}<span v-if="item.color" class="text-slate-400"> · {{ item.color }}</span></td>
                                                        <td class="py-2 text-right text-slate-500">{{ Number(item.quantity) }} {{ item.unit }}</td>
                                                        <td class="py-2 text-right font-medium text-slate-800">{{ money(item.sum) }}</td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </div>
                                        <div class="space-y-1 text-sm text-slate-600">
                                            <p><span class="text-slate-400">Получение:</span> {{ order.delivery === 'pickup' ? 'самовывоз' : 'доставка' }}</p>
                                            <p v-if="order.address"><span class="text-slate-400">Адрес:</span> {{ order.address }}</p>
                                            <p v-if="order.email"><span class="text-slate-400">Email:</span> {{ order.email }}</p>
                                            <p v-if="order.comment" class="rounded-lg bg-white p-3 text-slate-700 ring-1 ring-slate-100">{{ order.comment }}</p>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        </template>
                        <tr v-if="!orders.data.length"><td colspan="7" class="px-6 py-12 text-center text-slate-400">Заказов пока нет</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div v-if="orders.last_page > 1" class="mt-4 flex flex-wrap justify-center gap-1.5">
            <Link v-for="link in orders.links" :key="link.label" :href="link.url ?? ''" preserve-scroll
                class="min-w-9 rounded-lg border px-2.5 py-1.5 text-center text-sm"
                :class="[link.active ? 'border-indigo-500 bg-indigo-500 text-white' : 'border-slate-200 text-slate-600 hover:bg-slate-50', !link.url && 'pointer-events-none opacity-40']"
                v-html="link.label" />
        </div>
    </AppLayout>
</template>
