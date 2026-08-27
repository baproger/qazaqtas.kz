<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { useE } from '@/composables/useTranslations';

const tr = useE();

// Выбор сотрудника для фильтров: менеджеры отдела продаж всегда сверху и
// развёрнуты, остальные — по отделам, свёрнуты (раскрываются кликом).
const props = defineProps({
    users: { type: Array, default: () => [] }, // [{id, name, is_manager, department}]
    modelValue: { type: [String, Number], default: '' },
    placeholder: { type: String, default: 'Все менеджеры' },
    width: { type: String, default: 'w-full sm:w-52' },
});
const emit = defineEmits(['update:modelValue', 'change']);

const open = ref(false);
const openDepts = ref(new Set());
const managers = computed(() => props.users.filter((u) => u.is_manager));
const groups = computed(() => {
    const g = {};
    props.users.filter((u) => !u.is_manager).forEach((u) => {
        const k = u.department ?? tr('Без отдела');
        (g[k] ??= []).push(u);
    });
    return Object.entries(g).map(([name, items]) => ({ name, items }))
        .sort((a, b) => (a.name === 'Без отдела') - (b.name === 'Без отдела') || a.name.localeCompare(b.name, 'ru'));
});
const toggleDept = (name) => { const s = new Set(openDepts.value); s.has(name) ? s.delete(name) : s.add(name); openDepts.value = s; };
const selectedName = computed(() => props.users.find((u) => u.id === Number(props.modelValue))?.name ?? tr(props.placeholder));
const pick = (id) => { emit('update:modelValue', id); emit('change'); open.value = false; };
const onDocClick = (e) => { if (!e.target.closest?.('.mgr-picker')) open.value = false; };
onMounted(() => document.addEventListener('click', onDocClick));
onUnmounted(() => document.removeEventListener('click', onDocClick));
</script>

<template>
    <div class="mgr-picker relative" :class="width">
        <button type="button" @click="open = !open"
            class="flex w-full items-center justify-between gap-2 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-sm text-slate-600 shadow-sm">
            <span class="truncate">{{ selectedName }}</span>
            <span class="text-slate-400">{{ open ? '▲' : '▼' }}</span>
        </button>
        <div v-if="open" class="absolute z-30 mt-1 max-h-80 w-64 overflow-y-auto rounded-xl border border-slate-100 bg-white py-1 shadow-lg">
            <button type="button" @click="pick('')"
                class="block w-full px-3 py-1.5 text-left text-sm hover:bg-indigo-50" :class="!modelValue ? 'font-semibold text-indigo-600' : 'text-slate-700'">{{ $e(placeholder) }}</button>
            <div class="mt-1 border-t border-slate-100 px-3 pb-1 pt-2 text-[10px] font-bold uppercase tracking-wide text-slate-400">{{ $e('Менеджеры (отдел продаж)') }}</div>
            <button v-for="m in managers" :key="m.id" type="button" @click="pick(m.id)"
                class="block w-full px-3 py-1.5 text-left text-sm hover:bg-indigo-50"
                :class="Number(modelValue) === m.id ? 'font-semibold text-indigo-600' : 'text-slate-700'">{{ m.name }}</button>
            <div v-if="!managers.length" class="px-3 py-1.5 text-xs text-slate-400">{{ $e('Нет менеджеров') }}</div>
            <!-- Остальные отделы: свёрнуты, раскрываются кликом -->
            <template v-for="g in groups" :key="g.name">
                <button type="button" @click="toggleDept(g.name)"
                    class="mt-1 flex w-full items-center justify-between border-t border-slate-100 px-3 pb-1 pt-2 text-[10px] font-bold uppercase tracking-wide text-slate-400 hover:text-slate-600">
                    {{ g.name }} ({{ g.items.length }})
                    <span>{{ openDepts.has(g.name) ? '▲' : '▼' }}</span>
                </button>
                <template v-if="openDepts.has(g.name)">
                    <button v-for="m in g.items" :key="m.id" type="button" @click="pick(m.id)"
                        class="block w-full px-3 py-1.5 text-left text-sm hover:bg-indigo-50"
                        :class="Number(modelValue) === m.id ? 'font-semibold text-indigo-600' : 'text-slate-700'">{{ m.name }}</button>
                </template>
            </template>
        </div>
    </div>
</template>
