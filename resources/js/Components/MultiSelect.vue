<script setup>
/**
 * Мультивыбор в стиле glassmorphism: закрытый — показывает выбранное
 * чипами, открытый — стеклянная панель со списком и поиском.
 * v-model — массив значений; options — [{ value, label }].
 */
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';

const props = defineProps({
    modelValue: { type: Array, default: () => [] },
    options: { type: Array, default: () => [] },
    placeholder: { type: String, default: '' },
    emptyLabel: { type: String, default: '' },
});
const emit = defineEmits(['update:modelValue']);

const open = ref(false);
const q = ref('');
const root = ref(null);

const selected = computed(() => props.options.filter((o) => props.modelValue.includes(o.value)));
const filtered = computed(() => {
    const s = q.value.trim().toLowerCase();
    return s ? props.options.filter((o) => String(o.label).toLowerCase().includes(s)) : props.options;
});
const toggle = (v) => {
    const next = props.modelValue.includes(v) ? props.modelValue.filter((x) => x !== v) : [...props.modelValue, v];
    emit('update:modelValue', next);
};
const clear = () => emit('update:modelValue', []);
const onDoc = (e) => { if (root.value && !root.value.contains(e.target)) open.value = false; };
onMounted(() => document.addEventListener('mousedown', onDoc));
onBeforeUnmount(() => document.removeEventListener('mousedown', onDoc));
</script>

<template>
    <div ref="root" class="relative">
        <button type="button" @click="open = !open"
            class="flex min-h-[2.5rem] w-full items-center gap-1.5 rounded-xl border border-white/60 bg-white/70 px-3 py-1.5 text-left text-sm shadow-soft backdrop-blur transition hover:bg-white/90 focus:outline-none focus:ring-2 focus:ring-indigo-400 dark:border-slate-800/80 dark:bg-slate-900/70 dark:hover:bg-slate-900/90"
            :class="open ? 'ring-2 ring-indigo-400' : ''">
            <span class="flex min-w-0 flex-1 flex-wrap gap-1">
                <template v-if="selected.length">
                    <span v-for="o in selected" :key="o.value" class="inline-flex items-center gap-1 rounded-full bg-indigo-600/90 px-2 py-0.5 text-xs font-medium text-white">
                        {{ o.label }}
                        <span class="cursor-pointer opacity-70 hover:opacity-100" @click.stop="toggle(o.value)">×</span>
                    </span>
                </template>
                <span v-else class="text-slate-400">{{ emptyLabel || placeholder }}</span>
            </span>
            <svg class="h-4 w-4 shrink-0 text-slate-400 transition-transform" :class="open ? 'rotate-180' : ''" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M5 8l5 5 5-5"/></svg>
        </button>

        <transition enter-active-class="transition duration-150 ease-out" enter-from-class="opacity-0 -translate-y-1 scale-[0.98]"
            leave-active-class="transition duration-100 ease-out" leave-to-class="opacity-0 -translate-y-1 scale-[0.98]">
            <div v-if="open" class="absolute left-0 right-0 z-30 mt-1.5 overflow-hidden rounded-xl border border-white/60 bg-white/80 shadow-soft-lg backdrop-blur-xl dark:border-slate-800/80 dark:bg-slate-900/70">
                <div v-if="options.length > 6" class="border-b border-slate-100/80 p-2 dark:border-slate-800">
                    <input v-model="q" type="search" :placeholder="placeholder" class="w-full rounded-lg border-0 bg-slate-100/70 px-2.5 py-1.5 text-sm focus:ring-2 focus:ring-indigo-300" />
                </div>
                <ul class="max-h-56 overflow-y-auto p-1">
                    <li v-for="o in filtered" :key="o.value">
                        <button type="button" @click="toggle(o.value)"
                            class="flex w-full items-center gap-2.5 rounded-lg px-2.5 py-1.5 text-left text-sm transition hover:bg-indigo-50/80 dark:hover:bg-indigo-500/10">
                            <span class="flex h-4 w-4 shrink-0 items-center justify-center rounded border transition"
                                :class="modelValue.includes(o.value) ? 'border-indigo-600 bg-indigo-600 text-white' : 'border-slate-300 bg-white dark:border-slate-600 dark:bg-slate-800'">
                                <svg v-if="modelValue.includes(o.value)" class="h-3 w-3" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M4 10l4 4 8-8"/></svg>
                            </span>
                            <span class="truncate" :class="modelValue.includes(o.value) ? 'font-medium text-slate-900 dark:text-slate-100' : 'text-slate-700 dark:text-slate-300'">{{ o.label }}</span>
                        </button>
                    </li>
                    <li v-if="!filtered.length" class="px-2.5 py-2 text-xs text-slate-400">—</li>
                </ul>
                <div v-if="modelValue.length" class="border-t border-slate-100/80 px-2 py-1.5 text-right dark:border-slate-800">
                    <button type="button" @click="clear" class="text-xs font-medium text-slate-500 hover:text-rose-600 dark:text-slate-400 dark:hover:text-rose-400">{{ emptyLabel ? '× ' + emptyLabel : '×' }}</button>
                </div>
            </div>
        </transition>
    </div>
</template>
