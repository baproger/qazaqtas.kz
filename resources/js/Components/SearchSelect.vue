<script setup>
import { ref, computed, watch, nextTick, onMounted, onUnmounted } from 'vue';

// Выпадающий список с поиском внутри: печатаешь — варианты фильтруются.
const props = defineProps({
    modelValue: { type: [String, Number], default: '' },
    options: { type: Array, default: () => [] }, // [{ id, name }]
    placeholder: { type: String, default: 'Все' },
    width: { type: String, default: 'w-48' },
});
const emit = defineEmits(['update:modelValue', 'change']);

const open = ref(false);
const query = ref('');
const root = ref(null);
const searchInput = ref(null);

const selected = computed(() => props.options.find((o) => String(o.id) === String(props.modelValue)) ?? null);
const filtered = computed(() => {
    const q = query.value.trim().toLowerCase();
    return q ? props.options.filter((o) => (o.name ?? '').toLowerCase().includes(q)) : props.options;
});

const pick = (id) => {
    emit('update:modelValue', id);
    emit('change');
    open.value = false;
    query.value = '';
};
watch(open, async (v) => { if (v) { query.value = ''; await nextTick(); searchInput.value?.focus(); } });
const onDocClick = (e) => { if (root.value && !root.value.contains(e.target)) open.value = false; };
onMounted(() => document.addEventListener('click', onDocClick));
onUnmounted(() => document.removeEventListener('click', onDocClick));
</script>

<template>
    <div ref="root" class="relative" :class="width">
        <button type="button" @click="open = !open"
            class="flex w-full items-center justify-between gap-2 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-sm shadow-sm transition focus:border-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-500/20"
            :class="selected ? 'text-slate-800' : 'text-slate-500'">
            <span class="truncate">{{ selected?.name ?? placeholder }}</span>
            <svg class="h-4 w-4 flex-shrink-0 text-slate-400 transition-transform" :class="open ? 'rotate-180' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"/></svg>
        </button>

        <div v-if="open" class="absolute left-0 top-full z-30 mt-1 w-full min-w-52 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-lg">
            <div class="border-b border-slate-100 p-1.5">
                <input ref="searchInput" v-model="query" type="text" placeholder="Поиск…" @click.stop @keydown.escape="open = false"
                    class="w-full rounded-md border-slate-200 px-2 py-1 text-xs focus:border-indigo-400 focus:ring-1 focus:ring-indigo-400" />
            </div>
            <div class="max-h-56 overflow-y-auto py-1 text-sm">
                <button type="button" @click="pick('')"
                    class="block w-full px-3 py-1.5 text-left text-slate-500 transition-colors hover:bg-slate-50">{{ placeholder }}</button>
                <button v-for="o in filtered" :key="o.id" type="button" @click="pick(o.id)"
                    class="block w-full truncate px-3 py-1.5 text-left transition-colors hover:bg-indigo-50"
                    :class="String(o.id) === String(modelValue) ? 'bg-indigo-50 font-medium text-indigo-700' : 'text-slate-700'">{{ o.name }}</button>
                <div v-if="!filtered.length" class="px-3 py-2 text-xs text-slate-400">Не найдено</div>
            </div>
        </div>
    </div>
</template>
