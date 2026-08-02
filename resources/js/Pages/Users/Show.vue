<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Avatar from '@/Components/Avatar.vue';

const props = defineProps({
    person: Object,
    deals: { type: Array, default: () => [] },
    projects: { type: Array, default: () => [] },
    tasks: { type: Array, default: () => [] },
    payrollRow: { type: Object, default: null },
    adjustments: { type: Array, default: () => [] },
    can: { type: Object, default: () => ({ manage: false }) },
});

const roleLabels = { admin: 'СЕО (админ)', director: 'Директор', financist: 'Финансист-Бухгалтер', manager: 'Менеджер', employee: 'Сотрудник (цех)', lawyer: 'Юрист', cook: 'Повар', designer: 'Дизайнер', supplier: 'Снабженец' };
const adjLabels = { absence: 'Отгул', sick: 'Больничный', fine: 'Штраф', advance: 'Аванс', bonus: 'Премия' };
const taskStatusLabels = { todo: 'К выполнению', in_progress: 'В работе', done: 'Готово' };

const fmt = (v) => (v === null || v === undefined) ? '—' : Number(v).toLocaleString('ru-RU', { maximumFractionDigits: 0 }) + ' ₸';
const fmtDate = (d) => d ? new Date(d).toLocaleDateString('ru-RU') : '—';

const tenure = computed(() => {
    if (!props.person.hired_at) return null;
    const from = new Date(props.person.hired_at);
    const now = new Date();
    let months = (now.getFullYear() - from.getFullYear()) * 12 + now.getMonth() - from.getMonth();
    if (now.getDate() < from.getDate()) months--;
    months = Math.max(0, months);
    const y = Math.floor(months / 12);
    const m = months % 12;
    const parts = [];
    if (y) parts.push(`${y} г.`);
    if (m || !y) parts.push(`${m} мес.`);
    return parts.join(' ');
});

const stats = computed(() => ({
    deals: props.deals.length,
    won: props.deals.filter((d) => d.is_won).length,
    projects: props.projects.filter((p) => !['completed', 'cancelled'].includes(p.status)).length,
    tasks: props.tasks.filter((t) => t.status !== 'done').length,
}));
</script>

<template>
    <Head :title="person.name" />
    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2">
                <Link :href="route('users.index')" class="text-slate-400 hover:text-slate-600">← Сотрудники</Link>
                <span class="text-slate-300">/</span>
                <span>{{ person.name }}</span>
            </div>
        </template>

        <!-- Шапка профиля -->
        <div class="mb-5 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="flex flex-wrap items-start gap-4">
                <Avatar :name="person.name" :src="person.avatar" :size="72" />
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <h1 class="text-xl font-bold text-slate-900">{{ person.name }}</h1>
                        <span v-for="dep in person.head_of" :key="dep" class="rounded-full bg-amber-50 px-2 py-0.5 text-xs font-semibold text-amber-700 ring-1 ring-amber-200">⭐ Руководитель — {{ dep }}</span>
                        <span v-if="!person.is_active" class="rounded bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-500">Отключён</span>
                    </div>
                    <p class="mt-0.5 text-sm text-slate-500">
                        {{ roleLabels[person.role] ?? person.role ?? '—' }}
                        <template v-if="person.department"> · {{ person.department }}</template>
                        <template v-if="person.companies?.length"> · {{ person.companies.join(', ') }}</template>
                    </p>
                    <div class="mt-2 flex flex-wrap gap-x-5 gap-y-1 text-sm text-slate-600">
                        <a :href="`mailto:${person.email}`" class="hover:text-indigo-600">✉️ {{ person.email }}</a>
                        <a v-if="person.phone" :href="`tel:${person.phone}`" class="hover:text-indigo-600">📞 {{ person.phone }}</a>
                        <span v-if="person.birth_date">🎂 {{ fmtDate(person.birth_date) }}</span>
                        <span v-if="person.hired_at">🗓 в компании с {{ fmtDate(person.hired_at) }} ({{ tenure }})</span>
                        <a v-if="person.has_contract" :href="route('users.contract', person.id)" class="text-indigo-600 hover:underline">📄 Договор</a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Показатели -->
        <div class="mb-5 grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div class="rounded-xl border border-slate-200 bg-white px-4 py-3 shadow-sm">
                <p class="text-2xl font-bold text-slate-900">{{ stats.deals }}</p>
                <p class="text-xs text-slate-500">Сделок</p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white px-4 py-3 shadow-sm">
                <p class="text-2xl font-bold text-emerald-600">{{ stats.won }}</p>
                <p class="text-xs text-slate-500">Успешных</p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white px-4 py-3 shadow-sm">
                <p class="text-2xl font-bold text-indigo-600">{{ stats.projects }}</p>
                <p class="text-xs text-slate-500">Заказов в цехе</p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white px-4 py-3 shadow-sm">
                <p class="text-2xl font-bold" :class="tasks.some((t) => t.overdue) ? 'text-red-500' : 'text-slate-900'">{{ stats.tasks }}</p>
                <p class="text-xs text-slate-500">Открытых задач</p>
            </div>
        </div>

        <!-- ЗП (только руководство и сам сотрудник) -->
        <div v-if="payrollRow" class="mb-5 rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <h3 class="mb-3 text-xs font-bold uppercase tracking-wider text-slate-500">Зарплата (текущий расчёт)</h3>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div><p class="text-lg font-bold text-slate-900">{{ fmt(payrollRow.salary) }}</p><p class="text-xs text-slate-500">Оклад</p></div>
                <div><p class="text-lg font-bold text-emerald-600">{{ fmt(payrollRow.bonus) }}</p><p class="text-xs text-slate-500">Бонус от маржи</p></div>
                <div><p class="text-lg font-bold text-indigo-600">{{ fmt(payrollRow.payout) }}</p><p class="text-xs text-slate-500">К выплате (без корректировок)</p></div>
                <div><p class="text-lg font-bold text-slate-900">{{ payrollRow.closed }}</p><p class="text-xs text-slate-500">Закрытых сделок</p></div>
            </div>
            <div v-if="adjustments.length" class="mt-4 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="text-left text-xs uppercase text-slate-400">
                        <tr><th class="py-1 pr-4">Корректировка</th><th class="py-1 pr-4">Дата</th><th class="py-1 pr-4 text-right">Сумма</th><th class="py-1">Заметка</th></tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="a in adjustments" :key="a.id">
                            <td class="py-1.5 pr-4">
                                <span :class="a.type === 'bonus' ? 'text-emerald-600' : 'text-red-500'">{{ adjLabels[a.type] ?? a.type }}</span>
                                <span v-if="a.days" class="text-slate-400"> ({{ a.days }} дн.)</span>
                            </td>
                            <td class="py-1.5 pr-4 text-slate-500">{{ fmtDate(a.date) }}</td>
                            <td class="py-1.5 pr-4 text-right font-medium" :class="a.type === 'bonus' ? 'text-emerald-600' : 'text-red-500'">
                                {{ a.type === 'bonus' ? '+' : '−' }}{{ fmt(a.amount) }}
                            </td>
                            <td class="py-1.5 text-slate-500">{{ a.note ?? '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="grid gap-5 lg:grid-cols-2">
            <!-- Сделки -->
            <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                <h3 class="mb-3 text-xs font-bold uppercase tracking-wider text-slate-500">Сделки ({{ deals.length }})</h3>
                <div class="divide-y divide-slate-100">
                    <Link v-for="d in deals" :key="d.id" :href="route('deals.show', d.id)"
                        class="flex items-center justify-between gap-3 py-2 hover:bg-slate-50">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium text-slate-900">{{ d.number }} · {{ d.company_name }}</p>
                            <p class="text-xs text-slate-500">
                                <span :class="d.is_won ? 'text-emerald-600 font-semibold' : ''">{{ d.stage ?? '—' }}</span>
                                <template v-if="d.deadline"> · срок {{ fmtDate(d.deadline) }}</template>
                            </p>
                        </div>
                        <span v-if="d.budget !== null" class="shrink-0 text-sm font-semibold text-slate-700">{{ fmt(d.budget) }}</span>
                    </Link>
                    <p v-if="!deals.length" class="py-6 text-center text-sm text-slate-400">Нет сделок</p>
                </div>
            </div>

            <!-- Заказы цеха -->
            <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                <h3 class="mb-3 text-xs font-bold uppercase tracking-wider text-slate-500">Заказы цеха ({{ projects.length }})</h3>
                <div class="divide-y divide-slate-100">
                    <Link v-for="p in projects" :key="p.id" :href="route('projects.show', p.id)"
                        class="block py-2 hover:bg-slate-50">
                        <p class="truncate text-sm font-medium text-slate-900">{{ p.number }} · {{ p.name }}</p>
                        <p class="text-xs text-slate-500">
                            <template v-if="p.workshop">{{ p.workshop }} · </template>{{ p.stage ?? '—' }}
                            <span v-if="p.status === 'completed'" class="font-semibold text-emerald-600"> · готов</span>
                            <span v-else-if="p.status === 'cancelled'" class="text-slate-400"> · отменён</span>
                            <template v-if="p.deadline"> · срок {{ fmtDate(p.deadline) }}</template>
                        </p>
                    </Link>
                    <p v-if="!projects.length" class="py-6 text-center text-sm text-slate-400">Нет заказов</p>
                </div>
            </div>
        </div>

        <!-- Задачи -->
        <div class="mt-5 rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <h3 class="mb-3 text-xs font-bold uppercase tracking-wider text-slate-500">Задачи ({{ tasks.length }})</h3>
            <div class="divide-y divide-slate-100">
                <div v-for="t in tasks" :key="t.id" class="flex items-center justify-between gap-3 py-2">
                    <p class="min-w-0 truncate text-sm" :class="t.status === 'done' ? 'text-slate-400 line-through' : 'text-slate-900'">{{ t.title }}</p>
                    <div class="flex shrink-0 items-center gap-3 text-xs">
                        <span v-if="t.due_date" :class="t.overdue ? 'font-semibold text-red-500' : 'text-slate-400'">{{ t.overdue ? '⚠ ' : '' }}{{ fmtDate(t.due_date) }}</span>
                        <span class="rounded-full px-2 py-0.5 font-semibold"
                            :class="t.status === 'done' ? 'bg-emerald-50 text-emerald-600' : t.overdue ? 'bg-red-50 text-red-500' : 'bg-slate-100 text-slate-500'">
                            {{ taskStatusLabels[t.status] ?? t.status }}
                        </span>
                    </div>
                </div>
                <p v-if="!tasks.length" class="py-6 text-center text-sm text-slate-400">Нет задач</p>
            </div>
        </div>
    </AppLayout>
</template>
