<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Avatar from '@/Components/Avatar.vue';
import { useE } from '@/composables/useTranslations';

const tr = useE();

const props = defineProps({
    person: Object,
    deals: { type: Array, default: () => [] },
    projects: { type: Array, default: () => [] },
    tasks: { type: Array, default: () => [] },
    payrollRow: { type: Object, default: null },
    adjustments: { type: Array, default: () => [] },
    debt: { type: Object, default: null },
    month: { type: String, default: '' },
    can: { type: Object, default: () => ({ manage: false }) },
    // Личные доступы: приходят только админу и только для не-админа.
    access: { type: Object, default: null },
    // Только что выданный код входа: сервер отдаёт его один раз через flash.
    issuedCode: { type: String, default: null },
});

// ---- код входа: второй шаг для ключевых сотрудников, выдаёт админ ----
const issueCode = () => router.post(route('users.accessCode.issue', props.person.id), {}, { preserveScroll: true });
const revokeCode = () => {
    if (confirm(tr('Отозвать код входа? Сотрудник будет входить только по паролю.'))) {
        router.delete(route('users.accessCode.revoke', props.person.id), { preserveScroll: true });
    }
};

/*
 * Личные доступы — ДОБАВКА к роли, а не замена.
 *
 * Права роли показаны серыми и не снимаются: сняли бы здесь — и «почему у
 * него нет того, что есть у всех менеджеров» пришлось бы искать в двух
 * местах сразу. Забрать право у роли можно только в Настройки → Доступы.
 */
const personal = ref(new Set(props.access?.personal ?? []));
const fromRole = computed(() => new Set(props.access?.fromRole ?? []));
const accessBusy = ref(false);

const grantedByRole = (permission) => fromRole.value.has(permission);
const grantedPersonally = (permission) => personal.value.has(permission);
const togglePersonal = (permission) => {
    if (grantedByRole(permission)) return;   // право роли отсюда не трогаем
    const set = new Set(personal.value);
    set.has(permission) ? set.delete(permission) : set.add(permission);
    personal.value = set;
};
const accessDirty = computed(() => {
    const was = [...(props.access?.personal ?? [])].sort().join('|');
    return [...personal.value].sort().join('|') !== was;
});
const saveAccess = () => {
    accessBusy.value = true;
    router.put(route('access.updateUser', props.person.id), { permissions: [...personal.value] },
        { preserveScroll: true, onFinish: () => (accessBusy.value = false) });
};

// Месяц блока «Зарплата» переключает и корректировки, и долг: профиль
// должен сходиться с ведомостью ЗП за тот же месяц.
const setMonth = (value) => router.get(route('users.show', props.person.id), { month: value || undefined },
    { preserveState: true, preserveScroll: true, replace: true });

// Подписи ролей приходят из БД одним общим списком (HandleInertiaRequests):
// зашитый в шаблоне словарь не знал ни «Бригадира», ни ролей, созданных
// владельцем через Настройки → Права доступа, и показывал голый код.
const roleLabels = computed(() => usePage().props.roleLabels ?? {});
const roleTitle = (code) => roleLabels.value[code] ?? code ?? '';
const adjLabels = { absence: tr('Отгул'), sick: tr('Больничный'), fine: tr('Штраф'), advance: tr('Аванс'), bonus: tr('Премия') };
const taskStatusLabels = { todo: tr('К выполнению'), in_progress: tr('В работе'), done: tr('Готово') };

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
                <Link :href="route('users.index')" class="text-slate-400 hover:text-slate-600">{{ $e('← Сотрудники') }}</Link>
                <span class="text-slate-300 dark:text-slate-600">/</span>
                <span>{{ person.name }}</span>
            </div>
        </template>

        <!-- Шапка профиля -->
        <div class="mb-5 rounded-xl border border-slate-100 dark:border-slate-800 bg-white dark:bg-slate-900/70 p-5 shadow-sm">
            <div class="flex flex-wrap items-start gap-4">
                <Avatar :name="person.name" :src="person.avatar" :size="72" />
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ person.name }}</h1>
                        <span v-for="dep in person.head_of" :key="dep" class="rounded-full bg-amber-50 dark:bg-amber-500/10 px-2 py-0.5 text-xs font-semibold text-amber-700 dark:text-amber-400 ring-1 ring-amber-200 dark:ring-amber-500/30">{{ $e('⭐ Руководитель —') }} {{ dep }}</span>
                        <span v-if="!person.is_active" class="rounded bg-slate-100 dark:bg-slate-800/60 px-2 py-0.5 text-xs font-semibold text-slate-500 dark:text-slate-400">{{ $e('Отключён') }}</span>
                    </div>
                    <p class="mt-0.5 text-sm text-slate-500 dark:text-slate-400">
                        {{ roleTitle(person.role) || '—' }}
                        <template v-if="person.department"> · {{ person.department }}</template>
                        <template v-if="person.companies?.length"> · {{ person.companies.join(', ') }}</template>
                    </p>
                    <div class="mt-2 flex flex-wrap gap-x-5 gap-y-1 text-sm text-slate-600 dark:text-slate-300">
                        <a :href="`mailto:${person.email}`" class="hover:text-indigo-600">✉️ {{ person.email }}</a>
                        <a v-if="person.phone" :href="`tel:${person.phone}`" class="hover:text-indigo-600">📞 {{ person.phone }}</a>
                        <span v-if="person.birth_date">🎂 {{ fmtDate(person.birth_date) }}</span>
                        <span v-if="person.hired_at">{{ $e('🗓 в компании с') }} {{ fmtDate(person.hired_at) }} ({{ tenure }})</span>
                        <a v-if="person.has_contract" :href="route('users.contract', person.id)" class="text-indigo-600 dark:text-indigo-400 hover:underline">{{ $e('📄 Договор') }}</a>
                    </div>

                    <!-- Код входа: ключевой сотрудник входит в два шага -->
                    <div v-if="can.issue_code || person.code_required" class="mt-3 flex flex-wrap items-center gap-2 rounded-xl bg-slate-50 dark:bg-slate-800/50 px-3 py-2 text-sm ring-1 ring-slate-100 dark:ring-slate-800">
                        <template v-if="person.code_required">
                            <span class="font-medium text-emerald-700 dark:text-emerald-400">🔐 {{ $e('Вход в два шага: пароль и персональный код.') }}</span>
                            <span v-if="person.code_issued_at" class="text-xs text-slate-400">{{ $e('выдан') }} {{ fmtDate(person.code_issued_at) }}</span>
                        </template>
                        <span v-else class="text-slate-500 dark:text-slate-400">{{ $e('Код не требуется — вход только по паролю.') }}</span>
                        <template v-if="can.issue_code">
                            <button type="button" @click="issueCode" class="ml-auto rounded-lg bg-slate-900 px-3 py-1 text-xs font-semibold text-white dark:bg-slate-100 dark:text-slate-900 transition hover:bg-slate-700">
                                {{ person.code_required ? $e('Перевыпустить') : $e('Выдать код') }}
                            </button>
                            <button v-if="person.code_required" type="button" @click="revokeCode" class="rounded-lg px-3 py-1 text-xs font-semibold text-rose-600 dark:text-rose-400 ring-1 ring-rose-200 dark:ring-rose-500/30 transition hover:bg-rose-50">
                                {{ $e('Отозвать') }}
                            </button>
                        </template>
                    </div>
                    <div v-if="issuedCode" class="mt-3 rounded-xl border border-emerald-200 bg-emerald-50 dark:bg-emerald-500/10 px-4 py-3">
                        <p class="text-xs font-medium text-emerald-700 dark:text-emerald-400">{{ $e('Код показывается один раз — передайте его сотруднику лично.') }}</p>
                        <p class="mt-1 font-mono text-3xl font-bold tracking-[0.35em] text-emerald-800">{{ issuedCode }}</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Показатели -->
        <div class="mb-5 grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div class="rounded-xl border border-slate-100 dark:border-slate-800 bg-white dark:bg-slate-900/70 px-4 py-3 shadow-sm">
                <p class="text-2xl font-bold text-slate-900 dark:text-slate-100">{{ stats.deals }}</p>
                <p class="text-xs text-slate-500 dark:text-slate-400">{{ $e('Сделок') }}</p>
            </div>
            <div class="rounded-xl border border-slate-100 dark:border-slate-800 bg-white dark:bg-slate-900/70 px-4 py-3 shadow-sm">
                <p class="text-2xl font-bold text-emerald-600 dark:text-emerald-400">{{ stats.won }}</p>
                <p class="text-xs text-slate-500 dark:text-slate-400">{{ $e('Успешных') }}</p>
            </div>
            <div class="rounded-xl border border-slate-100 dark:border-slate-800 bg-white dark:bg-slate-900/70 px-4 py-3 shadow-sm">
                <p class="text-2xl font-bold text-indigo-600 dark:text-indigo-400">{{ stats.projects }}</p>
                <p class="text-xs text-slate-500 dark:text-slate-400">{{ $e('Заказов в цехе') }}</p>
            </div>
            <div class="rounded-xl border border-slate-100 dark:border-slate-800 bg-white dark:bg-slate-900/70 px-4 py-3 shadow-sm">
                <p class="text-2xl font-bold" :class="tasks.some((t) => t.overdue) ? 'text-red-500' : 'text-slate-900 dark:text-slate-100'">{{ stats.tasks }}</p>
                <p class="text-xs text-slate-500 dark:text-slate-400">{{ $e('Открытых задач') }}</p>
            </div>
        </div>

        <!-- ЗП (только руководство и сам сотрудник) -->
        <div v-if="payrollRow" class="mb-5 rounded-xl border border-slate-100 dark:border-slate-800 bg-white dark:bg-slate-900/70 p-4 shadow-sm">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h3 class="text-xs font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">{{ $e('Зарплата (текущий расчёт)') }}</h3>
                <input :value="month" @change="setMonth($event.target.value)" type="month"
                    class="rounded-lg border-slate-300 py-1 text-xs shadow-sm transition focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20" />
            </div>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div><p class="text-lg font-bold text-slate-900 dark:text-slate-100">{{ fmt(payrollRow.salary) }}</p><p class="text-xs text-slate-500 dark:text-slate-400">{{ $e('Оклад') }}</p></div>
                <div><p class="text-lg font-bold text-emerald-600 dark:text-emerald-400">{{ fmt(payrollRow.bonus) }}</p><p class="text-xs text-slate-500 dark:text-slate-400">{{ $e('Бонус от маржи') }}</p></div>
                <div><p class="text-lg font-bold text-indigo-600 dark:text-indigo-400">{{ fmt(payrollRow.payout) }}</p><p class="text-xs text-slate-500 dark:text-slate-400">{{ $e('К выплате (без корректировок)') }}</p></div>
                <div><p class="text-lg font-bold text-slate-900 dark:text-slate-100">{{ payrollRow.closed }}</p><p class="text-xs text-slate-500 dark:text-slate-400">{{ $e('Закрытых сделок') }}</p></div>
            </div>
            <div v-if="adjustments.length" class="mt-4 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="text-left text-xs uppercase text-slate-400">
                        <tr><th class="py-1 pr-4">{{ $e('Корректировка') }}</th><th class="py-1 pr-4">{{ $e('Дата') }}</th><th class="py-1 pr-4 text-right">{{ $e('Сумма') }}</th><th class="py-1">{{ $e('Заметка') }}</th></tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <tr v-for="a in adjustments" :key="a.id">
                            <td class="py-1.5 pr-4">
                                <span :class="a.type === 'bonus' ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-500'">{{ adjLabels[a.type] ?? a.type }}</span>
                                <span v-if="a.days" class="text-slate-400"> ({{ a.days }} {{ $e('дн.)') }}</span>
                            </td>
                            <td class="py-1.5 pr-4 text-slate-500 dark:text-slate-400">{{ fmtDate(a.date) }}</td>
                            <td class="py-1.5 pr-4 text-right font-medium" :class="a.type === 'bonus' ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-500'">
                                {{ a.type === 'bonus' ? '+' : '−' }}{{ fmt(a.amount) }}
                            </td>
                            <td class="py-1.5 text-slate-500 dark:text-slate-400">{{ a.note ?? '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Долг: остаток, план удержания месяца и история погашений -->
            <div v-if="debt && debt.items.length" class="mt-4 rounded-lg border border-rose-100 bg-rose-50/40 dark:bg-rose-500/10 p-3">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <span class="text-xs font-bold uppercase tracking-wider text-rose-600 dark:text-rose-400">{{ $e('Долг') }}</span>
                    <span class="text-sm text-slate-600 dark:text-slate-300">
                        {{ $e('Остаток') }} <b class="tabular-nums">{{ fmt(debt.balance) }}</b>
                        <template v-if="debt.charge > 0"> · {{ $e('удержим') }} <b class="tabular-nums text-rose-600 dark:text-rose-400">− {{ fmt(debt.charge) }}</b></template>
                        <template v-else> · {{ $e('в этом месяце удержания нет: бонуса не хватает') }}</template>
                    </span>
                </div>
                <table v-if="debt.payments.length" class="mt-2 min-w-full text-sm">
                    <thead class="text-left text-xs uppercase text-slate-400">
                        <tr><th class="py-1 pr-4">{{ $e('Месяц') }}</th><th class="py-1 pr-4 text-right">{{ $e('Погашено') }}</th></tr>
                    </thead>
                    <tbody class="divide-y divide-rose-100">
                        <tr v-for="p in debt.payments" :key="p.id">
                            <td class="py-1.5 pr-4 text-slate-500 dark:text-slate-400">{{ p.month }}</td>
                            <td class="py-1.5 pr-4 text-right font-medium tabular-nums text-emerald-600 dark:text-emerald-400">{{ fmt(p.amount) }}</td>
                        </tr>
                    </tbody>
                </table>
                <p v-else class="mt-1 text-xs text-slate-400">{{ $e('Погашений ещё не было.') }}</p>
            </div>
        </div>

        <div class="grid gap-5 lg:grid-cols-2">
            <!-- Сделки -->
            <div class="rounded-xl border border-slate-100 dark:border-slate-800 bg-white dark:bg-slate-900/70 p-4 shadow-sm">
                <h3 class="mb-3 text-xs font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">{{ $e('Сделки (') }}{{ deals.length }})</h3>
                <div class="divide-y divide-slate-100 dark:divide-slate-800">
                    <Link v-for="d in deals" :key="d.id" :href="route('deals.show', d.id)"
                        class="flex items-center justify-between gap-3 py-2 hover:bg-slate-50 dark:hover:bg-slate-800/60">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium text-slate-900 dark:text-slate-100">{{ d.number }} · {{ d.company_name }}</p>
                            <p class="text-xs text-slate-500 dark:text-slate-400">
                                <span :class="d.is_won ? 'text-emerald-600 dark:text-emerald-400 font-semibold' : ''">{{ d.stage ?? '—' }}</span>
                                <template v-if="d.deadline"> {{ $e('· срок') }} {{ fmtDate(d.deadline) }}</template>
                            </p>
                        </div>
                        <span v-if="d.budget !== null" class="shrink-0 text-sm font-semibold text-slate-700 dark:text-slate-300">{{ fmt(d.budget) }}</span>
                    </Link>
                    <p v-if="!deals.length" class="py-6 text-center text-sm text-slate-400">{{ $e('Нет сделок') }}</p>
                </div>
            </div>

            <!-- Заказы цеха -->
            <div class="rounded-xl border border-slate-100 dark:border-slate-800 bg-white dark:bg-slate-900/70 p-4 shadow-sm">
                <h3 class="mb-3 text-xs font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">{{ $e('Заказы цеха (') }}{{ projects.length }})</h3>
                <div class="divide-y divide-slate-100 dark:divide-slate-800">
                    <Link v-for="p in projects" :key="p.id" :href="route('projects.show', p.id)"
                        class="block py-2 hover:bg-slate-50 dark:hover:bg-slate-800/60">
                        <p class="truncate text-sm font-medium text-slate-900 dark:text-slate-100">{{ p.number }} · {{ p.name }}</p>
                        <p class="text-xs text-slate-500 dark:text-slate-400">
                            <template v-if="p.workshop">{{ p.workshop }} · </template>{{ p.stage ?? '—' }}
                            <span v-if="p.status === 'completed'" class="font-semibold text-emerald-600 dark:text-emerald-400"> {{ $e('· готов') }}</span>
                            <span v-else-if="p.status === 'cancelled'" class="text-slate-400"> {{ $e('· отменён') }}</span>
                            <template v-if="p.deadline"> {{ $e('· срок') }} {{ fmtDate(p.deadline) }}</template>
                        </p>
                    </Link>
                    <p v-if="!projects.length" class="py-6 text-center text-sm text-slate-400">{{ $e('Нет заказов') }}</p>
                </div>
            </div>
        </div>

        <!-- Задачи -->
        <div class="mt-5 rounded-xl border border-slate-100 dark:border-slate-800 bg-white dark:bg-slate-900/70 p-4 shadow-sm">
            <h3 class="mb-3 text-xs font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">{{ $e('Задачи (') }}{{ tasks.length }})</h3>
            <div class="divide-y divide-slate-100 dark:divide-slate-800">
                <div v-for="t in tasks" :key="t.id" class="flex items-center justify-between gap-3 py-2">
                    <p class="min-w-0 truncate text-sm" :class="t.status === 'done' ? 'text-slate-400 line-through' : 'text-slate-900 dark:text-slate-100'">{{ t.title }}</p>
                    <div class="flex shrink-0 items-center gap-3 text-xs">
                        <span v-if="t.due_date" :class="t.overdue ? 'font-semibold text-red-500' : 'text-slate-400'">{{ t.overdue ? '⚠ ' : '' }}{{ fmtDate(t.due_date) }}</span>
                        <span class="rounded-full px-2 py-0.5 font-semibold"
                            :class="t.status === 'done' ? 'bg-emerald-50 dark:bg-emerald-500/10 text-emerald-600 dark:text-emerald-400' : t.overdue ? 'bg-red-50 dark:bg-red-500/10 text-red-500' : 'bg-slate-100 dark:bg-slate-800/60 text-slate-500 dark:text-slate-400'">
                            {{ taskStatusLabels[t.status] ?? t.status }}
                        </span>
                    </div>
                </div>
                <p v-if="!tasks.length" class="py-6 text-center text-sm text-slate-400">{{ $e('Нет задач') }}</p>
            </div>
        </div>

        <!-- Личные доступы сверх роли (только админу) -->
        <div v-if="access" class="mt-6 rounded-2xl border border-slate-100 dark:border-slate-800 bg-white dark:bg-slate-900/70 p-6 shadow-soft">
            <div class="flex flex-wrap items-baseline justify-between gap-3">
                <div>
                    <h3 class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ $e('Доступы') }}</h3>
                    <p class="mt-0.5 text-xs text-slate-400">
                        {{ $e('Роль') }} «{{ access.roleLabel }}» {{ $e('даёт серые галочки — они правятся в') }}
                        <Link :href="route('access.index')" class="font-semibold text-indigo-600 dark:text-indigo-400 hover:underline">{{ $e('Настройки → Доступы') }}</Link>.
                        {{ $e('Здесь — только личная добавка этому человеку.') }}
                    </p>
                </div>
                <button v-if="accessDirty" :disabled="accessBusy" @click="saveAccess"
                    class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition-colors duration-150 hover:bg-indigo-700 disabled:opacity-50">
                    {{ accessBusy ? '…' : $e('Сохранить доступы') }}
                </button>
            </div>

            <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <div v-for="m in access.modules" :key="m.key" class="rounded-xl border border-slate-100 dark:border-slate-800 bg-slate-50/40 dark:bg-slate-800/50 p-3">
                    <div class="text-sm font-semibold text-slate-800 dark:text-slate-200">{{ $e(m.label) }}</div>
                    <div class="mt-2 flex flex-wrap gap-1.5">
                        <button v-for="(permission, key) in m.permissions" :key="permission" type="button"
                            :disabled="grantedByRole(permission)"
                            :title="grantedByRole(permission) ? $e('Даёт роль — снимается в «Настройки → Доступы»') : ''"
                            @click="togglePersonal(permission)"
                            class="rounded-full px-2.5 py-1 text-xs font-semibold transition-colors duration-150"
                            :class="grantedByRole(permission) ? 'cursor-not-allowed bg-slate-100 dark:bg-slate-800/60 text-slate-400'
                                : grantedPersonally(permission) ? 'bg-indigo-600 text-white hover:bg-indigo-700'
                                : 'bg-white dark:bg-slate-900/70 text-slate-500 dark:text-slate-400 ring-1 ring-slate-200 dark:ring-slate-800 hover:ring-indigo-300'">
                            {{ $e(access.abilities[key] ?? key) }}
                        </button>
                    </div>
                </div>
            </div>

            <p class="mt-4 text-xs text-slate-400">
                {{ $e('Серое — от роли, синее — личная добавка. Правила про деньги остаются в силе: доступ открывает раздел, но не отменяет проверок в политиках.') }}
            </p>
        </div>
    </AppLayout>
</template>
