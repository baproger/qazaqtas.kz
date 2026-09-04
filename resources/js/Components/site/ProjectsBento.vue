<script setup>
/**
 * Bento-сетка объектов — на ЛЮБОЕ количество карточек.
 *
 * Раскладка повторяется суперциклом из десяти БЕЗ мелких карточек 1×1 —
 * объекты подаются крупно (просьба владельца: «больше большой блок»):
 *
 *   ┌───────────┬─────┬─────┐   0 — большая 2×2, 1-2 — высокие 1×2
 *   │     0     │  1  │  2  │
 *   │           │     │     │   3-4 — широкие 2×1
 *   ├───────────┴──┬──┴─────┤
 *   │      3       │   4    │   5-7 — высокая, БОЛЬШАЯ ПО ЦЕНТРУ, высокая
 *   ├─────┬────────┴──┬─────┤
 *   │  5  │     6     │  7  │   8-9 — широкие 2×1
 *   │     │           │     │
 *   ├─────┴─────┬─────┴─────┤
 *   │     8     │     9     │
 *   └───────────┴───────────┘
 *
 * Порядок в DOM подобран под построчный автоплейсмент грида — дыр не
 * остаётся. Первые четыре позиции совпадают с прежней раскладкой:
 * главная, передающая 4 объекта, выглядит как раньше.
 */
const props = defineProps({
    projects: { type: Array, default: () => [] },
});

/** Размер по месту в суперцикле: lg 2×2 · tall 1×2 · wide 2×1. */
const SIZES = ['lg', 'tall', 'tall', 'wide', 'wide', 'tall', 'lg', 'tall', 'wide', 'wide'];
const sizeFor = (i) => SIZES[i % SIZES.length];

const SPAN = {
    lg: 'min-h-[320px] md:col-span-2 md:row-span-2',
    tall: 'min-h-[320px] md:col-span-1 md:row-span-2',
    wide: 'min-h-[220px] md:col-span-2 md:row-span-1',
    sm: 'min-h-[220px] md:col-span-1 md:row-span-1',
};
const PAD = { lg: 'p-7 sm:p-9', tall: 'p-6 sm:p-7', wide: 'p-6', sm: 'p-6' };
const TITLE = { lg: 'mt-3 text-3xl sm:text-4xl', tall: 'mt-2 text-2xl', wide: 'mt-2 text-xl', sm: 'mt-2 text-xl' };
const AREA = { lg: 'display mt-5 text-4xl sm:text-5xl', tall: 'display mt-3 text-3xl', wide: 'display mt-3 text-2xl', sm: 'mt-1 text-sm text-white/75' };
</script>

<template>
    <div class="grid grid-cols-1 gap-4 md:grid-cols-4 md:auto-rows-[minmax(200px,auto)] lg:gap-6">
        <article v-for="(p, i) in projects" :key="p.title + i"
            class="card card-hover reveal group relative overflow-hidden rounded-3xl md:min-h-0"
            :class="SPAN[sizeFor(i)]">
            <img v-if="p.image" :src="sizeFor(i) === 'sm' ? (p.thumb || p.image) : p.image"
                :srcset="p.thumb && sizeFor(i) !== 'sm' ? `${p.thumb} 600w, ${p.image} 1600w` : undefined"
                :sizes="sizeFor(i) === 'lg' || sizeFor(i) === 'wide' ? '(max-width: 768px) 100vw, 50vw' : '(max-width: 768px) 100vw, 25vw'"
                :alt="p.title" loading="lazy" decoding="async"
                class="absolute inset-0 h-full w-full object-cover transition duration-700 ease-premium group-hover:scale-105" />
            <div v-else class="paving-pattern absolute inset-0" />
            <div class="pointer-events-none absolute inset-0 bg-gradient-to-t from-black/75 via-black/25 to-transparent" />
            <div class="relative flex h-full flex-col justify-end" :class="PAD[sizeFor(i)]">
                <p class="text-xs uppercase tracking-[0.24em] text-white/70">{{ p.city }}<template v-if="p.year"> · {{ p.year }}</template></p>
                <h3 class="display text-white" :class="TITLE[sizeFor(i)]">{{ p.title }}</h3>
                <p v-if="p.products" class="mt-2 max-w-md text-sm leading-relaxed text-white/70">{{ p.products }}</p>
                <p v-if="p.area" class="text-white" :class="AREA[sizeFor(i)]">{{ p.area }}</p>
            </div>
        </article>
    </div>
</template>
