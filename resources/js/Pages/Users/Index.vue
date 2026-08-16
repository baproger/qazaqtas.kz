<script setup>
import { ref, computed } from 'vue';
import { Head, Link, useForm, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { confirmDialog } from '@/composables/useConfirm';
import Avatar from '@/Components/Avatar.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import { useE } from '@/composables/useTranslations';

const tr = useE();

const props = defineProps({
    users: Array,
    departments: Array,
    roles: Array,
    companies: { type: Array, default: () => [] },
    can: { type: Object, default: () => ({ manage: false }) },
    workshopOptions: { type: Array, default: () => [] },
});

const roleLabels = { admin: tr('СЕО (админ)'), director: tr('Директор'), financist: tr('Финансист-Бухгалтер'), manager: tr('Менеджер'), employee: tr('Сотрудник (цех)'), lawyer: tr('Юрист'), cook: tr('Повар'), designer: tr('Технолог'), supplier: tr('Снабженец') };
const roleColors = {
    admin: 'bg-purple-50 text-purple-700 ring-purple-200',
    director: 'bg-indigo-50 text-indigo-700 ring-indigo-200',
    financist: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    manager: 'bg-blue-50 text-blue-700 ring-blue-200',
    designer: 'bg-pink-50 text-pink-700 ring-pink-200',
    supplier: 'bg-amber-50 text-amber-700 ring-amber-200',
    lawyer: 'bg-cyan-50 text-cyan-700 ring-cyan-200',
    cook: 'bg-orange-50 text-orange-700 ring-orange-200',
    employee: 'bg-slate-100 text-slate-600 ring-slate-200',
};
const companyNames = computed(() => Object.fromEntries(props.companies.map((c) => [c.id, c.name])));
// Руководители отделов — ⭐ на карточке.
const headIds = computed(() => new Set(props.departments.map((d) => d.head_user_id).filter(Boolean)));

// 🎂: сколько дней до ближайшего дня рождения (0 = сегодня, null = не указан).
const daysToBirthday = (u) => {
    if (!u.birth_date) return null;
    const bd = new Date(u.birth_date);
    const now = new Date();
    const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    let next = new Date(now.getFullYear(), bd.getMonth(), bd.getDate());
    if (next < today) next = new Date(now.getFullYear() + 1, bd.getMonth(), bd.getDate());
    return Math.round((next - today) / 86400000);
};

// «с 12.06.2026» под именем: дата приёма. Стажем (`tenure`) пользуется
// карточка сотрудника; в таблице нужна короткая дата.
const hiredSince = (u) => {
    if (!u.hired_at) return null;
    const d = new Date(u.hired_at);

    return `с ${String(d.getDate()).padStart(2, '0')}.${String(d.getMonth() + 1).padStart(2, '0')}.${d.getFullYear()}`;
};

// Себя отключать нельзя — кнопку не показываем.
const currentUserId = computed(() => usePage().props.auth.user?.id);

// --- Фильтры (всё на клиенте — мгновенно, без запросов) ---
const search = ref('');
const deptFilter = ref('all'); // 'all' | id отдела | 0 («Без отдела»)
const showInactive = ref(false);

const inactiveCount = computed(() => props.users.filter((u) => !u.is_active).length);

const visibleUsers = computed(() => {
    const q = search.value.trim().toLowerCase();
    return props.users.filter((u) => {
        if (!showInactive.value && !u.is_active) return false;
        if (deptFilter.value !== 'all' && (u.department_id ?? 0) !== deptFilter.value) return false;
        if (!q) return true;
        return [u.name, u.email, u.phone, u.department?.name, roleLabels[u.role]]
            .some((v) => (v ?? '').toLowerCase().includes(q));
    });
});

// Чипы отделов с количеством (учитывают переключатель «отключённые», но не поиск).
const deptChips = computed(() => {
    const pool = props.users.filter((u) => showInactive.value || u.is_active);
    const counts = {};
    pool.forEach((u) => { const k = u.department_id ?? 0; counts[k] = (counts[k] ?? 0) + 1; });
    const chips = props.departments
        .map((d) => ({ id: d.id, name: d.name, count: counts[d.id] ?? 0 }))
        .filter((c) => c.count > 0);
    if (counts[0]) chips.push({ id: 0, name: tr('Без отдела'), count: counts[0] });
    return chips;
});

const stats = computed(() => ({
    total: props.users.length,
    active: props.users.length - inactiveCount.value,
    departments: new Set(props.users.filter((u) => u.is_active && u.department_id).map((u) => u.department_id)).size,
}));

// --- Модалка (создание/правка) ---
const show = ref(false);
const editing = ref(null);

const form = useForm({
    name: '', email: '', password: '', password_confirmation: '',
    department_id: '', workshops: [], phone: '', birth_date: '', hired_at: '', salary: 0, bonus_percent: '', contract: null, role: 'employee', is_active: true,
    company_ids: props.companies.map((c) => c.id),
});
const toggleWorkshop = (w) => {
    form.workshops = form.workshops.includes(w)
        ? form.workshops.filter((x) => x !== w)
        : [...form.workshops, w];
};

const openCreate = () => {
    editing.value = null; form.reset(); form.role = 'employee'; form.is_active = true;
    form.company_ids = props.companies.map((c) => c.id);
    // Если открыт фильтр по отделу — сразу подставляем его в форму.
    form.department_id = typeof deptFilter.value === 'number' && deptFilter.value !== 0 ? deptFilter.value : '';
    show.value = true;
};
const openEdit = (u) => {
    if (!props.can.manage) return;
    editing.value = u;
    Object.assign(form, {
        name: u.name, email: u.email, password: '', password_confirmation: '',
        department_id: u.department_id ?? '', workshops: [...(u.workshops ?? [])], phone: u.phone ?? '',
        birth_date: u.birth_date ?? '', hired_at: u.hired_at ?? '',
        salary: u.salary ?? 0, bonus_percent: u.bonus_percent ?? '', contract: null,
        role: u.role ?? 'employee', is_active: u.is_active,
        company_ids: [...(u.company_ids ?? [])],
    });
    show.value = true;
};
const toggleCompany = (id) => {
    form.company_ids = form.company_ids.includes(id)
        ? form.company_ids.filter((c) => c !== id)
        : [...form.company_ids, id];
};
const submit = () => {
    const opts = { preserveScroll: true, onSuccess: () => (show.value = false) };
    // Файл договора требует multipart — обновление идёт POST-ом с _method=put.
    if (editing.value) form.transform((d) => ({ ...d, _method: 'put' })).post(route('users.update', editing.value.id), opts);
    else form.post(route('users.store'), opts);
};
const deactivate = async (u) => {
    if (await confirmDialog({ title: tr('Деактивировать сотрудника'), message: `Сотрудник «${u.name}» потеряет доступ к системе.`, confirmText: tr('Деактивировать'), danger: true })) {
        router.delete(route('users.destroy', u.id), { preserveScroll: true });
    }
};
</script>

<template>
    <Head :title="$e('Сотрудники')" />
    <AppLayout>
        <template #header>{{ $t('page.users', 'Сотрудники') }}</template>

        <!-- Мини-статистика -->
        <div class="mb-4 grid grid-cols-3 gap-3 sm:max-w-md">
            <div class="rounded-xl border border-slate-200 bg-white px-4 py-3 shadow-sm">
                <p class="text-2xl font-bold text-slate-900">{{ stats.total }}</p>
                <p class="text-xs text-slate-500">{{ $e('Всего') }}</p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white px-4 py-3 shadow-sm">
                <p class="text-2xl font-bold text-emerald-600">{{ stats.active }}</p>
                <p class="text-xs text-slate-500">{{ $e('Активных') }}</p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white px-4 py-3 shadow-sm">
                <p class="text-2xl font-bold text-indigo-600">{{ stats.departments }}</p>
                <p class="text-xs text-slate-500">{{ $e('Отделов') }}</p>
            </div>
        </div>

        <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
            <TextInput v-model="search" :placeholder="$e('Поиск: имя, email, телефон, отдел, роль…')" class="w-full sm:w-80" />
            <div class="flex items-center gap-2">
                <a :href="route('users.export')">
                    <SecondaryButton>⬇ Excel</SecondaryButton>
                </a>
                <Link v-if="can.manage" :href="route('departments.index')">
                    <SecondaryButton>{{ $e('⚙ Отделы') }}</SecondaryButton>
                </Link>
                <PrimaryButton v-if="can.manage" @click="openCreate">{{ $e('+ Добавить сотрудника') }}</PrimaryButton>
            </div>
        </div>

        <!-- Фильтр по отделам -->
        <div class="mb-5 flex flex-wrap items-center gap-2">
            <button type="button" @click="deptFilter = 'all'"
                class="rounded-full px-3 py-1.5 text-xs font-semibold transition"
                :class="deptFilter === 'all' ? 'bg-indigo-600 text-white' : 'bg-white text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50'">
                {{ $e('Все') }}
            </button>
            <button v-for="c in deptChips" :key="c.id" type="button" @click="deptFilter = deptFilter === c.id ? 'all' : c.id"
                class="rounded-full px-3 py-1.5 text-xs font-semibold transition"
                :class="deptFilter === c.id ? 'bg-indigo-600 text-white' : 'bg-white text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50'">
                {{ c.name }} <span :class="deptFilter === c.id ? 'text-indigo-200' : 'text-slate-400'">{{ c.count }}</span>
            </button>
            <label v-if="inactiveCount" class="ml-auto flex cursor-pointer items-center gap-1.5 text-xs text-slate-500">
                <input type="checkbox" v-model="showInactive" class="rounded border-slate-300 text-indigo-600" />
                {{ $e('Отключённые (') }}{{ inactiveCount }})
            </label>
        </div>

        <!-- Таблица сотрудников: одна строка — один человек.
             Отделы раньше были секциями с карточками; на десятке сотрудников
             карточки занимали три экрана, и сравнить людей глазами было
             нельзя. Отдел остался колонкой и фильтром-чипом выше. -->
        <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-400">
                    <tr>
                        <th class="px-6 py-3">{{ $e('Пользователь') }}</th>
                        <th class="px-4 py-3">{{ $e('Email') }}</th>
                        <th class="px-4 py-3">{{ $e('Роль') }}</th>
                        <th class="px-4 py-3">{{ $e('Филиал') }}</th>
                        <th class="px-4 py-3">{{ $e('Отдел') }}</th>
                        <th class="px-4 py-3">{{ $e('Статус') }}</th>
                        <th v-if="can.manage" class="px-4 py-3 text-right">{{ $e('Действия') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="u in visibleUsers" :key="u.id"
                        class="group cursor-pointer transition-colors duration-150 hover:bg-slate-50/70"
                        :class="{ 'opacity-60': !u.is_active }"
                        @click="router.visit(route('users.show', u.id))">
                        <td class="px-6 py-3">
                            <div class="flex items-center gap-3">
                                <Avatar :name="u.name" :src="u.avatar" :size="40" />
                                <div class="min-w-0">
                                    <div class="flex items-center gap-1.5">
                                        <span v-if="headIds.has(u.id)" :title="$e('Руководитель отдела')">⭐</span>
                                        <span class="truncate font-semibold text-slate-900">{{ u.name }}</span>
                                        <span v-if="daysToBirthday(u) === 0" class="rounded-full bg-pink-50 px-2 py-0.5 text-[10px] font-semibold text-pink-600">{{ $e('🎂 сегодня!') }}</span>
                                        <span v-else-if="daysToBirthday(u) !== null && daysToBirthday(u) <= 7" class="rounded-full bg-pink-50 px-2 py-0.5 text-[10px] font-semibold text-pink-600">{{ $e('🎂 через') }} {{ daysToBirthday(u) }} {{ $e('дн.') }}</span>
                                    </div>
                                    <div class="text-xs text-slate-400">{{ hiredSince(u) ?? '—' }}</div>
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            <a :href="`mailto:${u.email}`" class="text-slate-500 transition-colors hover:text-indigo-600" @click.stop>{{ u.email }}</a>
                        </td>
                        <td class="px-4 py-3">
                            <span class="inline-block rounded-full px-2.5 py-0.5 text-xs font-medium ring-1"
                                :class="roleColors[u.role] ?? roleColors.employee">{{ roleLabels[u.role] ?? u.role ?? '—' }}</span>
                        </td>
                        <td class="px-4 py-3">
                            <!-- Филиал = цех, к которому у сотрудника есть доступ.
                                 Пусто — доступны все (руководство). -->
                            <span v-if="u.workshops?.length" class="inline-flex items-center gap-1.5 rounded-full bg-sky-50 px-2.5 py-0.5 text-xs font-medium text-sky-700">
                                <span class="h-1.5 w-1.5 rounded-full bg-sky-500"></span>{{ u.workshops.join(' · ') }}
                            </span>
                            <span v-else class="text-slate-300">—</span>
                        </td>
                        <td class="px-4 py-3 text-slate-500">{{ u.department?.name ?? '—' }}</td>
                        <td class="px-4 py-3">
                            <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium"
                                :class="u.is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500'">
                                <span class="h-1.5 w-1.5 rounded-full" :class="u.is_active ? 'bg-emerald-500' : 'bg-slate-400'"></span>
                                {{ u.is_active ? $e('Активен') : $e('Отключён') }}
                            </span>
                        </td>
                        <td v-if="can.manage" class="px-4 py-3">
                            <div class="flex items-center justify-end gap-2">
                                <button class="rounded-lg bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-700 transition-colors duration-150 hover:bg-emerald-100"
                                    @click.stop="openEdit(u)">✏️ {{ $e('Изменить') }}</button>
                                <!-- Себя не отключают: иначе можно закрыть себе вход. -->
                                <button v-if="u.is_active && u.id !== currentUserId"
                                    class="rounded-lg bg-rose-50 px-3 py-1.5 text-xs font-semibold text-rose-600 transition-colors duration-150 hover:bg-rose-100"
                                    @click.stop="deactivate(u)">🗑 {{ $e('Удалить') }}</button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="!visibleUsers.length" class="mt-3 rounded-xl border border-dashed border-slate-300 bg-white px-4 py-14 text-center text-slate-400">
            {{ $e('Никого не нашли — измените поиск или фильтр') }}
        </div>

        <Modal :show="show" @close="show = false" max-width="2xl">
            <div class="p-6">
                <h2 class="mb-4 text-lg font-semibold">{{ editing ? $e('Изменить сотрудника') : $e('Новый сотрудник') }}</h2>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <InputLabel :value="$e('Имя')" />
                        <TextInput v-model="form.name" class="mt-1 w-full" />
                        <InputError :message="form.errors.name" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel value="Email" />
                        <TextInput v-model="form.email" type="email" class="mt-1 w-full" />
                        <InputError :message="form.errors.email" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel :value="editing ? $e('Новый пароль (если менять)') : $e('Пароль')" />
                        <TextInput v-model="form.password" type="password" class="mt-1 w-full" />
                        <InputError :message="form.errors.password" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel :value="$e('Повтор пароля')" />
                        <TextInput v-model="form.password_confirmation" type="password" class="mt-1 w-full" />
                    </div>
                    <div>
                        <InputLabel :value="$e('Отдел')" />
                        <select v-model="form.department_id" class="mt-1 w-full rounded-md border-slate-300 shadow-sm">
                            <option value="">—</option>
                            <option v-for="d in departments" :key="d.id" :value="d.id">{{ d.name }}</option>
                        </select>
                    </div>
                    <div>
                        <InputLabel :value="$e('Роль')" />
                        <select v-model="form.role" class="mt-1 w-full rounded-md border-slate-300 shadow-sm">
                            <option v-for="r in roles" :key="r" :value="r">{{ roleLabels[r] ?? r }}</option>
                        </select>
                    </div>
                    <div v-if="workshopOptions.length" class="col-span-2">
                        <InputLabel :value="$e('Доступ к цехам (пусто = все цеха; можно выбрать оба)')" />
                        <div class="mt-1 flex flex-wrap gap-2">
                            <button v-for="w in workshopOptions" :key="w" type="button" @click="toggleWorkshop(w)"
                                class="rounded-lg border px-4 py-2 text-sm font-semibold transition-all"
                                :class="form.workshops.includes(w) ? 'border-indigo-500 bg-indigo-50 text-indigo-700 ring-1 ring-indigo-500' : 'border-slate-200 bg-white text-slate-500 hover:border-slate-300'">
                                🏭 {{ w }}
                            </button>
                        </div>
                        <p class="mt-1 text-[11px] text-slate-400">{{ $e('Сотрудник увидит и сможет двигать только заказы своих цехов') }}</p>
                    </div>
                    <div><InputLabel :value="$e('Телефон')" /><TextInput v-model="form.phone" class="mt-1 w-full" /></div>
                    <div>
                        <InputLabel :value="$e('День рождения (🎂 напомним руководству)')" />
                        <TextInput v-model="form.birth_date" type="date" class="mt-1 w-full" />
                        <InputError :message="form.errors.birth_date" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel :value="$e('Дата приёма на работу (стаж)')" />
                        <TextInput v-model="form.hired_at" type="date" class="mt-1 w-full" />
                        <InputError :message="form.errors.hired_at" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel :value="$e('Оклад, ₸ (ЗП = оклад + бонус)')" />
                        <TextInput v-model="form.salary" type="number" step="0.01" min="0" class="mt-1 w-full" />
                        <InputError :message="form.errors.salary" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel :value="$e('% бонуса')" />
                        <TextInput v-model="form.bonus_percent" type="number" step="0.01" min="0" max="100" class="mt-1 w-full" :placeholder="$e('например 1')" />
                        <p class="mt-1 text-[11px] text-slate-400">{{ $e('Считается от чистого остатка сделки. Пусто — ступени от маржи.') }}</p>
                        <InputError :message="form.errors.bonus_percent" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel :value="$e('Договор (файл, необязательно)')" />
                        <input type="file" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx" class="mt-1 w-full text-sm text-slate-600 file:mr-2 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-slate-700 hover:file:bg-slate-200"
                            @change="form.contract = $event.target.files[0] ?? null" />
                        <InputError :message="form.errors.contract" class="mt-1" />
                        <a v-if="editing?.has_contract" :href="route('users.contract', editing.id)" class="mt-1 inline-block text-xs text-indigo-600 hover:underline">{{ $e('📄 Скачать текущий договор') }}</a>
                    </div>
                    <div class="col-span-2">
                        <InputLabel :value="$e('Компании (может работать в обеих)')" />
                        <div class="mt-1 flex gap-2">
                            <button v-for="c in companies" :key="c.id" type="button" @click="toggleCompany(c.id)"
                                class="rounded-lg border px-4 py-2 text-sm font-semibold transition-all"
                                :class="form.company_ids.includes(c.id) ? 'border-emerald-500 bg-emerald-50 text-emerald-700 ring-1 ring-emerald-500' : 'border-slate-200 bg-white text-slate-500 hover:border-slate-300'">
                                {{ c.name }}
                            </button>
                        </div>
                    </div>
                    <label class="col-span-2 flex items-center gap-2 text-sm">
                        <input type="checkbox" v-model="form.is_active" class="rounded border-slate-300 text-indigo-600" /> {{ $e('Активен') }}
                    </label>
                </div>
                <div class="mt-6 flex justify-end gap-2">
                    <SecondaryButton @click="show = false">{{ $e('Отмена') }}</SecondaryButton>
                    <PrimaryButton :disabled="form.processing" @click="submit">{{ $e('Сохранить') }}</PrimaryButton>
                </div>
            </div>
        </Modal>
    </AppLayout>
</template>
