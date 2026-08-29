<script setup>
/**
 * Плитка-итог денежных страниц.
 *
 * До неё каждая страница рисовала плитки сама: где-то rounded-2xl и text-xl,
 * где-то rounded-2xl и text-2xl. Рядом это читалось как разные разделы разных
 * систем — теперь цифра, подпись и рамка везде одни.
 */
defineProps({
    label: { type: String, default: '' },
    value: { type: String, default: '' },
    hint: { type: String, default: '' },
    // default — обычная, good — приход, bad — расход/долг, warn — ожидание,
    // dark — итоговая (тёмная плитка «Доступно сейчас» / «Чистая прибыль»).
    tone: { type: String, default: 'default' },
});

const BOX = {
    default: 'border border-slate-200 bg-white',
    good: 'border border-slate-200 bg-white',
    bad: 'border border-rose-200 bg-rose-50',
    warn: 'border border-amber-200 bg-amber-50',
    dark: 'border border-transparent',
};
const VALUE = {
    default: 'text-slate-800',
    good: 'text-emerald-600',
    bad: 'text-rose-600',
    warn: 'text-amber-700',
    dark: 'text-emerald-300',
};
const LABEL = {
    default: 'text-slate-400',
    good: 'text-slate-400',
    bad: 'text-rose-500',
    warn: 'text-amber-600',
    dark: 'text-white/60',
};
</script>

<template>
    <div class="rounded-2xl p-4 shadow-soft-md" :class="BOX[tone]" :style="tone === 'dark' ? 'background-color: #1A3B5C' : ''">
        <div class="text-xs uppercase tracking-wide" :class="LABEL[tone]">{{ label }}</div>
        <div class="mt-1 whitespace-nowrap text-xl font-bold tabular-nums" :class="VALUE[tone]">{{ value }}</div>
        <div v-if="hint" class="mt-0.5 text-xs" :class="LABEL[tone]">{{ hint }}</div>
        <slot />
    </div>
</template>
