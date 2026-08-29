<script setup>
/**
 * Bento-сетка объектов: 4 колонки на десктопе, 1 — на телефоне.
 *
 *   ┌───────────┬─────┬─────┐   1 — главный объект (2×2)
 *   │     1     │  2  │  4  │   2 — вертикальная карточка (1×2)
 *   │           │     │  5  │   4, 5 — обычные (1×1)
 *   ├───────────┼─────┴─────┤   3 — материалы и цифры (2×1)
 *   │     3     │     6     │   6 — призыв «посчитаем объект» (2×1)
 *
 * Данные те же, что были: объекты из ERP. Карточка 3 считается из них же —
 * отдельного хранилища для цифр не заводим.
 */
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    projects: { type: Array, default: () => [] },
    /** Подпись «м²» и прочие тексты берём через $t в шаблоне. */
});

const top = computed(() => props.projects[0] ?? null);
const tall = computed(() => props.projects[1] ?? null);
const small = computed(() => props.projects.slice(2, 4));

// Цифры по объектам: площадь суммируем из строк вида «4 200 м²».
const stats = computed(() => {
    const area = props.projects.reduce((sum, p) => sum + (parseInt(String(p.area ?? '').replace(/[^\d]/g, ''), 10) || 0), 0);
    const cities = [...new Set(props.projects.map((p) => p.city).filter(Boolean))];
    const products = [...new Set(props.projects.flatMap((p) => String(p.products ?? '').split(/[,;·]/).map((s) => s.trim()).filter(Boolean)))].slice(0, 6);
    return { count: props.projects.length, area, cities, products };
});
const fmt = (n) => new Intl.NumberFormat('ru-RU').format(n);
</script>

<template>
    <div class="grid grid-cols-1 gap-4 md:grid-cols-4 md:auto-rows-[minmax(200px,auto)] lg:gap-6">
        <!-- 1. Главный объект 2×2 -->
        <article v-if="top" class="card card-hover reveal group relative min-h-[320px] overflow-hidden rounded-3xl md:col-span-2 md:row-span-2 md:min-h-0">
            <img v-if="top.image" :src="top.image" :srcset="top.thumb ? `${top.thumb} 600w, ${top.image} 1600w` : undefined"
                sizes="(max-width: 768px) 100vw, 50vw" :alt="top.title" loading="lazy" decoding="async"
                class="absolute inset-0 h-full w-full object-cover transition duration-700 ease-premium group-hover:scale-105" />
            <div v-else class="paving-pattern absolute inset-0" />
            <div class="pointer-events-none absolute inset-0 bg-gradient-to-t from-ink-900 via-ink-900/40 to-transparent" />
            <div class="relative flex h-full flex-col justify-end p-7 sm:p-9">
                <p class="text-xs uppercase tracking-[0.24em] text-sand-300/70">{{ top.city }}<template v-if="top.year"> · {{ top.year }}</template></p>
                <h3 class="display mt-3 text-3xl text-sand-50 sm:text-4xl">{{ top.title }}</h3>
                <p v-if="top.products" class="mt-3 max-w-md text-sm leading-relaxed text-sand-100/60">{{ top.products }}</p>
                <p v-if="top.area" class="display mt-5 text-4xl text-sand-50 sm:text-5xl">{{ top.area }}</p>
            </div>
        </article>

        <!-- 2. Вертикальная 1×2 -->
        <article v-if="tall" class="card card-hover reveal group relative min-h-[320px] overflow-hidden rounded-3xl md:col-span-1 md:row-span-2 md:min-h-0">
            <img v-if="tall.image" :src="tall.image" :srcset="tall.thumb ? `${tall.thumb} 600w, ${tall.image} 1600w` : undefined"
                sizes="(max-width: 768px) 100vw, 25vw" :alt="tall.title" loading="lazy" decoding="async"
                class="absolute inset-0 h-full w-full object-cover transition duration-700 ease-premium group-hover:scale-105" />
            <div v-else class="paving-pattern absolute inset-0" />
            <div class="pointer-events-none absolute inset-0 bg-gradient-to-t from-ink-900 via-ink-900/30 to-transparent" />
            <div class="relative flex h-full flex-col justify-end p-6 sm:p-7">
                <p class="text-xs uppercase tracking-[0.24em] text-sand-300/70">{{ tall.city }}<template v-if="tall.year"> · {{ tall.year }}</template></p>
                <h3 class="display mt-2 text-2xl text-sand-50">{{ tall.title }}</h3>
                <p v-if="tall.area" class="display mt-3 text-3xl text-sand-50">{{ tall.area }}</p>
            </div>
        </article>

        <!-- 4, 5. Обычные 1×1 -->
        <article v-for="p in small" :key="p.title" class="card card-hover reveal group relative min-h-[220px] overflow-hidden rounded-3xl md:col-span-1 md:row-span-1 md:min-h-0">
            <img v-if="p.image" :src="p.thumb || p.image" :alt="p.title" loading="lazy" decoding="async"
                class="absolute inset-0 h-full w-full object-cover transition duration-700 ease-premium group-hover:scale-105" />
            <div v-else class="paving-pattern absolute inset-0" />
            <div class="pointer-events-none absolute inset-0 bg-gradient-to-t from-ink-900 via-ink-900/30 to-transparent" />
            <div class="relative flex h-full flex-col justify-end p-6">
                <p class="text-xs uppercase tracking-[0.24em] text-sand-300/70">{{ p.city }}<template v-if="p.year"> · {{ p.year }}</template></p>
                <h3 class="display mt-2 text-xl text-sand-50">{{ p.title }}</h3>
                <p v-if="p.area" class="mt-1 text-sm text-sand-100/60">{{ p.area }}</p>
            </div>
        </article>
        <!-- 3. Материалы и цифры 2×1 -->
        <article class="card reveal rounded-3xl p-7 sm:p-8 md:col-span-2 md:row-span-1">
            <p class="eyebrow">{{ $t('site.projects.bento_materials') }}</p>
            <div class="mt-4 grid grid-cols-2 gap-4">
                <div>
                    <p class="display text-3xl text-sand-50 sm:text-4xl">{{ stats.count }}</p>
                    <p class="mt-1 text-xs uppercase tracking-[0.2em] text-sand-300/60">{{ $t('site.projects.bento_objects') }}</p>
                </div>
                <div v-if="stats.area">
                    <p class="display text-3xl text-sand-50 sm:text-4xl">{{ fmt(stats.area) }} <span class="text-xl">м²</span></p>
                    <p class="mt-1 text-xs uppercase tracking-[0.2em] text-sand-300/60">{{ $t('site.projects.bento_area') }}</p>
                </div>
            </div>
            <div v-if="stats.products.length" class="mt-5 flex flex-wrap gap-2">
                <span v-for="m in stats.products" :key="m" class="rounded-full border border-sand-100/15 px-3 py-1 text-xs text-sand-100/70">{{ m }}</span>
            </div>
            <p v-if="stats.cities.length" class="mt-4 text-xs text-sand-100/45">{{ stats.cities.join(' · ') }}</p>
        </article>

        <!-- 6. Призыв 2×1: добивает третий ряд, иначе в нём были бы две пустые клетки -->
        <article class="card reveal flex flex-col justify-between rounded-3xl p-7 sm:p-8 md:col-span-2 md:row-span-1">
            <div>
                <p class="eyebrow">{{ $t('site.projects.eyebrow') }}</p>
                <h3 class="display mt-3 text-2xl text-sand-50 sm:text-3xl">{{ $t('site.projects.cta_title') }}</h3>
                <p class="mt-3 text-sm leading-relaxed text-sand-100/55">{{ $t('site.projects.cta_lead') }}</p>
            </div>
            <div class="mt-6 flex flex-wrap gap-3">
                <Link :href="$r('site.contacts')" class="btn-sand">{{ $t('site.cta.write') }}</Link>
            </div>
        </article>
    </div>
</template>
