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
import { computed, reactive, ref, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import SettingsLayout from '@/Layouts/SettingsLayout.vue';
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

/*
 * Черновик галочек. ПЕРЕСОБИРАЕТСЯ при каждой смене списка ролей.
 *
 * Строился один раз при создании компонента — и после «+ Роль» страница
 * белела: список приходил с новой ролью, шаблон брал `traits[r.name]`, а
 * записи для неё не было. Правило простое: состояние, выведенное из пропа,
 * обязано следовать за пропом.
 *
 * Несохранённые правки существующих ролей при этом остаются на месте: их
 * потеря после создания соседней роли выглядела бы как потеря данных.
 */
const draft = reactive({});
const traits = reactive({});
const saved = reactive({});

const rowFor = (role) => {
    const row = {};
    for (const m of props.modules) {
        for (const permission of Object.values(m.permissions)) {
            row[permission] = role.scopes[permission] ?? fallback(role, permission);
        }
    }
    return row;
};

const snapshot = (name) => JSON.stringify(draft[name] ?? {}) + JSON.stringify(traits[name] ?? {});

const syncRoles = (roles) => {
    const names = new Set(roles.map((r) => r.name));

    for (const role of roles) {
        const serverRow = rowFor(role);
        const serverTraits = { ...role.traits };
        const fromServer = JSON.stringify(serverRow) + JSON.stringify(serverTraits);

        /*
         * Точка отсчёта — ВСЕГДА состояние сервера. «Не сохранено» тогда
         * означает «отличается от того, что в базе сейчас», и после записи
         * гаснет само.
         *
         * Раньше отсчёт брался из черновика и обновлялся, только когда роль
         * «чистая», — а сразу после сохранения она как раз грязная. Отметка
         * висела навсегда, и кнопка «Сохранить» выглядела не нажатой.
         */
        saved[role.name] = fromServer;

        // Черновика нет — берём серверный. Есть и уже совпал с сервером
        // (только что записали) — обновляем, чтобы не держать копию.
        // Отличается — значит человек правит прямо сейчас, не трогаем.
        if (! draft[role.name] || snapshot(role.name) === fromServer) {
            draft[role.name] = serverRow;
            traits[role.name] = serverTraits;
        }
    }

    // Роль удалили — убираем и её черновик, иначе он копится в памяти и
    // «не сохранено» показывает то, чего больше нет.
    for (const name of Object.keys(draft)) {
        if (! names.has(name)) {
            delete draft[name];
            delete traits[name];
            delete saved[name];
        }
    }
};

const dirty = (name) => saved[name] !== undefined && snapshot(name) !== saved[name];

syncRoles(props.roles);
watch(() => props.roles, (roles) => syncRoles(roles), { deep: true });
const changed = computed(() => props.roles.filter((r) => !r.locked && dirty(r.name)));

const busy = ref(null);
const save = (role, after = null) => {
    busy.value = role;
    router.put(route('access.update'), { role, scopes: draft[role] ?? {}, traits: traits[role] ?? {} },
        { preserveScroll: true, onFinish: () => { busy.value = null; after?.(); } });
};

/*
 * Сохранить все правки подряд, по одной роли за раз.
 *
 * Не параллельно: каждый ответ приносит свежий список ролей, и одновременные
 * запросы перетирали бы состояние друг друга — последний ответ решал бы, что
 * сохранилось, а что нет.
 */
const saveAll = () => {
    const queue = changed.value.map((r) => r.name);
    const next = () => {
        const name = queue.shift();
        if (name) save(name, next);
    };
    next();
};

// Раздел целиком: у четырнадцати разделов по пять действий иначе семьдесят
// кликов на роль.
const setModule = (roleName, module, scope) => {
    if (! draft[roleName]) return;
    for (const permission of Object.values(module.permissions)) draft[roleName][permission] = scope;
};
const moduleScope = (roleName, module) => {
    const row = draft[roleName];
    if (! row) return 'none';   // роль только что появилась — черновик ещё собирается

    const values = [...new Set(Object.values(module.permissions).map((p) => row[p]))];
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
    department: 'bg-indigo-50 text-indigo-700 border-indigo-200',
    department_tree: 'bg-indigo-100 text-indigo-700 border-indigo-200',
    all: 'bg-emerald-50 text-emerald-700 border-emerald-200',
}[scope] ?? 'bg-amber-50 text-amber-700 border-amber-200');

// ---- Меню роли (⋯) ----
// Действия роли собраны в одно меню, а не разложены кнопками по шапке: их
// пять, а колонок десять — пятьдесят кнопок в шапке никто не читает.
/*
 * Меню и выбор людей держат ИМЯ роли, а не сам объект.
 *
 * Объект приходит из пропа: добавил человека в роль, props обновились — а
 * сохранённая ссылка указывает на старую копию, и список носителей в модалке
 * остаётся без только что добавленного. Имя переживает обновление, объект нет.
 */
const menuForName = ref(null);
const menuFor = computed(() => props.roles.find((r) => r.name === menuForName.value) ?? null);
// Координаты кнопки: меню живёт ВНЕ таблицы. Внутри её обрезал бы
// `overflow-x-auto` — выпадающий список просто исчезал бы за краем.
const menuAt = ref({ top: 0, left: 0 });
const toggleMenu = (role, event) => {
    if (menuForName.value === role.name) {
        menuForName.value = null;

        return;
    }
    const box = event.currentTarget.getBoundingClientRect();
    menuAt.value = { top: box.bottom + 6, left: box.left + box.width / 2 };
    menuForName.value = role.name;
};

// Открыть/закрыть всё разом. Меняем черновик и СРАЗУ сохраняем: пункт меню
// обещает «откроется доступ», а не «подготовится к сохранению».
const setAll = (role, scope) => {
    if (! draft[role.name]) return;
    for (const m of props.modules) {
        for (const permission of Object.values(m.permissions)) draft[role.name][permission] = scope;
    }
    menuForName.value = null;
    save(role.name);
};

// ---- Переименование ----
const renameForm = useForm({ label: '' });
const renamingRole = ref(null);
const openRename = (role) => {
    renamingRole.value = role;
    renameForm.clearErrors();
    renameForm.label = role.label;
    menuForName.value = null;
};
const submitRename = () => renameForm.put(route('access.roles.rename', renamingRole.value.id), {
    preserveScroll: true, onSuccess: () => (renamingRole.value = null),
});

// ---- Состав роли: кто её носит ----
// Колонка без лиц — просто слово. Добавляем людей отделом или ролью целиком:
// назначить право отделу из восьми человек поштучно значит восемь раз
// повторить одно решение.
const pickerForName = ref(null);
const pickerFor = computed(() => props.roles.find((r) => r.name === pickerForName.value) ?? null);
const openPicker = (role) => (pickerForName.value = role.name);
const addPeople = (ids) => {
    if (! pickerFor.value) return;
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
    menuForName.value = null;
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

    menuForName.value = null;
    router.delete(route('access.roles.destroy', role.id), { preserveScroll: true });
};
</script>

<template>
    <Head :title="$e('Права доступа')" />
    <SettingsLayout :title="$e('Права доступа')" wide>

        <div class="mx-auto max-w-full">
            <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold text-slate-900">{{ $e('Кто что может') }}</h2>
                    <p class="mt-0.5 text-xs text-slate-400">
                        {{ $e('Область показывает, на сколько записей действует право. Что такое «Отдел» — решает') }}
                        <Link :href="route('structure.index')" class="font-semibold text-indigo-700 hover:underline">{{ $e('Структура компании') }}</Link>.
                    </p>
                </div>
                <button @click="openRole" class="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white transition-colors duration-150 hover:bg-indigo-700">{{ $e('+ Роль') }}</button>
            </div>

            <!-- Полоса правок липнет к верху: правишь середину таблицы —
                 сохранить можно не поднимаясь. «Сохранить всё» одной кнопкой:
                 три роли это три клика, а бывает и больше. -->
            <div v-if="changed.length" class="sticky top-0 z-40 mb-4 flex flex-wrap items-center gap-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-2.5 text-sm shadow-sm">
                <span class="font-medium text-amber-800">{{ $e('Не сохранено:') }}</span>
                <button v-for="r in changed" :key="r.name" @click="save(r.name)"
                    class="rounded-full bg-white px-2.5 py-0.5 text-xs font-semibold text-amber-700 ring-1 ring-amber-200 hover:bg-amber-100">
                    {{ r.label }}
                </button>
                <button v-if="changed.length > 1" :disabled="!!busy" @click="saveAll"
                    class="ml-auto rounded-lg bg-amber-600 px-3 py-1 text-xs font-semibold text-white transition-colors duration-150 hover:bg-amber-700 disabled:opacity-50">
                    {{ busy ? '…' : $e('Сохранить всё') }} ({{ changed.length }})
                </button>
            </div>

            <div class="overflow-hidden rounded-2xl border border-slate-200/60 bg-white shadow-sm">
                <!-- Свой скроллбокс: пока прокручивалась вся страница,
                     sticky было не за что цеплять — шапка с ролями уезжала
                     вверх, и на середине таблицы уже не понять, чей столбец
                     правишь, а до «Сохранить» надо было листать вниз. -->
                <div class="max-h-[calc(100vh-15rem)] overflow-auto">
                    <table class="min-w-full text-xs">
                        <thead class="sticky top-0 z-30">
                            <tr class="border-b border-slate-200 bg-slate-50">
                                <th class="sticky left-0 z-40 min-w-[13rem] bg-slate-50 px-4 py-2.5 text-left align-bottom text-xs font-medium uppercase tracking-wide text-slate-400 shadow-[1px_0_0_0_rgb(226_232_240)]">{{ $e('Раздел') }}</th>
                                <!-- Колонка роли: имя, меню «⋯», стопка
                                     носителей и круглая «+». Действия иконками:
                                     у десяти ролей подписи повторяли одно и то
                                     же в каждой колонке. Что делает кнопка,
                                     говорит подсказка при наведении. -->
                                <th v-for="r in roles" :key="r.name" class="min-w-[10rem] px-2.5 py-2 align-top">
                                    <div class="flex flex-col items-center gap-1">
                                        <div class="relative flex items-baseline gap-1">
                                            <span class="text-xs font-medium leading-tight" :class="r.locked ? 'text-slate-400' : 'text-slate-700'">{{ r.label }}</span>

                                            <!-- Меню роли: пять действий в одном
                                                 месте. Разложи их кнопками по
                                                 шапке — на десяти колонках это
                                                 полсотни кнопок, которых никто
                                                 не читает. -->
                                            <button v-if="!r.locked" type="button" @click="toggleMenu(r, $event)"
                                                :title="$e('Настройки роли')"
                                                class="rounded px-1 text-sm leading-none text-slate-300 transition-colors duration-150 hover:text-slate-600">⋯</button>
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
                                                <span v-if="r.holders.length > 4" class="ml-1.5 text-xs font-semibold text-slate-500">+{{ r.holders.length - 4 }}</span>
                                            </button>

                                            <button v-if="!r.locked" type="button" @click="openPicker(r)"
                                                :title="$e('Добавить сотрудника или отдел')"
                                                class="flex h-6 w-6 flex-shrink-0 items-center justify-center rounded-full border border-indigo-200 text-indigo-600 transition-colors duration-150 hover:border-indigo-400 hover:bg-indigo-50">
                                                <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
                                            </button>
                                        </div>

                                        <span v-if="r.locked" class="text-xs font-normal text-slate-300">{{ $e('полный доступ') }}</span>
                                    </div>
                                </th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-slate-50">
                            <!-- Признаки роли: чем роль ЯВЛЯЕТСЯ. Их не выразить
                                 областью — «видит суммы» не про число записей. -->
                            <tr v-for="(label, key) in traitLabels" :key="key" class="bg-indigo-50">
                                <td class="sticky left-0 z-10 bg-indigo-50 px-4 py-1.5 text-xs font-medium text-slate-600 shadow-[1px_0_0_0_rgb(226_232_240)]">{{ $e(label) }}</td>
                                <td v-for="r in roles" :key="r.name" class="px-2.5 py-1 text-center">
                                    <input v-if="!r.locked && traits[r.name]" type="checkbox" v-model="traits[r.name][key]"
                                        class="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-600" />
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
                                                <span class="text-xs font-semibold text-slate-800">{{ $e(m.label) }}</span>
                                                <span v-if="m.hint" class="ml-1.5 text-xs font-normal text-slate-400">{{ $e(m.hint) }}</span>
                                            </span>
                                        </button>
                                    </td>
                                    <td v-for="r in roles" :key="r.name" class="px-2.5 py-1.5 text-center">
                                        <select v-if="!r.locked" :value="moduleScope(r.name, m)" @change="setModule(r.name, m, $event.target.value)"
                                            class="w-full rounded-md border py-0.5 pl-1.5 pr-5 text-xs font-semibold shadow-sm focus:border-indigo-600 focus:ring-2 focus:ring-indigo-600/20"
                                            :class="scopeClass(moduleScope(r.name, m))">
                                            <option v-if="moduleScope(r.name, m) === 'mixed'" value="mixed">{{ $e('по действиям') }}</option>
                                            <option v-for="s in scopeLevels" :key="s.value" :value="s.value">{{ $e(s.label) }}</option>
                                        </select>
                                        <span v-else class="text-xs font-semibold text-emerald-600/45">{{ $e('Все') }}</span>
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
                                        <select v-if="!r.locked && m.permissions[key] && draft[r.name]" v-model="draft[r.name][m.permissions[key]]"
                                            class="w-full rounded-md border py-0.5 pl-1.5 pr-5 text-xs shadow-sm focus:border-indigo-600 focus:ring-2 focus:ring-indigo-600/20"
                                            :class="scopeClass(draft[r.name][m.permissions[key]])">
                                            <option v-for="s in scopeLevels" :key="s.value" :value="s.value">{{ $e(s.label) }}</option>
                                        </select>
                                        <span v-else-if="r.locked && m.permissions[key]" class="text-xs text-emerald-600/45">{{ $e('Все') }}</span>
                                        <span v-else class="text-slate-200">—</span>
                                    </td>
                                </tr>
                            </template>
                        </tbody>

                        <tfoot class="sticky bottom-0 z-30 border-t border-slate-200 bg-slate-50">
                            <tr>
                                <td class="sticky left-0 z-40 bg-slate-50 px-4 py-2.5 text-xs text-slate-400 shadow-[1px_0_0_0_rgb(226_232_240)]">{{ $e('Сохраняется по одной роли') }}</td>
                                <td v-for="r in roles" :key="r.name" class="px-2.5 py-2 text-center">
                                    <button v-if="!r.locked" type="button" :disabled="!dirty(r.name) || busy === r.name" @click="save(r.name)"
                                        class="rounded-lg px-2.5 py-1 text-xs font-semibold transition-colors duration-150"
                                        :class="dirty(r.name) ? 'bg-indigo-600 text-white hover:bg-indigo-700' : 'bg-slate-100 text-slate-300'">
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
        <div v-if="menuFor" class="fixed inset-0 z-30" @click="menuForName = null"></div>

        <!-- Меню роли — телепортом в body и фиксированно по кнопке: внутри
             таблицы его обрезал бы `overflow-x-auto`. -->
        <Teleport to="body">
            <div v-if="menuFor" class="fixed z-40 w-64 -translate-x-1/2 overflow-hidden rounded-xl border border-slate-200/60 bg-white text-left shadow-lg"
                :style="{ top: menuAt.top + 'px', left: menuAt.left + 'px' }">
                <button type="button" @click="setAll(menuFor, 'all')" class="flex w-full gap-2.5 px-3 py-2.5 text-left transition-colors duration-150 hover:bg-slate-50">
                    <span class="mt-0.5 text-emerald-600">✓</span>
                    <span>
                        <span class="block text-xs font-semibold text-slate-900">{{ $e('Открыть доступ ко всем') }}</span>
                        <span class="block text-xs font-normal text-slate-400">{{ $e('Роль получит область «Все» во всех разделах') }}</span>
                    </span>
                </button>
                <button type="button" @click="setAll(menuFor, 'none')" class="flex w-full gap-2.5 border-t border-slate-100 px-3 py-2.5 text-left transition-colors duration-150 hover:bg-slate-50">
                    <span class="mt-0.5 text-rose-500">🔒</span>
                    <span>
                        <span class="block text-xs font-semibold text-slate-900">{{ $e('Закрыть доступ ко всем') }}</span>
                        <span class="block text-xs font-normal text-slate-400">{{ $e('Все права роли снимаются разом') }}</span>
                    </span>
                </button>
                <button type="button" @click="openRename(menuFor)" class="flex w-full gap-2.5 border-t border-slate-100 px-3 py-2.5 text-left transition-colors duration-150 hover:bg-slate-50">
                    <span class="mt-0.5 text-indigo-600">✎</span>
                    <span>
                        <span class="block text-xs font-semibold text-slate-900">{{ $e('Переименовать') }}</span>
                        <span class="block text-xs font-normal text-slate-400">{{ $e('Меняется подпись, код роли остаётся') }}</span>
                    </span>
                </button>
                <button type="button" @click="openRole(menuFor)" class="flex w-full gap-2.5 border-t border-slate-100 px-3 py-2.5 text-left transition-colors duration-150 hover:bg-slate-50">
                    <span class="mt-0.5 text-indigo-600">⧉</span>
                    <span>
                        <span class="block text-xs font-semibold text-slate-900">{{ $e('Скопировать') }}</span>
                        <span class="block text-xs font-normal text-slate-400">{{ $e('В новую роль перейдут все области этой') }}</span>
                    </span>
                </button>
                <button type="button" @click="removeRole(menuFor)"
                    class="flex w-full gap-2.5 border-t border-slate-100 px-3 py-2.5 text-left transition-colors duration-150 hover:bg-rose-50">
                    <span class="mt-0.5 text-rose-500">🗑</span>
                    <span>
                        <span class="block text-xs font-semibold text-slate-900">{{ $e('Удалить') }}</span>
                        <span class="block text-xs font-normal text-slate-400">
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
                    class="w-full rounded-lg border-slate-200 text-sm shadow-sm focus:border-indigo-600 focus:ring-2 focus:ring-indigo-600/20" />
                <p v-if="renameForm.errors.label" class="mt-1 text-xs text-rose-600">{{ renameForm.errors.label }}</p>
                <div class="mt-5 flex justify-end gap-2">
                    <button @click="renamingRole = null" class="rounded-lg px-4 py-2 text-sm font-medium text-slate-500 hover:text-slate-700">{{ $e('Отмена') }}</button>
                    <button :disabled="renameForm.processing" @click="submitRename"
                        class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition-colors duration-150 hover:bg-indigo-700 disabled:opacity-50">{{ $e('Сохранить') }}</button>
                </div>
            </div>
        </Modal>

        <PeoplePicker :show="!!pickerFor" :title="pickerFor ? $e('Роль') + ' «' + pickerFor.label + '»' : ''"
            :people="people" :departments="departments" :roles="roles"
            :members="pickerFor?.holders ?? []"
            :selected="pickerFor ? pickerFor.holders.map((h) => h.id) : []"
            @close="pickerForName = null" @pick="addPeople"
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
                        <p class="mt-1 text-xs text-slate-400">{{ $e('Код не меняется потом: на нём держатся правила. Название переименовать можно всегда.') }}</p>
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
    </SettingsLayout>
</template>
