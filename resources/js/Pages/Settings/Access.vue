<script setup>
/**
 * Настройки → Права доступа: кто что может и НА СКОЛЬКО ЗАПИСЕЙ.
 *
 * Роли — колонки с лицами тех, кто их носит: колонка без лиц просто слово, и
 * владелец не видит, кого он сейчас ограничивает. Строки — разделы системы,
 * сворачиваются: четырнадцать разделов по пять действий разом не читаются.
 *
 * Значение — не галочка, а ОБЛАСТЬ: Нет доступа / Свои / Отдел / Отдел и
 * подчинённые / Все. Галочка отвечала только «пустят ли», и между «видит свои»
 * и «видит всё» не было места для руководителя отдела — его приходилось
 * делать директором вместе с доступом к чужим деньгам.
 *
 * Что значит «Отдел», решает дерево в «Структуре компании» — поэтому страницы
 * ссылаются друг на друга.
 *
 * Роль admin показана серой: она суперпользователь через Gate::before, и
 * выбор области у неё был бы ложью.
 *
 * Шаг текста здесь МЕЛЬЧЕ общего (13 px против 14, строки 1.5 вместо 2.5):
 * это ведомость на четырнадцать разделов и десяток ролей, и на общем шаге она
 * не помещается на экран — читать её приходилось бы прокруткой в обе стороны.
 * Крупный шаг остаётся там, где текст читают, а не сверяют глазами (§9.2).
 */
import { computed, reactive, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Avatar from '@/Components/Avatar.vue';
import Modal from '@/Components/Modal.vue';
import PeoplePicker from '@/Components/PeoplePicker.vue';
import { confirmDialog } from '@/composables/useConfirm';
import { useE } from '@/composables/useTranslations';

const tr = useE();

const props = defineProps({
    roles: { type: Array, default: () => [] },
    modules: { type: Array, default: () => [] },
    abilities: { type: Object, default: () => ({}) },
    scopeLevels: { type: Array, default: () => [] },
    traitLabels: { type: Object, default: () => ({}) },
    people: { type: Array, default: () => [] },
    departments: { type: Array, default: () => [] },
});

// Область по умолчанию, когда её не настраивали: ровно то, что делает сервер
// (AccessScope::for) — руководство видит всё, остальные своё. Разойдись эти
// два ответа, и таблица показывала бы не то, что действует.
const fallback = (role, permission) => {
    if (!role.permissions.includes(permission)) return 'none';
    return role.traits.is_leadership ? 'all' : 'own';
};

const draft = reactive(Object.fromEntries(props.roles.map((role) => {
    const row = {};
    for (const m of props.modules) {
        for (const permission of Object.values(m.permissions)) {
            row[permission] = role.scopes[permission] ?? fallback(role, permission);
        }
    }
    return [role.name, row];
})));
const traits = reactive(Object.fromEntries(props.roles.map((r) => [r.name, { ...r.traits }])));

const snapshot = (name) => JSON.stringify(draft[name]) + JSON.stringify(traits[name]);
const saved = Object.fromEntries(props.roles.map((r) => [r.name, snapshot(r.name)]));
const dirty = (name) => snapshot(name) !== saved[name];
const changed = computed(() => props.roles.filter((r) => !r.locked && dirty(r.name)));

const busy = ref(null);
const save = (role) => {
    busy.value = role;
    router.put(route('access.update'), { role, scopes: draft[role], traits: traits[role] },
        { preserveScroll: true, onFinish: () => (busy.value = null) });
};

// Раздел целиком: у четырнадцати разделов по пять действий иначе семьдесят
// кликов на роль.
const setModule = (roleName, module, scope) => {
    for (const permission of Object.values(module.permissions)) draft[roleName][permission] = scope;
};
const moduleScope = (roleName, module) => {
    const values = [...new Set(Object.values(module.permissions).map((p) => draft[roleName][p]))];
    return values.length === 1 ? values[0] : 'mixed';
};

const open = ref(new Set(props.modules.slice(0, 1).map((m) => m.key)));
const toggle = (key) => {
    const set = new Set(open.value);
    set.has(key) ? set.delete(key) : set.add(key);
    open.value = set;
};

/*
 * Цвет области — шкала ширины, а не светофор: чем шире доступ, тем заметнее.
 * «По действиям» (внутри раздела области разные) — состояние, а не ошибка:
 * серым. Тревожным оранжевым он читался как поломка, хотя это ровно то, чего
 * владелец добивался, разложив действия по-разному.
 */
/*
 * Область — бейджем: цвет несёт ширину доступа, и строку читаешь не вчитываясь.
 * «Нет доступа» серым, «Все» зелёным, «По действиям» мягким оранжевым — это
 * состояние (внутри раздела области разные), а не ошибка.
 */
const scopeClass = (scope) => ({
    none: 'bg-slate-100 text-slate-500 border-slate-200',
    own: 'bg-white text-slate-700 border-slate-200',
    department: 'bg-bx-50 text-bx-600 border-bx-200',
    department_tree: 'bg-bx-100 text-bx-600 border-bx-200',
    all: 'bg-emerald-50 text-emerald-700 border-emerald-200',
}[scope] ?? 'bg-amber-50 text-amber-700 border-amber-200');

// ---- Меню роли (⋯) ----
// Действия роли собраны в одно меню, а не разложены кнопками по шапке: их
// пять, а колонок десять — пятьдесят кнопок в шапке никто не читает.
const menuFor = ref(null);
// Координаты кнопки: меню живёт ВНЕ таблицы. Внутри её обрезал бы
// `overflow-x-auto` — выпадающий список просто исчезал бы за краем.
const menuAt = ref({ top: 0, left: 0 });
const toggleMenu = (role, event) => {
    if (menuFor.value?.name === role.name) {
        menuFor.value = null;

        return;
    }
    const box = event.currentTarget.getBoundingClientRect();
    menuAt.value = { top: box.bottom + 6, left: box.left + box.width / 2 };
    menuFor.value = role;
};

// Открыть/закрыть всё разом. Меняем черновик и СРАЗУ сохраняем: пункт меню
// обещает «откроется доступ», а не «подготовится к сохранению».
const setAll = (role, scope) => {
    for (const m of props.modules) {
        for (const permission of Object.values(m.permissions)) draft[role.name][permission] = scope;
    }
    menuFor.value = null;
    save(role.name);
};

// ---- Переименование ----
const renameForm = useForm({ label: '' });
const renamingRole = ref(null);
const openRename = (role) => {
    renamingRole.value = role;
    renameForm.clearErrors();
    renameForm.label = role.label;
    menuFor.value = null;
};
const submitRename = () => renameForm.put(route('access.roles.rename', renamingRole.value.id), {
    preserveScroll: true, onSuccess: () => (renamingRole.value = null),
});

// ---- Состав роли: кто её носит ----
// Колонка без лиц — просто слово. Добавляем людей отделом или ролью целиком:
// назначить право отделу из восьми человек поштучно значит восемь раз
// повторить одно решение.
const pickerFor = ref(null);
const openPicker = (role) => (pickerFor.value = role);
const addPeople = (ids) => {
    if (!pickerFor.value) return;
    router.post(route('access.roles.addUsers', pickerFor.value.id), { users: ids }, { preserveScroll: true });
};
const removePerson = (role, person) =>
    router.delete(route('access.roles.removeUser', [role.id, person.id]), { preserveScroll: true });

// ---- Новая роль ----
const showRole = ref(false);
const roleForm = useForm({ label: '', name: '', copy_from: '' });
// Код роли предлагаем сами: владелец пишет «Старший менеджер», латиницу
// придумывать не должен.
const suggestCode = () => {
    const map = { а:'a',б:'b',в:'v',г:'g',д:'d',е:'e',ё:'e',ж:'zh',з:'z',и:'i',й:'y',к:'k',л:'l',м:'m',н:'n',о:'o',п:'p',р:'r',с:'s',т:'t',у:'u',ф:'f',х:'h',ц:'c',ч:'ch',ш:'sh',щ:'sch',ъ:'',ы:'y',ь:'',э:'e',ю:'yu',я:'ya',' ':'_','-':'_' };
    roleForm.name = [...roleForm.label.toLowerCase()]
        .map((c) => (c in map ? map[c] : /[a-z0-9_]/.test(c) ? c : ''))
        .join('').replace(/_+/g, '_').replace(/^_|_$/g, '').slice(0, 40);
};
const openRole = (copyFrom = null) => {
    roleForm.reset();
    roleForm.clearErrors();
    // Копия: образец выбран, владельцу остаётся дать имя и убрать лишнее.
    if (copyFrom) {
        roleForm.copy_from = copyFrom.name;
        roleForm.label = copyFrom.label + ' — копия';
    }
    menuFor.value = null;
    showRole.value = true;
};
const submitRole = () => roleForm.post(route('access.roles.store'), {
    preserveScroll: true, onSuccess: () => (showRole.value = false),
});
const removeRole = async (role) => {
    // Подтверждение называет последствия числом: «удалить роль» и «оставить
    // шесть человек без доступа» — это два разных решения.
    // Системная роль упоминается в коде по ИМЕНИ: кому уходит весть о
    // нехватке склада, кто закрывает гейт-этап. Удалить её можно, но человек
    // должен понимать, что замолчит, — иначе узнаёт об этом по пропавшим
    // письмам. Вернуть: php artisan roles:restore.
    const parts = [`«${role.label}»: удалятся все настроенные области роли.`];
    if (role.holders.length) parts.push(`Сотрудников без роли останется ${role.holders.length} — назначьте им новую.`);
    if (role.system) parts.push('Это системная роль: на её имя ссылается код (уведомления, гейты этапов). Вернуть — командой roles:restore.');
    const message = parts.join(' ');

    if (!(await confirmDialog({ title: tr('Удалить роль?'), message, confirmText: tr('Удалить'), danger: true }))) return;

    menuFor.value = null;
    router.delete(route('access.roles.destroy', role.id), { preserveScroll: true });
};
</script>

<template>
    <Head :title="$e('Права доступа')" />
    <AppLayout>
        <template #header>{{ $e('Настройки системы') }}</template>

        <div class="mb-4 flex gap-2 border-b">
            <Link :href="route('settings.index')" class="px-3 py-2 text-sm text-slate-500 hover:text-slate-700">{{ $e('Общие') }}</Link>
            <Link :href="route('stages.index')" class="px-3 py-2 text-sm text-slate-500 hover:text-slate-700">{{ $e('Этапы') }}</Link>
            <Link :href="route('screens.index')" class="px-3 py-2 text-sm text-slate-500 hover:text-slate-700">{{ $e('Экраны') }}</Link>
            <Link :href="route('custom-fields.index')" class="px-3 py-2 text-sm text-slate-500 hover:text-slate-700">{{ $e('Доп. поля') }}</Link>
            <Link :href="route('siteSettings.index')" class="px-3 py-2 text-sm text-slate-500 hover:text-slate-700">{{ $e('Сайт') }}</Link>
            <Link :href="route('access.index')" class="border-b-2 border-bx-500 px-3 py-2 text-sm font-medium text-bx-600">{{ $e('Права доступа') }}</Link>
            <Link :href="route('structure.index')" class="px-3 py-2 text-sm text-slate-500 hover:text-slate-700">{{ $e('Структура') }}</Link>
        </div>

        <div class="mx-auto max-w-full">
            <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold text-slate-900">{{ $e('Кто что может') }}</h2>
                    <p class="mt-0.5 text-xs text-slate-400">
                        {{ $e('Область показывает, на сколько записей действует право. Что такое «Отдел» — решает') }}
                        <Link :href="route('structure.index')" class="font-semibold text-bx-600 hover:underline">{{ $e('Структура компании') }}</Link>.
                    </p>
                </div>
                <button @click="openRole" class="rounded-lg bg-bx-500 px-3 py-1.5 text-xs font-semibold text-white transition-colors duration-150 hover:bg-bx-600">{{ $e('+ Роль') }}</button>
            </div>

            <div v-if="changed.length" class="mb-4 flex flex-wrap items-center gap-2 rounded-xl border border-amber-200 bg-amber-50/60 px-4 py-2.5 text-sm">
                <span class="font-medium text-amber-800">{{ $e('Не сохранено:') }}</span>
                <button v-for="r in changed" :key="r.name" @click="save(r.name)"
                    class="rounded-full bg-white px-2.5 py-0.5 text-xs font-semibold text-amber-700 ring-1 ring-amber-200 hover:bg-amber-100">
                    {{ r.label }} — {{ $e('сохранить') }}
                </button>
            </div>

            <div class="overflow-hidden rounded-2xl border border-slate-200/60 bg-white shadow-sm">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-[12px]">
                        <thead>
                            <tr class="border-b border-slate-200 bg-slate-50">
                                <th class="sticky left-0 z-20 min-w-[13rem] bg-slate-50 px-4 py-2.5 text-left align-bottom text-[10px] font-medium uppercase tracking-wide text-slate-400 shadow-[1px_0_0_0_rgb(226_232_240)]">{{ $e('Раздел') }}</th>
                                <!-- Колонка роли: имя, меню «⋯», стопка
                                     носителей и круглая «+». Действия иконками:
                                     у десяти ролей подписи повторяли одно и то
                                     же в каждой колонке. Что делает кнопка,
                                     говорит подсказка при наведении. -->
                                <th v-for="r in roles" :key="r.name" class="min-w-[10rem] px-2.5 py-2 align-top">
                                    <div class="flex flex-col items-center gap-1">
                                        <div class="relative flex items-baseline gap-1">
                                            <span class="text-[12px] font-medium leading-tight" :class="r.locked ? 'text-slate-400' : 'text-slate-700'">{{ r.label }}</span>

                                            <!-- Меню роли: пять действий в одном
                                                 месте. Разложи их кнопками по
                                                 шапке — на десяти колонках это
                                                 полсотни кнопок, которых никто
                                                 не читает. -->
                                            <button v-if="!r.locked" type="button" @click="toggleMenu(r, $event)"
                                                :title="$e('Настройки роли')"
                                                class="rounded px-1 text-[13px] leading-none text-slate-300 transition-colors duration-150 hover:text-slate-600">⋯</button>
                                        </div>

                                        <!-- Носители — СТОПКОЙ аватаров, а не
                                             списком имён: у роли с десятью
                                             людьми список превращал колонку в
                                             столбец, и шапка занимала пол-экрана.
                                             Стопка одинаковой высоты при любом
                                             числе людей; кто именно там — по
                                             клику, там же их и убирают. -->
                                        <div class="flex items-center justify-center gap-1.5">
                                            <button v-if="r.holders.length" type="button" @click="openPicker(r)"
                                                :title="r.holders.map((h) => h.name).join(', ')"
                                                class="flex items-center transition-opacity duration-150 hover:opacity-80">
                                                <span class="flex -space-x-1.5">
                                                    <Avatar v-for="h in r.holders.slice(0, 4)" :key="h.id" :name="h.name" :src="h.avatar" :size="24"
                                                        class="ring-2 ring-white" />
                                                </span>
                                                <span v-if="r.holders.length > 4" class="ml-1.5 text-[11px] font-semibold text-slate-500">+{{ r.holders.length - 4 }}</span>
                                            </button>

                                            <button v-if="!r.locked" type="button" @click="openPicker(r)"
                                                :title="$e('Добавить сотрудника или отдел')"
                                                class="flex h-6 w-6 flex-shrink-0 items-center justify-center rounded-full border border-bx-200 text-bx-500 transition-colors duration-150 hover:border-bx-400 hover:bg-bx-50">
                                                <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
                                            </button>
                                        </div>

                                        <span v-if="r.locked" class="text-[10px] font-normal text-slate-300">{{ $e('полный доступ') }}</span>
                                    </div>
                                </th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-slate-50">
                            <!-- Признаки роли: чем роль ЯВЛЯЕТСЯ. Их не выразить
                                 областью — «видит суммы» не про число записей. -->
                            <tr v-for="(label, key) in traitLabels" :key="key" class="bg-bx-50">
                                <td class="sticky left-0 z-10 bg-bx-50 px-4 py-1.5 text-[11px] font-medium text-slate-600 shadow-[1px_0_0_0_rgb(226_232_240)]">{{ $e(label) }}</td>
                                <td v-for="r in roles" :key="r.name" class="px-2.5 py-1 text-center">
                                    <input v-if="!r.locked" type="checkbox" v-model="traits[r.name][key]"
                                        class="h-4 w-4 rounded border-slate-300 text-bx-500 focus:ring-bx-500" />
                                    <span v-else class="text-emerald-500/50">✓</span>
                                </td>
                            </tr>

                            <template v-for="m in modules" :key="m.key">
                                <tr class="bg-slate-50">
                                    <td class="sticky left-0 z-10 bg-slate-50 px-4 py-2 shadow-[1px_0_0_0_rgb(226_232_240)]">
                                        <button type="button" @click="toggle(m.key)" class="flex items-center gap-2 text-left">
                                            <svg class="h-3 w-3 text-slate-300 transition-transform duration-200" :class="open.has(m.key) ? 'rotate-90' : ''"
                                                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
                                            <span class="min-w-0">
                                                <span class="text-[12px] font-semibold text-slate-800">{{ $e(m.label) }}</span>
                                                <span v-if="m.hint" class="ml-1.5 text-[10px] font-normal text-slate-400">{{ $e(m.hint) }}</span>
                                            </span>
                                        </button>
                                    </td>
                                    <td v-for="r in roles" :key="r.name" class="px-2.5 py-1.5 text-center">
                                        <select v-if="!r.locked" :value="moduleScope(r.name, m)" @change="setModule(r.name, m, $event.target.value)"
                                            class="w-full rounded-md border py-0.5 pl-1.5 pr-5 text-[11px] font-semibold shadow-sm focus:border-bx-500 focus:ring-2 focus:ring-bx-500/20"
                                            :class="scopeClass(moduleScope(r.name, m))">
                                            <option v-if="moduleScope(r.name, m) === 'mixed'" value="mixed">{{ $e('по действиям') }}</option>
                                            <option v-for="s in scopeLevels" :key="s.value" :value="s.value">{{ $e(s.label) }}</option>
                                        </select>
                                        <span v-else class="text-[12px] font-semibold text-emerald-600/45">{{ $e('Все') }}</span>
                                    </td>
                                </tr>

                                <!-- Подсветка строки — через group: липкая ячейка
                                     красится вместе со строкой, но остаётся
                                     НЕПРОЗРАЧНОЙ. Полупрозрачный фон пропускал
                                     сквозь себя прокрученные колонки, и поверх
                                     подписи «Список» проступало чужое «Все». -->
                                <tr v-for="(label, key) in abilities" :key="m.key + key" v-show="open.has(m.key) && m.permissions[key]"
                                    class="group transition-colors duration-150 hover:bg-slate-50">
                                    <td class="sticky left-0 z-10 bg-white px-4 py-1.5 pl-9 text-slate-500 shadow-[1px_0_0_0_rgb(226_232_240)] transition-colors duration-150 group-hover:bg-slate-50">{{ $e(label) }}</td>
                                    <td v-for="r in roles" :key="r.name" class="px-2.5 py-1 text-center">
                                        <select v-if="!r.locked && m.permissions[key]" v-model="draft[r.name][m.permissions[key]]"
                                            class="w-full rounded-md border py-0.5 pl-1.5 pr-5 text-[11px] shadow-sm focus:border-bx-500 focus:ring-2 focus:ring-bx-500/20"
                                            :class="scopeClass(draft[r.name][m.permissions[key]])">
                                            <option v-for="s in scopeLevels" :key="s.value" :value="s.value">{{ $e(s.label) }}</option>
                                        </select>
                                        <span v-else-if="r.locked && m.permissions[key]" class="text-[12px] text-emerald-600/45">{{ $e('Все') }}</span>
                                        <span v-else class="text-slate-200">—</span>
                                    </td>
                                </tr>
                            </template>
                        </tbody>

                        <tfoot class="border-t border-slate-200 bg-slate-50">
                            <tr>
                                <td class="sticky left-0 z-10 bg-slate-50 px-4 py-2.5 text-[10px] text-slate-400 shadow-[1px_0_0_0_rgb(226_232_240)]">{{ $e('Сохраняется по одной роли') }}</td>
                                <td v-for="r in roles" :key="r.name" class="px-2.5 py-2 text-center">
                                    <button v-if="!r.locked" type="button" :disabled="!dirty(r.name) || busy === r.name" @click="save(r.name)"
                                        class="rounded-lg px-2.5 py-1 text-[11px] font-semibold transition-colors duration-150"
                                        :class="dirty(r.name) ? 'bg-bx-500 text-white hover:bg-bx-600' : 'bg-slate-100 text-slate-300'">
                                        {{ busy === r.name ? '…' : $e('Сохранить') }}
                                    </button>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <p class="mt-4 text-xs text-slate-400">
                {{ $e('Личные доступы сверх роли — в карточке сотрудника. Правила про деньги остаются в силе: область открывает записи, но не отменяет проверок в политиках.') }}
            </p>
        </div>

        <!-- Клик мимо закрывает меню: без подложки оно висит, пока не нажмёшь
             ту же кнопку ещё раз. -->
        <div v-if="menuFor" class="fixed inset-0 z-30" @click="menuFor = null"></div>

        <!-- Меню роли — телепортом в body и фиксированно по кнопке: внутри
             таблицы его обрезал бы `overflow-x-auto`. -->
        <Teleport to="body">
            <div v-if="menuFor" class="fixed z-40 w-64 -translate-x-1/2 overflow-hidden rounded-xl border border-slate-200/60 bg-white text-left shadow-lg"
                :style="{ top: menuAt.top + 'px', left: menuAt.left + 'px' }">
                <button type="button" @click="setAll(menuFor, 'all')" class="flex w-full gap-2.5 px-3 py-2.5 text-left transition-colors duration-150 hover:bg-slate-50">
                    <span class="mt-0.5 text-emerald-600">✓</span>
                    <span>
                        <span class="block text-[12px] font-semibold text-slate-900">{{ $e('Открыть доступ ко всем') }}</span>
                        <span class="block text-[11px] font-normal text-slate-400">{{ $e('Роль получит область «Все» во всех разделах') }}</span>
                    </span>
                </button>
                <button type="button" @click="setAll(menuFor, 'none')" class="flex w-full gap-2.5 border-t border-slate-100 px-3 py-2.5 text-left transition-colors duration-150 hover:bg-slate-50">
                    <span class="mt-0.5 text-rose-500">🔒</span>
                    <span>
                        <span class="block text-[12px] font-semibold text-slate-900">{{ $e('Закрыть доступ ко всем') }}</span>
                        <span class="block text-[11px] font-normal text-slate-400">{{ $e('Все права роли снимаются разом') }}</span>
                    </span>
                </button>
                <button type="button" @click="openRename(menuFor)" class="flex w-full gap-2.5 border-t border-slate-100 px-3 py-2.5 text-left transition-colors duration-150 hover:bg-slate-50">
                    <span class="mt-0.5 text-bx-500">✎</span>
                    <span>
                        <span class="block text-[12px] font-semibold text-slate-900">{{ $e('Переименовать') }}</span>
                        <span class="block text-[11px] font-normal text-slate-400">{{ $e('Меняется подпись, код роли остаётся') }}</span>
                    </span>
                </button>
                <button type="button" @click="openRole(menuFor)" class="flex w-full gap-2.5 border-t border-slate-100 px-3 py-2.5 text-left transition-colors duration-150 hover:bg-slate-50">
                    <span class="mt-0.5 text-bx-500">⧉</span>
                    <span>
                        <span class="block text-[12px] font-semibold text-slate-900">{{ $e('Скопировать') }}</span>
                        <span class="block text-[11px] font-normal text-slate-400">{{ $e('В новую роль перейдут все области этой') }}</span>
                    </span>
                </button>
                <button type="button" @click="removeRole(menuFor)"
                    class="flex w-full gap-2.5 border-t border-slate-100 px-3 py-2.5 text-left transition-colors duration-150 hover:bg-rose-50">
                    <span class="mt-0.5 text-rose-500">🗑</span>
                    <span>
                        <span class="block text-[12px] font-semibold text-slate-900">{{ $e('Удалить') }}</span>
                        <span class="block text-[11px] font-normal text-slate-400">
                            {{ menuFor.system ? $e('Системная роль — на её имя ссылается код')
                                : menuFor.holders.length ? $e('Сотрудники останутся без роли:') + ' ' + menuFor.holders.length
                                : $e('Роль удалится со всеми настроенными областями') }}
                        </span>
                    </span>
                </button>
            </div>
        </Teleport>

        <!-- Переименование -->
        <Modal :show="!!renamingRole" max-width="md" @close="renamingRole = null">
            <div class="p-6">
                <h2 class="mb-1 text-base font-semibold text-slate-900">{{ $e('Переименовать роль') }}</h2>
                <p class="mb-4 text-xs text-slate-400">{{ $e('Код роли не меняется: на нём держатся правила и политики.') }}</p>
                <input v-model="renameForm.label" type="text" @keyup.enter="submitRename"
                    class="w-full rounded-lg border-slate-200 text-sm shadow-sm focus:border-bx-500 focus:ring-2 focus:ring-bx-500/20" />
                <p v-if="renameForm.errors.label" class="mt-1 text-xs text-rose-600">{{ renameForm.errors.label }}</p>
                <div class="mt-5 flex justify-end gap-2">
                    <button @click="renamingRole = null" class="rounded-lg px-4 py-2 text-sm font-medium text-slate-500 hover:text-slate-700">{{ $e('Отмена') }}</button>
                    <button :disabled="renameForm.processing" @click="submitRename"
                        class="rounded-lg bg-bx-500 px-4 py-2 text-sm font-semibold text-white transition-colors duration-150 hover:bg-bx-600 disabled:opacity-50">{{ $e('Сохранить') }}</button>
                </div>
            </div>
        </Modal>

        <PeoplePicker :show="!!pickerFor" :title="pickerFor ? $e('Роль') + ' «' + pickerFor.label + '»' : ''"
            :people="people" :departments="departments" :roles="roles"
            :members="pickerFor?.holders ?? []"
            :selected="pickerFor ? pickerFor.holders.map((h) => h.id) : []"
            @close="pickerFor = null" @pick="addPeople"
            @remove="(id) => removePerson(pickerFor, { id })" />

        <!-- Новая роль -->
        <Modal :show="showRole" max-width="lg" @close="showRole = false">
            <div class="p-6">
                <h2 class="mb-1 text-lg font-semibold text-slate-900">{{ $e('Новая роль') }}</h2>
                <p class="mb-5 text-xs text-slate-400">{{ $e('Проще создать копию похожей роли и убрать лишнее, чем набирать семьдесят областей с нуля.') }}</p>

                <div class="space-y-4">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">{{ $e('Название') }}</label>
                        <input v-model="roleForm.label" @blur="!roleForm.name && suggestCode()" type="text"
                            :placeholder="$e('Руководитель отдела продаж')"
                            class="w-full rounded-lg border-slate-200 text-sm shadow-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20" />
                        <p v-if="roleForm.errors.label" class="mt-1 text-xs text-rose-600">{{ roleForm.errors.label }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">{{ $e('Код (латиницей)') }}</label>
                        <input v-model="roleForm.name" type="text" placeholder="sales_head"
                            class="w-full rounded-lg border-slate-200 font-mono text-sm shadow-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20" />
                        <p class="mt-1 text-[11px] text-slate-400">{{ $e('Код не меняется потом: на нём держатся правила. Название переименовать можно всегда.') }}</p>
                        <p v-if="roleForm.errors.name" class="mt-1 text-xs text-rose-600">{{ roleForm.errors.name }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">{{ $e('Взять доступы у роли') }}</label>
                        <select v-model="roleForm.copy_from" class="w-full rounded-lg border-slate-200 text-sm shadow-sm">
                            <option value="">{{ $e('— пустая роль') }}</option>
                            <option v-for="r in roles" :key="r.name" :value="r.name">{{ r.label }}</option>
                        </select>
                    </div>
                </div>

                <div class="mt-6 flex justify-end gap-2">
                    <button @click="showRole = false" class="rounded-lg px-4 py-2 text-sm font-medium text-slate-500 hover:text-slate-700">{{ $e('Отмена') }}</button>
                    <button :disabled="roleForm.processing" @click="submitRole"
                        class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition-colors duration-150 hover:bg-indigo-700 disabled:opacity-50">{{ $e('Создать роль') }}</button>
                </div>
            </div>
        </Modal>
    </AppLayout>
</template>
