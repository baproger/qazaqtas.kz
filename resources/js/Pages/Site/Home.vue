<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import SiteLayout from '@/Layouts/SiteLayout.vue';
import ProductCard from '@/Components/site/ProductCard.vue';
import { money, observeReveal } from '@/utils/site';
import { useSmoothScroll, loadScrollTrigger } from '@/site/useSmoothScroll';

const props = defineProps({
    categories: { type: Array, default: () => [] },
    featured: { type: Array, default: () => [] },
    paving: { type: Array, default: () => [] },
    stats: { type: Array, default: () => [] },
    advantages: { type: Array, default: () => [] },
    production: { type: Array, default: () => [] },
    projects: { type: Array, default: () => [] },
    seo: { type: Object, default: () => ({}) },
});

useSmoothScroll();

const canvas = ref(null);
const storyEl = ref(null);
const progress = ref(0);
const sceneReady = ref(false);
let scene = null;
let stopReveal = () => {};

/** Подписи сюжета — меняются вместе с прогрессом сборки двора. */
const steps = [
    { at: 0, title: 'Один элемент', text: 'Мраморная крошка, белый цемент и пигмент. Вибролитьё даёт плотную структуру и сквозной цвет.' },
    { at: 0.28, title: 'Рисунок покрытия', text: 'Плитка ложится в перевязку: рисунок держит нагрузку и не расходится со временем.' },
    { at: 0.55, title: 'Чистый край', text: 'Бордюр фиксирует покрытие по периметру — линия остаётся ровной год за годом.' },
    { at: 0.75, title: 'Городская мебель', text: 'Скамьи, вазоны и урны из того же композита: двор выглядит цельно.' },
    { at: 0.92, title: 'Готовый двор', text: 'От сырья до благоустроенной территории — на трёх площадках: Шымкент, Алматы, Тараз.' },
];

const activeStep = computed(() => {
    let current = steps[0];
    for (const step of steps) if (progress.value >= step.at) current = step;
    return current;
});

const heroColor = computed(() => props.paving?.[0]?.colors?.[0]?.hex ?? '#C8B79A');

onMounted(async () => {
    stopReveal = observeReveal();

    // 3D грузим отдельным чанком и только когда секция близко к экрану:
    // первый экран остаётся лёгким, Core Web Vitals не страдают.
    if (!canvas.value || !storyEl.value) return;

    const start = async () => {
        const [{ createCourtyard }, { gsap, ScrollTrigger }] = await Promise.all([
            import('@/site/courtyard'),
            loadScrollTrigger(),
        ]);

        scene = createCourtyard(canvas.value, {
            color: heroColor.value,
            onReady: () => (sceneReady.value = true),
        });

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
        <!-- ======================= 3D-история двора ======================= -->
        <section ref="storyEl" class="relative h-[520vh]">
            <div class="sticky top-0 h-screen overflow-hidden">
                <canvas ref="canvas" class="absolute inset-0 h-full w-full" aria-hidden="true" />

                <!-- Затемнение по краям, чтобы текст читался поверх сцены -->
                <div class="pointer-events-none absolute inset-0 bg-gradient-to-b from-ink-900/85 via-transparent to-ink-900" />

                <!-- Первый экран -->
                <div
                    class="absolute inset-x-0 top-0 flex h-screen flex-col justify-center px-5 sm:px-8"
                    :style="{ opacity: Math.max(0, 1 - progress * 5), transform: `translateY(${progress * -60}px)` }"
                >
                    <div class="mx-auto w-full max-w-7xl">
                        <p class="eyebrow">Производство мраморного композита · с 2013 года</p>
                        <h1 class="display mt-6 max-w-4xl text-[clamp(2.75rem,8vw,6.5rem)] text-sand-50">
                            Камень,<br />который делает<br />город красивым
                        </h1>
                        <p class="mt-8 max-w-xl text-base leading-relaxed text-sand-100/60 sm:text-lg">
                            Тротуарная плитка, бордюры и малые архитектурные формы из мраморного
                            композита. Собственные площадки в Шымкенте, Алматы и Таразе.
                        </p>
                        <div class="mt-10 flex flex-wrap gap-3">
                            <Link :href="route('site.catalog')" class="btn-sand">Смотреть каталог</Link>
                            <Link :href="route('site.configurator')" class="btn-ghost">Собрать двор в 3D</Link>
                        </div>
                    </div>
                </div>

                <!-- Подписи сюжета -->
                <div
                    class="absolute inset-x-0 bottom-0 px-5 pb-16 sm:px-8 sm:pb-20"
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
                                Сборка двора
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Подсказка «крутите вниз» -->
                <div
                    class="absolute bottom-8 left-1/2 -translate-x-1/2 text-[11px] uppercase tracking-[0.3em] text-sand-100/40"
                    :style="{ opacity: Math.max(0, 1 - progress * 12) }"
                >
                    Прокрутите
                </div>
            </div>
        </section>

        <!-- ======================= Цифры ======================= -->
        <section class="border-y border-white/10 bg-ink-800/40">
            <div class="mx-auto grid max-w-7xl grid-cols-2 gap-px bg-white/10 lg:grid-cols-4">
                <div v-for="s in stats" :key="s.label" class="bg-ink-900 px-6 py-10 sm:px-8 sm:py-14">
                    <p class="display text-3xl text-sand-50 sm:text-5xl">{{ s.value }}</p>
                    <p class="mt-3 text-sm text-sand-100/50">{{ s.label }}</p>
                </div>
            </div>
        </section>

        <!-- ======================= Каталог по категориям ======================= -->
        <section class="mx-auto max-w-7xl px-5 py-24 sm:px-8 sm:py-32">
            <div class="reveal flex flex-wrap items-end justify-between gap-6">
                <div>
                    <p class="eyebrow">Каталог</p>
                    <h2 class="display mt-4 max-w-2xl text-[clamp(2rem,5vw,3.5rem)] text-sand-50">
                        Всё для благоустройства — из одного материала
                    </h2>
                </div>
                <Link :href="route('site.catalog')" class="btn-ghost">Весь каталог</Link>
            </div>

            <div class="mt-14 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <Link
                    v-for="c in categories"
                    :key="c.id"
                    :href="route('site.catalog', { category: c.slug })"
                    class="reveal concrete group relative overflow-hidden rounded-3xl border border-white/10 bg-ink-800/60 p-7 transition duration-500 ease-premium hover:-translate-y-1 hover:border-sand-300/40 sm:p-8"
                >
                    <span
                        class="absolute -right-10 -top-10 h-32 w-32 rounded-full opacity-20 blur-2xl transition group-hover:opacity-40"
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
        </section>

        <!-- ======================= Хиты ======================= -->
        <section v-if="featured.length" class="border-y border-white/10 bg-ink-800/30">
            <div class="mx-auto max-w-7xl px-5 py-24 sm:px-8 sm:py-32">
                <div class="reveal flex flex-wrap items-end justify-between gap-6">
                    <div>
                        <p class="eyebrow">Выбирают чаще всего</p>
                        <h2 class="display mt-4 text-[clamp(2rem,5vw,3.5rem)] text-sand-50">Готовы к отгрузке</h2>
                    </div>
                    <Link :href="route('site.configurator')" class="btn-ghost">Рассчитать двор</Link>
                </div>

                <div class="mt-14 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    <ProductCard v-for="p in featured" :key="p.id" :product="p" class="reveal" />
                </div>
            </div>
        </section>

        <!-- ======================= Преимущества ======================= -->
        <section class="mx-auto max-w-7xl px-5 py-24 sm:px-8 sm:py-32">
            <p class="eyebrow reveal">Почему композит</p>
            <div class="mt-12 grid gap-px bg-white/10 sm:grid-cols-2">
                <div v-for="a in advantages" :key="a.title" class="reveal bg-ink-900 p-8 sm:p-12">
                    <h3 class="display text-2xl text-sand-50 sm:text-3xl">{{ a.title }}</h3>
                    <p class="mt-4 max-w-md text-sm leading-relaxed text-sand-100/55">{{ a.text }}</p>
                </div>
            </div>
        </section>

        <!-- ======================= Производство ======================= -->
        <section class="border-y border-white/10 bg-ink-800/30">
            <div class="mx-auto max-w-7xl px-5 py-24 sm:px-8 sm:py-32">
                <div class="reveal max-w-2xl">
                    <p class="eyebrow">Производство</p>
                    <h2 class="display mt-4 text-[clamp(2rem,5vw,3.5rem)] text-sand-50">Пять шагов от сырья до паллеты</h2>
                </div>

                <ol class="mt-14 space-y-px">
                    <li
                        v-for="p in production"
                        :key="p.step"
                        class="reveal group grid gap-4 border-t border-white/10 py-8 transition hover:bg-white/[0.02] sm:grid-cols-[80px_260px_1fr] sm:items-baseline sm:gap-8"
                    >
                        <span class="text-sm font-semibold tracking-[0.2em] text-sand-300/70">{{ p.step }}</span>
                        <h3 class="display text-2xl text-sand-50">{{ p.title }}</h3>
                        <p class="text-sm leading-relaxed text-sand-100/55">{{ p.text }}</p>
                    </li>
                </ol>

                <Link :href="route('site.about')" class="btn-ghost mt-12">О заводе подробнее</Link>
            </div>
        </section>

        <!-- ======================= Проекты ======================= -->
        <section class="mx-auto max-w-7xl px-5 py-24 sm:px-8 sm:py-32">
            <div class="reveal flex flex-wrap items-end justify-between gap-6">
                <div>
                    <p class="eyebrow">Реализовано</p>
                    <h2 class="display mt-4 text-[clamp(2rem,5vw,3.5rem)] text-sand-50">Объекты по Казахстану</h2>
                </div>
                <Link :href="route('site.projects')" class="btn-ghost">Все проекты</Link>
            </div>

            <div class="mt-14 grid gap-4 sm:grid-cols-2">
                <article
                    v-for="p in projects.slice(0, 4)"
                    :key="p.title"
                    class="reveal concrete rounded-3xl border border-white/10 bg-ink-800/60 p-8 sm:p-10"
                >
                    <p class="text-xs uppercase tracking-[0.24em] text-sand-300/60">{{ p.city }} · {{ p.year }}</p>
                    <h3 class="display mt-4 text-2xl text-sand-50">{{ p.title }}</h3>
                    <p class="mt-3 text-sm text-sand-100/50">{{ p.products }}</p>
                    <p class="mt-6 text-3xl font-semibold text-sand-300">{{ p.area }}</p>
                </article>
            </div>
        </section>

        <!-- ======================= CTA ======================= -->
        <section class="border-t border-white/10 bg-gradient-to-b from-ink-800/40 to-ink-900">
            <div class="mx-auto max-w-7xl px-5 py-24 text-center sm:px-8 sm:py-36">
                <h2 class="display mx-auto max-w-3xl text-[clamp(2.25rem,6vw,4.5rem)] text-sand-50">
                    Посчитаем ваш двор за пару минут
                </h2>
                <p class="mx-auto mt-6 max-w-xl text-sm leading-relaxed text-sand-100/55 sm:text-base">
                    Укажите площадь и выберите раскладку — конфигуратор покажет результат в 3D,
                    рассчитает количество плитки и бордюра и соберёт заказ.
                </p>
                <div class="mt-10 flex flex-wrap justify-center gap-3">
                    <Link :href="route('site.configurator')" class="btn-sand">Открыть конфигуратор</Link>
                    <Link :href="route('site.contacts')" class="btn-ghost">Связаться с отделом продаж</Link>
                </div>
                <p v-if="paving.length" class="mt-10 text-xs text-sand-100/35">
                    Плитка от {{ money(Math.min(...paving.map((p) => p.price))) }} за м²
                </p>
            </div>
        </section>
    </SiteLayout>
</template>
