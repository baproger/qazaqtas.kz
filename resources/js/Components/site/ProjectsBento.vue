<script setup>
/**
 * Bento-сетка объектов — на ЛЮБОЕ количество карточек.
 *
 * Раскладка повторяется модулем из семи (4 колонки на десктопе):
 *
 *   ┌───────────┬─────┬─────┐   0 — большая 2×2, 1 — высокая 1×2,
 *   │     0     │  1  │  2  │   2, 3 — обычные 1×1
 *   │           │     │  3  │
 *   ├───────────┼─────┴─────┤   4 — широкая 2×1, 5, 6 — обычные
 *   │     4     │  5  │  6  │
 *   └───────────┴─────┴─────┘
 *
 * В каждом втором цикле большая и высокая меняются местами — ритм
 * сохраняется, но страница не выглядит отштампованной. Первые четыре
 * позиции совпадают с прежней раскладкой: главная, передающая 4 объекта,
 * выглядит как раньше.
 */
const props = defineProps({
    projects: { type: Array, default: () => [] },
});

/** Размер по месту в цикле: lg 2×2 · tall 1×2 · wide 2×1 · sm 1×1. */
const sizeFor = (i) => {
    const pos = i % 7;
    const mirrored = Math.floor(i / 7) % 2 === 1;
    if (pos === 0) return mirrored ? 'tall' : 'lg';
    if (pos === 1) return mirrored ? 'lg' : 'tall';
    return pos === 4 ? 'wide' : 'sm';
};

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
                <p v-if="p.products && sizeFor(i) !== 'sm'" class="mt-2 max-w-md text-sm leading-relaxed text-white/70">{{ p.products }}</p>
                <p v-if="p.area" class="text-white" :class="AREA[sizeFor(i)]">{{ p.area }}</p>
            </div>
        </article>
    </div>
</template>
