<script setup>
/**
 * Сворачиваемый блок карточки.
 *
 * Задачи, комментарии, фото и документы разворачивались всегда, и карточка
 * сделки уезжала на три экрана: чтобы посмотреть этап, менеджер прокручивал
 * мимо всего остального. Свёрнутые блоки держат главное — что делать, для
 * кого и на каком этапе — на первом экране, а счётчик в заголовке говорит,
 * есть ли внутри что-то, ради чего стоит разворачивать.
 */
import { ref } from 'vue';

const props = defineProps({
    title: String,
    count: { type: [Number, null], default: null },
    /** Открыт при первой отрисовке: так помечают то, что смотрят всегда. */
    open: { type: Boolean, default: false },
});

const isOpen = ref(props.open);
</script>

<template>
    <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-card">
        <button type="button" class="flex w-full items-center gap-2 px-5 py-3 text-left transition-colors duration-150 hover:bg-slate-50"
            :aria-expanded="isOpen" @click="isOpen = !isOpen">
            <slot name="icon" />
            <span class="text-sm font-semibold text-slate-900">{{ title }}</span>
            <span v-if="count !== null" class="rounded-full bg-indigo-50 px-2 py-0.5 text-xs font-semibold text-indigo-600">{{ count }}</span>
            <slot name="badge" />
            <svg class="ml-auto h-4 w-4 shrink-0 text-slate-400 transition-transform duration-200" :class="isOpen ? 'rotate-180' : ''"
                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
        </button>
        <div v-show="isOpen" class="border-t border-slate-100 px-5 py-4">
            <slot />
        </div>
    </div>
</template>
