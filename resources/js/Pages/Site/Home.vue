<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import SiteLayout from '@/Layouts/SiteLayout.vue';
import ProductCard from '@/Components/site/ProductCard.vue';
import PavingParallax from '@/Components/site/PavingParallax.vue';
import HeroShowcase from '@/Components/site/HeroShowcase.vue';
import { observeReveal } from '@/utils/site';
import { useSmoothScroll, loadScrollTrigger } from '@/site/useSmoothScroll';
import { theme } from '@/site/theme';
import { useT } from '@/composables/useTranslations';

const t = useT();

const props = defineProps({
    categories: { type: Array, default: () => [] },
    featured: { type: Array, default: () => [] },
    paving: { type: Array, default: () => [] },
    scene: { type: Object, default: () => ({ textures: {}, models: {}, colors: {} }) },
    stats: { type: Array, default: () => [] },
    advantages: { type: Array, default: () => [] },
    production: { type: Array, default: () => [] },
    projects: { type: Array, default: () => [] },
    // Оформление первого экрана из ERP → Настройки → Сайт.
    hero: { type: String, default: 'scene3d' },
    heroSlides: { type: Array, default: () => [] },
    seo: { type: Object, default: () => ({}) },
});

useSmoothScroll();

// Конфигуратор включается в ERP → Настройки.
const configuratorEnabled = computed(() => usePage().props.site?.configurator ?? false);

const canvas = ref(null);
const storyEl = ref(null);
const progress = ref(0);
const sceneReady = ref(false);
let scene = null;
let stopReveal = () => {};

/** Подписи сюжета — меняются вместе с прогрессом сборки двора. */
const steps = computed(() => [0, 0.28, 0.55, 0.75, 0.92].map((at, i) => ({
    at,
    title: t(`site.home.step_${i + 1}_title`),
    text: t(`site.home.step_${i + 1}_text`),
})));

const activeStep = computed(() => {
    let current = steps.value[0];
    for (const step of steps.value) if (progress.value >= step.at) current = step;
    return current;
});

/**
 * Цвет плитки для 3D-сцены. Первый цвет палитры — «Мрамор белый»: на тёмном
 * фоне он выглядит выцветшим пятном, поэтому берём первый ВЫРАЗИТЕЛЬНЫЙ тон
 * (не слишком светлый и не слишком тёмный), а если такого нет — песочный.
 */
/**
 * Первый экран: 3D-сборка двора или витрина изделий. Витрина требует
 * загруженных снимков — без них честнее показать сцену, чем пустой слайдер.
 */
const showcase = computed(() => props.hero === 'showcase' && props.heroSlides.length > 0);

/**
 * Снимки брусчатки для слоя глубины над 3D-двором. Собираем их из коллекций
 * каталога — отдельного хранилища не заводим: что загружено в ERP, то и на
 * витрине.
 *
 * Сейчас у коллекций брусчатки фото нет, поэтому слой не отрисовывается и в
 * разметку не попадает. Это не мёртвый код: он оживает сам, как только
 * снимки появятся в «Каталог сайта → Позиции».
 */
const pavingPhotos = computed(() => props.paving.flatMap((p) => p.images ?? []));

const heroColor = computed(() => {
    const luminance = (hex) => {
        const v = parseInt(String(hex).replace('#', ''), 16);
        if (Number.isNaN(v)) return 1;
        return (((v >> 16) & 255) * 0.299 + ((v >> 8) & 255) * 0.587 + (v & 255) * 0.114) / 255;
    };
    const palette = props.paving?.[0]?.colors ?? [];
    return palette.find((c) => luminance(c.hex) > 0.35 && luminance(c.hex) < 0.78)?.hex ?? '#C8B79A';
});

onMounted(async () => {
    stopReveal = observeReveal();

    // 3D грузим отдельным чанком и только когда секция близко к экрану:
    // первый экран остаётся лёгким, Core Web Vitals не страдают.
    if (showcase.value || !canvas.value || !storyEl.value) return;

    const start = async () => {
        const [{ createCourtyard }, { gsap, ScrollTrigger }] = await Promise.all([
            import('@/site/courtyard'),
            loadScrollTrigger(),
        ]);

        scene = createCourtyard(canvas.value, {
            color: heroColor.value,
            // Двор освещается по теме страницы: тёмный асфальт ночью,
            // светлая площадка днём.
            theme: theme.value,
            // Фото изделий из ERP: если отмечены как текстура — сцена
            // показывает настоящую поверхность вместо ровного цвета.
            textures: props.scene?.textures ?? {},
            // Цвета изделий из их карточек — используются там, где нет модели.
            colors: props.scene?.colors ?? {},
            onReady: () => (sceneReady.value = true),
        });

        // GLB-модели (если загружены) заменяют процедурные скамью и вазон.
        if (Object.keys(props.scene?.models ?? {}).length) {
            scene.setModels(props.scene.models);
        }

        gsap.to({}, {
            scrollTrigger: {
                trigger: storyEl.value,
                start: 'top top',
                end: 'bottom bottom',
                scrub: 0.6,
                onUpdate: (self) => {
                    progress.value = self.progress;
                    scene?.setProgress(self.progress);
                },
            },
        });

        ScrollTrigger.refresh();
    };

    // Переключатель дня и ночи перекрашивает и сцену — иначе на светлой
    // странице остаётся тёмное пятно.
    watch(theme, (value) => scene?.setTheme(value));

    const io = new IntersectionObserver(([entry]) => {
        if (entry.isIntersecting) {
            io.disconnect();
            start();
        }
    }, { rootMargin: '400px' });
    io.observe(storyEl.value);
});

onBeforeUnmount(() => {
    stopReveal();
    scene?.dispose();
});
</script>

<template>
    <SiteLayout :seo="seo" transparent-header>
        <!-- ======================= Витрина изделий ======================= -->
        <HeroShowcase v-if="showcase" :slides="heroSlides" />

        <!-- ======================= 3D-история двора ======================= -->
        <section v-else ref="storyEl" class="relative h-[520vh]">
            <div class="sticky top-0 h-screen overflow-hidden">
                <canvas ref="canvas" class="absolute inset-0 h-full w-full" aria-hidden="true" />

                <!-- Вуаль поверх сцены: гасит верх под шапку и растворяет
                     нижний край двора в фоне страницы. Градиент собран на
                     токенах, поэтому работает и днём, и ночью. -->
                <div class="hero-veil pointer-events-none absolute inset-0 z-10" />

                <!-- Плиты брусчатки: висят над сценой, но под заголовком -->
                <PavingParallax :photos="pavingPhotos" :progress="progress" />

                <!-- Первый экран -->
                <div
                    class="absolute inset-x-0 top-0 z-30 flex h-screen flex-col justify-center px-5 sm:px-8"
                    :style="{ opacity: Math.max(0, 1 - progress * 5), transform: `translateY(${progress * -60}px)` }"
                >
                    <div class="mx-auto w-full max-w-7xl">
                        <p class="eyebrow">{{ $t('site.home.eyebrow') }}</p>
                        <h1 class="display mt-6 max-w-4xl text-[clamp(2.75rem,8vw,6.5rem)] text-sand-50">
                            {{ $t('site.home.title') }}
                        </h1>
                        <p class="mt-8 max-w-xl text-base leading-relaxed text-sand-100/60 sm:text-lg">
                            {{ $t('site.home.lead') }}
                        </p>
                        <div class="mt-10 flex flex-wrap gap-3">
                            <Link :href="$r('site.catalog')" class="btn-sand">{{ $t('site.cta.catalog') }}</Link>
                            <Link
                                :href="configuratorEnabled ? $r('site.configurator') : $r('site.contacts')"
                                class="btn-ghost"
                            >{{ configuratorEnabled ? $t('site.cta.build_yard_3d') : $t('site.cta.estimate') }}</Link>
                        </div>
                    </div>
                </div>

                <!-- Подписи сюжета -->
                <div
                    class="absolute inset-x-0 bottom-0 z-30 px-5 pb-16 sm:px-8 sm:pb-20"
                    :style="{ opacity: progress > 0.06 && progress < 0.99 ? 1 : 0 }"
                    style="transition: opacity 0.5s ease"
                >
                    <div class="mx-auto flex w-full max-w-7xl items-end justify-between gap-8">
                        <div class="max-w-md">
                            <Transition
                                mode="out-in"
                                enter-active-class="transition duration-500 ease-premium"
                                enter-from-class="opacity-0 translate-y-3"
                                leave-active-class="transition duration-200"
                                leave-to-class="opacity-0"
                            >
                                <div :key="activeStep.title">
                                    <p class="display text-3xl text-sand-50 sm:text-4xl">{{ activeStep.title }}</p>
                                    <p class="mt-3 text-sm leading-relaxed text-sand-100/60">{{ activeStep.text }}</p>
                                </div>
                            </Transition>
                        </div>

                        <!-- Индикатор прогресса сборки -->
                        <div class="hidden w-56 sm:block">
                            <div class="h-px w-full bg-white/15">
                                <div class="h-px bg-sand-300 transition-[width] duration-150" :style="{ width: `${progress * 100}%` }" />
                            </div>
                            <p class="mt-3 text-right text-[11px] uppercase tracking-[0.28em] text-sand-100/40">
                                {{ $t('site.home.assembly') }}
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Подсказка «крутите вниз» -->
                <div
                    class="absolute bottom-8 left-1/2 z-30 -translate-x-1/2 text-[11px] uppercase tracking-[0.3em] text-sand-100/40"
                    :style="{ opacity: Math.max(0, 1 - progress * 12) }"
                >
                    {{ $t('site.home.scroll') }}
                </div>
            </div>
        </section>

        <!-- ======================= Цифры ======================= -->
        <section>
            <div class="stat-grid mx-auto grid max-w-7xl grid-cols-2 lg:grid-cols-4">
                <div v-for="s in stats" :key="s.label" class="px-6 py-10 sm:px-8 sm:py-14">
                    <p class="display text-3xl text-sand-50 sm:text-5xl">{{ s.value }}</p>
                    <p class="mt-3 text-sm text-sand-100/50">{{ s.label }}</p>
                </div>
            </div>
        </section>

        <!-- ======================= Каталог по категориям ======================= -->
        <section class="ambient">
          <div class="mx-auto max-w-7xl px-5 py-20 sm:px-8 sm:py-28">
            <div class="reveal flex flex-wrap items-end justify-between gap-6">
                <div>
                    <p class="eyebrow">{{ $t('site.nav.catalog') }}</p>
                    <h2 class="display mt-4 max-w-2xl text-[clamp(2rem,5vw,3.5rem)] text-sand-50">
                        {{ $t('site.home.catalog_title') }}
                    </h2>
                </div>
                <Link :href="$r('site.catalog')" class="btn-ghost">{{ $t('site.home.all_catalog') }}</Link>
            </div>

            <div class="mt-14 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <Link
                    v-for="c in categories"
                    :key="c.id"
                    :href="$r('site.catalog', { category: c.slug })"
                    class="card card-hover reveal group relative overflow-hidden p-7 sm:p-8"
                >
                    <span
                        class="accent-glow absolute -right-10 -top-10 h-32 w-32 rounded-full blur-2xl transition"
                        :style="{ background: c.accent ?? '#C8B79A' }"
                    />
                    <p class="text-xs text-sand-100/40">{{ c.products_count }} позиций</p>
                    <h3 class="display mt-4 text-2xl text-sand-50">{{ c.name }}</h3>
                    <p class="mt-3 min-h-10 text-sm leading-relaxed text-sand-100/50">{{ c.tagline }}</p>
                    <span class="mt-8 inline-flex items-center gap-2 text-sm font-medium text-sand-300">
                        Смотреть
                        <svg class="h-4 w-4 transition group-hover:translate-x-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6" /></svg>
                    </span>
                </Link>
            </div>
          </div>
        </section>

        <!-- ======================= Хиты ======================= -->
        <section v-if="featured.length" class="ambient ambient-flip">
            <div class="mx-auto max-w-7xl px-5 py-20 sm:px-8 sm:py-28">
                <div class="reveal flex flex-wrap items-end justify-between gap-6">
                    <div>
                        <p class="eyebrow">{{ $t('site.home.featured_eyebrow') }}</p>
                        <h2 class="display mt-4 text-[clamp(2rem,5vw,3.5rem)] text-sand-50">{{ $t('site.home.featured_title') }}</h2>
                    </div>
                    <Link v-if="configuratorEnabled" :href="$r('site.configurator')" class="btn-ghost">{{ $t('site.cta.estimate_yard') }}</Link>
                </div>

                <div class="mt-14 grid gap-5 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                    <ProductCard v-for="p in featured" :key="p.id" :product="p" class="reveal h-full" />
                </div>
            </div>
        </section>

        <!-- ======================= Преимущества ======================= -->
        <section>
          <div class="mx-auto max-w-7xl px-5 py-20 sm:px-8 sm:py-28">
            <p class="eyebrow reveal">{{ $t('site.home.why') }}</p>
            <div class="duo-grid mt-12 grid sm:grid-cols-2">
                <div v-for="a in advantages" :key="a.title" class="reveal p-8 sm:p-12">
                    <h3 class="display text-2xl text-sand-50 sm:text-3xl">{{ a.title }}</h3>
                    <p class="mt-4 max-w-md text-sm leading-relaxed text-sand-100/55">{{ a.text }}</p>
                </div>
            </div>
          </div>
        </section>

        <!-- ======================= Производство ======================= -->
        <section>
            <div class="mx-auto max-w-7xl px-5 py-20 sm:px-8 sm:py-28">
                <div class="reveal max-w-2xl">
                    <p class="eyebrow">{{ $t('site.footer.production') }}</p>
                    <h2 class="display mt-4 text-[clamp(2rem,5vw,3.5rem)] text-sand-50">{{ $t('site.home.production_title') }}</h2>
                </div>

                <ol class="mt-14 space-y-3">
                    <li
                        v-for="p in production"
                        :key="p.step"
                        class="card card-hover reveal group grid gap-4 p-6 sm:grid-cols-[80px_260px_1fr] sm:items-baseline sm:gap-8 sm:p-8"
                    >
                        <span class="text-sm font-semibold tracking-[0.2em] text-sand-300/70">{{ p.step }}</span>
                        <h3 class="display text-2xl text-sand-50">{{ p.title }}</h3>
                        <p class="text-sm leading-relaxed text-sand-100/55">{{ p.text }}</p>
                    </li>
                </ol>

                <Link :href="$r('site.about')" class="btn-ghost mt-12">{{ $t('site.home.about_more') }}</Link>
            </div>
        </section>

        <!-- ======================= Проекты ======================= -->
        <section>
          <div class="mx-auto max-w-7xl px-5 py-20 sm:px-8 sm:py-28">
            <div class="reveal flex flex-wrap items-end justify-between gap-6">
                <div>
                    <p class="eyebrow">{{ $t('site.projects.eyebrow') }}</p>
                    <h2 class="display mt-4 text-[clamp(2rem,5vw,3.5rem)] text-sand-50">{{ $t('site.home.projects_title') }}</h2>
                </div>
                <Link :href="$r('site.projects')" class="btn-ghost">{{ $t('site.home.all_projects') }}</Link>
            </div>

            <div class="mt-14 grid gap-4 sm:grid-cols-2">
                <article
                    v-for="p in projects.slice(0, 4)"
                    :key="p.title"
                    class="card card-hover reveal group overflow-hidden"
                >
                    <!-- Фото объекта из ERP; пока его нет — бетонная заливка,
                         чтобы плитка карточки не разъезжалась по высоте. -->
                    <div class="relative aspect-[16/10] overflow-hidden">
                        <img
                            v-if="p.image"
                            :src="p.image"
                            :srcset="p.thumb ? `${p.thumb} 600w, ${p.image} 1600w` : undefined"
                            sizes="(max-width: 640px) 100vw, 50vw"
                            :alt="p.title"
                            loading="lazy"
                            decoding="async"
                            class="h-full w-full object-cover transition duration-700 ease-premium group-hover:scale-105"
                        />
                        <div v-else class="paving-pattern flex h-full w-full items-center justify-center">
                            <span class="text-[11px] uppercase tracking-[0.28em] text-sand-100/25">{{ $t('site.projects.photo_placeholder') }}</span>
                        </div>

                        <div class="pointer-events-none absolute inset-0 bg-gradient-to-t from-ink-900 via-ink-900/20 to-transparent" />

                        <p v-if="p.area" class="absolute bottom-5 left-6 display text-3xl text-sand-50 sm:text-4xl">{{ p.area }}</p>
                    </div>

                    <div class="p-7 sm:p-8">
                        <p class="text-xs uppercase tracking-[0.24em] text-sand-300/60">
                            {{ p.city }}<template v-if="p.year"> · {{ p.year }}</template>
                        </p>
                        <h3 class="display mt-3 text-2xl text-sand-50">{{ p.title }}</h3>
                        <p v-if="p.products" class="mt-3 text-sm leading-relaxed text-sand-100/50">{{ p.products }}</p>
                    </div>
                </article>
            </div>
          </div>
        </section>

        <!-- ======================= CTA ======================= -->
        <section class="ambient">
            <div class="mx-auto max-w-7xl px-5 py-20 sm:px-8 sm:py-28">
              <!-- Панель идёт во всю ширину колонки, как остальные блоки.
                   Воздух вокруг заголовка даёт не рамка, а его собственная
                   мера строки. -->
              <div class="card px-8 py-16 text-center sm:px-16 sm:py-20">
                <h2 class="display mx-auto max-w-lg text-balance text-[clamp(1.75rem,4vw,2.75rem)] text-sand-50">
                    {{ $t('site.home.cta_title') }}
                </h2>
                <p class="mx-auto mt-5 max-w-md text-pretty text-sm leading-relaxed text-sand-100/55">
                    <template v-if="configuratorEnabled">
                        {{ $t('site.home.cta_lead_3d') }}
                    </template>
                    <template v-else>
                        {{ $t('site.home.cta_lead') }}
                    </template>
                </p>
                <div class="mt-8 flex flex-wrap justify-center gap-3">
                    <Link v-if="configuratorEnabled" :href="$r('site.configurator')" class="btn-sand">{{ $t('site.home.open_configurator') }}</Link>
                    <Link :href="$r('site.contacts')" :class="configuratorEnabled ? 'btn-ghost' : 'btn-sand'">
                        {{ $t('site.home.contact_sales') }}
                    </Link>
                </div>
              </div>
            </div>
        </section>
    </SiteLayout>
</template>
