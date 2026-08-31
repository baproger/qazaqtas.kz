<script setup>
/**
 * Bento-сетка объектов: 4 колонки на десктопе, 1 — на телефоне.
 *
 *   ┌───────────┬─────┬─────┐   1 — главный объект (2×2)
 *   │     1     │  2  │  4  │   2 — вертикальная карточка (1×2)
 *   │           │     │  5  │   4, 5 — обычные (1×1)
 *   └───────────┴─────┴─────┘   (цифры и призыв убраны: CTA уже идёт ниже)
 *
 * Данные те же, что были: объекты из ERP. Карточка 3 считается из них же —
 * отдельного хранилища для цифр не заводим.
 */
import { computed } from 'vue';

const props = defineProps({
    projects: { type: Array, default: () => [] },
    /** Подпись «м²» и прочие тексты берём через $t в шаблоне. */
});

const top = computed(() => props.projects[0] ?? null);
const tall = computed(() => props.projects[1] ?? null);
const small = computed(() => props.projects.slice(2, 4));

</script>

<template>
    <div class="grid grid-cols-1 gap-4 md:grid-cols-4 md:auto-rows-[minmax(200px,auto)] lg:gap-6">
        <!-- 1. Главный объект 2×2 -->
        <article v-if="top" class="card card-hover reveal group relative min-h-[320px] overflow-hidden rounded-3xl md:col-span-2 md:row-span-2 md:min-h-0">
            <img v-if="top.image" :src="top.image" :srcset="top.thumb ? `${top.thumb} 600w, ${top.image} 1600w` : undefined"
                sizes="(max-width: 768px) 100vw, 50vw" :alt="top.title" loading="lazy" decoding="async"
                class="absolute inset-0 h-full w-full object-cover transition duration-700 ease-premium group-hover:scale-105" />
            <div v-else class="paving-pattern absolute inset-0" />
            <div class="pointer-events-none absolute inset-0 bg-gradient-to-t from-black/75 via-black/30 to-transparent" />
            <div class="relative flex h-full flex-col justify-end p-7 sm:p-9">
                <p class="text-xs uppercase tracking-[0.24em] text-white/70">{{ top.city }}<template v-if="top.year"> · {{ top.year }}</template></p>
                <h3 class="display mt-3 text-3xl text-white sm:text-4xl">{{ top.title }}</h3>
                <p v-if="top.products" class="mt-3 max-w-md text-sm leading-relaxed text-white/75">{{ top.products }}</p>
                <p v-if="top.area" class="display mt-5 text-4xl text-white sm:text-5xl">{{ top.area }}</p>
            </div>
        </article>

        <!-- 2. Вертикальная 1×2 -->
        <article v-if="tall" class="card card-hover reveal group relative min-h-[320px] overflow-hidden rounded-3xl md:col-span-1 md:row-span-2 md:min-h-0">
            <img v-if="tall.image" :src="tall.image" :srcset="tall.thumb ? `${tall.thumb} 600w, ${tall.image} 1600w` : undefined"
                sizes="(max-width: 768px) 100vw, 25vw" :alt="tall.title" loading="lazy" decoding="async"
                class="absolute inset-0 h-full w-full object-cover transition duration-700 ease-premium group-hover:scale-105" />
            <div v-else class="paving-pattern absolute inset-0" />
            <div class="pointer-events-none absolute inset-0 bg-gradient-to-t from-black/70 via-black/25 to-transparent" />
            <div class="relative flex h-full flex-col justify-end p-6 sm:p-7">
                <p class="text-xs uppercase tracking-[0.24em] text-white/70">{{ tall.city }}<template v-if="tall.year"> · {{ tall.year }}</template></p>
                <h3 class="display mt-2 text-2xl text-white">{{ tall.title }}</h3>
                <p v-if="tall.area" class="display mt-3 text-3xl text-white">{{ tall.area }}</p>
            </div>
        </article>

        <!-- 4, 5. Обычные 1×1 -->
        <article v-for="p in small" :key="p.title" class="card card-hover reveal group relative min-h-[220px] overflow-hidden rounded-3xl md:col-span-1 md:row-span-1 md:min-h-0">
            <img v-if="p.image" :src="p.thumb || p.image" :alt="p.title" loading="lazy" decoding="async"
                class="absolute inset-0 h-full w-full object-cover transition duration-700 ease-premium group-hover:scale-105" />
            <div v-else class="paving-pattern absolute inset-0" />
            <div class="pointer-events-none absolute inset-0 bg-gradient-to-t from-black/70 via-black/25 to-transparent" />
            <div class="relative flex h-full flex-col justify-end p-6">
                <p class="text-xs uppercase tracking-[0.24em] text-white/70">{{ p.city }}<template v-if="p.year"> · {{ p.year }}</template></p>
                <h3 class="display mt-2 text-xl text-white">{{ p.title }}</h3>
                <p v-if="p.area" class="mt-1 text-sm text-white/75">{{ p.area }}</p>
            </div>
        </article>
    </div>
</template>
