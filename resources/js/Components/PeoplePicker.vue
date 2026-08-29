<script setup>
/**
 * Выбор людей: вкладками «Последние / Отделы / Роли».
 *
 * Плоский список из сорока фамилий не выбирают — в нём ищут. Вкладки дают три
 * способа попасть в нужного человека: недавние, по отделу и по роли. Отдел и
 * роль добавляют сразу всех своих: назначить право отделу из восьми человек
 * поштучно значит восемь раз повторить одно решение.
 *
 * «Проекты» из образца нет намеренно: проект в этой системе — заказ цеха, и
 * прав он не носит. Вкладка, которая ничего не выбирает, только сбивает.
 */
import { computed, ref, watch } from 'vue';
import Avatar from '@/Components/Avatar.vue';
import Modal from '@/Components/Modal.vue';
import { useE } from '@/composables/useTranslations';

const tr = useE();

const props = defineProps({
    show: { type: Boolean, default: false },
    title: { type: String, default: '' },
    people: { type: Array, default: () => [] },
    departments: { type: Array, default: () => [] },
    roles: { type: Array, default: () => [] },
    // Кто уже выбран — их показываем отмеченными, а не прячем: видно, что
    // человек в списке уже есть, и не приходится гадать.
    selected: { type: Array, default: () => [] },
    // Кто уже в составе: их показываем сверху с × — состав правится в ОДНОМ
    // месте, а не «добавить здесь, убрать там».
    members: { type: Array, default: () => [] },
});
const emit = defineEmits(['close', 'pick', 'remove']);

// Подписи переводим здесь, а не в шаблоне: `$e(t.label)` — динамический
// вызов, и сборщик словаря его не видит, поэтому перевод считался бы
// потерянным и уходил в русский запасной вариант.
const TABS = [
    { key: 'recent', label: tr('Последние') },
    { key: 'departments', label: tr('Отделы') },
    { key: 'roles', label: tr('Роли') },
];
const tab = ref('recent');
const search = ref('');
watch(() => props.show, (open) => { if (open) { tab.value = 'recent'; search.value = ''; } });

const has = (id) => props.selected.includes(id);
const match = (text) => String(text ?? '').toLowerCase().includes(search.value.trim().toLowerCase());

// «Последние» — просто все активные по алфавиту, пока история выбора не
// заведена: показать пустую вкладку хуже, чем показать всех.
const visiblePeople = computed(() => props.people.filter((p) => match(p.name)));

const groups = computed(() => {
    if (tab.value === 'departments') {
        return props.departments
            .map((d) => ({ id: 'd' + d.id, name: d.name, people: props.people.filter((p) => p.department_id === d.id) }))
            .filter((g) => g.people.length && (match(g.name) || g.people.some((p) => match(p.name))));
    }
    return props.roles
        .map((r) => ({ id: 'r' + r.name, name: r.label, people: props.people.filter((p) => (p.roles ?? []).includes(r.name)) }))
        .filter((g) => g.people.length && (match(g.name) || g.people.some((p) => match(p.name))));
});

const pick = (ids) => emit('pick', Array.isArray(ids) ? ids : [ids]);
</script>

<template>
    <Modal :show="show" max-width="lg" @close="emit('close')">
        <div class="p-6">
            <h2 class="mb-4 text-base font-semibold text-slate-900">{{ title || $e('Добавить сотрудника') }}</h2>

            <!-- Состав: кто уже здесь. Без этой строки после закрытия окна
                 приходилось бы гадать, кого ты только что добавил. -->
            <div v-if="members.length" class="mb-4 rounded-lg border border-slate-100 bg-slate-50/60 p-2.5">
                <div class="mb-1.5 text-xs uppercase tracking-wide text-slate-400">{{ $e('Сейчас в составе') }} · {{ members.length }}</div>
                <div class="flex flex-wrap gap-1">
                    <span v-for="m in members" :key="m.id"
                        class="inline-flex items-center gap-1 rounded-md bg-sky-50 px-1.5 py-0.5 text-xs font-medium text-sky-700">
                        {{ m.name }}
                        <button @click="emit('remove', m.id)" :title="$e('Убрать')"
                            class="text-sky-400 transition-colors duration-150 hover:text-rose-600">×</button>
                    </span>
                </div>
            </div>

            <div class="mb-3 flex gap-1 rounded-lg bg-slate-100 p-1">
                <button v-for="t in TABS" :key="t.key" type="button" @click="tab = t.key"
                    class="flex-1 rounded-md px-3 py-1.5 text-sm font-medium transition-colors duration-150"
                    :class="tab === t.key ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-700'">
                    {{ t.label }}
                </button>
            </div>

            <input v-model="search" type="text" :placeholder="$e('Поиск по имени…')"
                class="mb-3 w-full rounded-lg border-slate-200 text-sm shadow-sm focus:border-indigo-600 focus:ring-2 focus:ring-indigo-600/20" />

            <div class="max-h-80 space-y-1 overflow-y-auto pr-1">
                <template v-if="tab === 'recent'">
                    <button v-for="p in visiblePeople" :key="p.id" type="button" @click="pick(p.id)"
                        :disabled="has(p.id)"
                        class="flex w-full items-center gap-2.5 rounded-lg px-2 py-1.5 text-left transition-colors duration-150"
                        :class="has(p.id) ? 'opacity-40' : 'hover:bg-indigo-50'">
                        <Avatar :name="p.name" :src="p.avatar" :size="28" />
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm text-slate-800">{{ p.name }}</span>
                            <span class="block truncate text-xs text-slate-400">{{ p.department || $e('без отдела') }}</span>
                        </span>
                        <span v-if="has(p.id)" class="text-xs text-slate-400">{{ $e('уже есть') }}</span>
                    </button>
                    <p v-if="!visiblePeople.length" class="py-6 text-center text-sm text-slate-400">{{ $e('Никого не нашли') }}</p>
                </template>

                <template v-else>
                    <div v-for="g in groups" :key="g.id" class="rounded-lg border border-slate-100 p-2">
                        <div class="flex items-center gap-2">
                            <span class="min-w-0 flex-1 truncate text-sm font-semibold text-slate-800">{{ g.name }}</span>
                            <span class="text-xs text-slate-400">{{ g.people.length }}</span>
                            <button type="button" @click="pick(g.people.map((p) => p.id))"
                                class="rounded-md bg-indigo-50 px-2 py-1 text-xs font-semibold text-indigo-700 transition-colors duration-150 hover:bg-indigo-100">
                                {{ $e('добавить всех') }}
                            </button>
                        </div>
                        <div class="mt-1.5 flex flex-wrap gap-1">
                            <button v-for="p in g.people" :key="p.id" type="button" @click="pick(p.id)" :disabled="has(p.id)"
                                class="rounded-md px-2 py-1 text-xs transition-colors duration-150"
                                :class="has(p.id) ? 'bg-slate-100 text-slate-400' : 'bg-white text-slate-600 ring-1 ring-slate-200 hover:ring-indigo-400'">
                                {{ p.name }}
                            </button>
                        </div>
                    </div>
                    <p v-if="!groups.length" class="py-6 text-center text-sm text-slate-400">{{ $e('Никого не нашли') }}</p>
                </template>
            </div>

            <div class="mt-5 text-right">
                <button @click="emit('close')" class="rounded-lg px-4 py-2 text-sm font-medium text-slate-500 hover:text-slate-700">{{ $e('Закрыть') }}</button>
            </div>
        </div>
    </Modal>
</template>
