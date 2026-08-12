<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Link } from '@inertiajs/vue3';

/**
 * Витрина изделий на первом экране: нижняя лента карточек переключает слайды,
 * вместе с ними меняются рендер, заголовок, цена, описание и выноски.
 *
 * Всё движение — на transform и opacity. Высота блока зафиксирована, поэтому
 * при переключении вёрстка не прыгает.
 */
const props = defineProps({
    slides: { type: Array, default: () => [] },
});

const DURATION = 520;
const AUTOPLAY = 7000;
/** Пауза после ручного переключения: не выдёргиваем слайд из-под пальца. */
const MANUAL_PAUSE = 15000;

const reduced = ref(false);

const index = ref(0);
const direction = ref(1);
const animating = ref(false);
/** Пока идёт переход, лишние клики схлопываются в последний. */
let pending = null;

const root = ref(null);
const rail = ref(null);
const railItems = ref([]);

const current = computed(() => props.slides[index.value] ?? null);

/* ------------------------------------------------------------------ */
/* Покупка                                                             */
/* ------------------------------------------------------------------ */

const money = (value) => new Intl.NumberFormat('ru-RU').format(Math.round(value));

/** Цена анимируется отдельно от вёрстки — счёт идёт по кадрам. */
const shownPrice = ref(0);
let priceRaf = null;

const countTo = (to) => {
    cancelAnimationFrame(priceRaf);
    const from = shownPrice.value;
    if (reduced.value || from === to) {
        shownPrice.value = to;
        return;
    }
    const start = performance.now();
    const step = (now) => {
        const t = Math.min(1, (now - start) / 420);
        const eased = 1 - Math.pow(1 - t, 3);
        shownPrice.value = from + (to - from) * eased;
        if (t < 1) priceRaf = requestAnimationFrame(step);
    };
    priceRaf = requestAnimationFrame(step);
};

/* ------------------------------------------------------------------ */
/* Переключение слайдов                                                */
/* ------------------------------------------------------------------ */

/** Следующий рендер декодируем заранее — иначе на переходе мигнёт пустота. */
const preload = (slide) => {
    const src = slide?.image?.path;
    if (!src) return Promise.resolve();
    const img = new Image();
    img.src = src;
    return img.decode ? img.decode().catch(() => {}) : Promise.resolve();
};

const goTo = async (next, dir = null) => {
    if (!props.slides.length) return;
    const target = (next + props.slides.length) % props.slides.length;
    if (target === index.value) return;

    if (animating.value) {
        pending = target;
        return;
    }

    direction.value = dir ?? (target > index.value ? 1 : -1);
    animating.value = true;

    await preload(props.slides[target]);

    index.value = target;
    countTo(props.slides[target].price ?? 0);
    syncUrl(props.slides[target].id);
    await nextTick();
    scrollRailTo(target);

    window.setTimeout(() => {
        animating.value = false;
        if (pending !== null) {
            const queued = pending;
            pending = null;
            goTo(queued);
        }
    }, reduced.value ? 160 : DURATION);
};

const next = () => { pauseAutoplay(); goTo(index.value + 1, 1); };
const prev = () => { pauseAutoplay(); goTo(index.value - 1, -1); };
const select = (i) => { pauseAutoplay(); goTo(i); };

/** Активную карточку подводим в видимую часть ленты, не трогая скролл страницы. */
const scrollRailTo = (i) => {
    const el = railItems.value[i];
    const box = rail.value;
    if (!el || !box) return;
    const left = el.offsetLeft - (box.clientWidth - el.offsetWidth) / 2;
    box.scrollTo({ left: Math.max(0, left), behavior: reduced.value ? 'auto' : 'smooth' });
};

/* ------------------------------------------------------------------ */
/* Адрес страницы                                                      */
/* ------------------------------------------------------------------ */

const syncUrl = (id) => {
    try {
        const url = new URL(window.location.href);
        url.searchParams.set('item', id);
        window.history.replaceState(window.history.state, '', url);
    } catch { /* приватный режим или необычный адрес — не критично */ }
};

/* ------------------------------------------------------------------ */
/* Автопрокрутка                                                       */
/* ------------------------------------------------------------------ */

const progress = ref(0);
let autoTimer = null;
let progressRaf = null;
let resumeTimer = null;
const paused = ref(false);

const stopAutoplay = () => {
    clearInterval(autoTimer);
    cancelAnimationFrame(progressRaf);
    autoTimer = null;
    progress.value = 0;
};

const startAutoplay = () => {
    if (reduced.value || paused.value || props.slides.length < 2 || document.hidden) return;
    stopAutoplay();

    let start = performance.now();
    const tick = (now) => {
        progress.value = Math.min(1, (now - start) / AUTOPLAY);
        if (progress.value >= 1) {
            start = now;
            goTo(index.value + 1, 1);
        }
        progressRaf = requestAnimationFrame(tick);
    };
    progressRaf = requestAnimationFrame(tick);
};

/** Ручное переключение отодвигает автопрокрутку, а не выключает навсегда. */
const pauseAutoplay = () => {
    stopAutoplay();
    clearTimeout(resumeTimer);
    resumeTimer = window.setTimeout(() => {
        if (!paused.value) startAutoplay();
    }, MANUAL_PAUSE);
};

const hold = () => { paused.value = true; stopAutoplay(); };
const release = () => { paused.value = false; startAutoplay(); };

/* ------------------------------------------------------------------ */
/* Клавиатура и свайп                                                  */
/* ------------------------------------------------------------------ */

const onKeydown = (event) => {
    if (event.key === 'ArrowLeft') { event.preventDefault(); prev(); }
    if (event.key === 'ArrowRight') { event.preventDefault(); next(); }
};

let touchX = null;
const onTouchStart = (e) => (touchX = e.changedTouches[0].clientX);
const onTouchEnd = (e) => {
    if (touchX === null) return;
    const delta = e.changedTouches[0].clientX - touchX;
    touchX = null;
    if (Math.abs(delta) < 40) return;
    delta < 0 ? next() : prev();
};

const onVisibility = () => (document.hidden ? stopAutoplay() : startAutoplay());

onMounted(() => {
    reduced.value = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false;

    // Восстанавливаем слайд из адреса страницы.
    const wanted = new URLSearchParams(window.location.search).get('item');
    const found = props.slides.findIndex((s) => s.id === wanted);
    if (found > 0) index.value = found;

    shownPrice.value = current.value?.price ?? 0;

    props.slides.slice(0, 3).forEach(preload);
    nextTick(() => scrollRailTo(index.value));

    document.addEventListener('visibilitychange', onVisibility);
    startAutoplay();
});

onBeforeUnmount(() => {
    stopAutoplay();
    clearTimeout(resumeTimer);
    cancelAnimationFrame(priceRaf);
    document.removeEventListener('visibilitychange', onVisibility);
});

watch(() => props.slides.length, () => nextTick(() => scrollRailTo(index.value)));
</script>

<template>
    <section
        v-if="slides.length"
        ref="root"
        class="hero-showcase band band-hero ambient"
        :class="reduced ? 'is-reduced' : ''"
        :style="{ '--dir': direction }"
        aria-roledescription="carousel"
        aria-label="Витрина изделий"
        tabindex="-1"
        @mouseenter="hold"
        @mouseleave="release"
        @focusin="hold"
        @focusout="release"
        @keydown="onKeydown"
    >
        <div class="mx-auto grid max-w-7xl gap-10 px-5 py-16 sm:px-8 lg:grid-cols-[minmax(0,1fr)_minmax(0,600px)] lg:items-center lg:py-24">
            <!-- Текстовая колонка -->
            <div class="hero-copy" :key="`copy-${current.id}`">
                <p class="eyebrow hero-anim" style="--d: 0ms">{{ current.category }}</p>

                <h1 class="display hero-anim hero-title mt-5 text-[clamp(2.25rem,5vw,3.75rem)] text-sand-50" style="--d: 60ms">
                    {{ current.title }}
                </h1>

                <p v-if="current.price" class="hero-anim mt-6 flex items-baseline gap-2" style="--d: 110ms">
                    <span class="text-base text-sand-100/50">от</span>
                    <span class="display text-4xl text-sand-50 sm:text-5xl">{{ money(shownPrice) }} ₸</span>
                    <span class="text-base text-sand-100/50">/ {{ current.unit }}</span>
                </p>

                <p class="hero-anim hero-lead mt-6 max-w-xl text-sm leading-relaxed text-sand-100/60 sm:text-base" style="--d: 160ms">
                    {{ current.lead }}
                </p>

                <div class="hero-anim mt-9 flex flex-wrap items-center gap-3" style="--d: 210ms">
                    <Link :href="current.href" class="btn-sand">
                        Смотреть {{ current.count }} {{ current.count === 1 ? 'позицию' : 'позиций' }}
                    </Link>
                    <Link :href="route('site.contacts')" class="btn-ghost">Рассчитать стоимость</Link>
                </div>

                <p class="hero-anim mt-5 text-xs text-sand-100/40" style="--d: 260ms">
                    Расчёт и выезд замерщика бесплатны · отгрузка со склада в Шымкенте
                </p>
            </div>

            <!-- Визуал -->
            <div
                class="hero-visual"
                aria-live="polite"
                @touchstart.passive="onTouchStart"
                @touchend.passive="onTouchEnd"
            >
                <div class="hero-glow" aria-hidden="true" />

                <img
                    :key="current.image.path"
                    class="hero-img"
                    :src="current.image.path"
                    :alt="current.image.alt"
                    :loading="index === 0 ? 'eager' : 'lazy'"
                    decoding="async"
                />

                <ul class="hero-specs" aria-hidden="true">
                    <li
                        v-for="(spec, i) in current.specs"
                        :key="`${current.id}-${spec.label}`"
                        class="hero-spec"
                        :class="`is-${spec.pos}`"
                        :style="{ '--d': `${i * 70}ms` }"
                    >
                        <i class="hero-spec-line" />
                        <i class="hero-spec-dot" />
                        <span class="hero-spec-pill">
                            <b>{{ spec.value }}</b>
                            <em>{{ spec.label }}</em>
                        </span>
                    </li>
                </ul>
            </div>
        </div>

        <!-- Лента переключателей -->
        <div class="mx-auto max-w-7xl px-5 pb-14 sm:px-8">
            <div class="mb-3 flex items-center justify-between gap-4">
                <p class="eyebrow">Витрина изделий</p>
                <div class="flex gap-2">
                    <button type="button" class="hero-arrow" aria-label="Предыдущий товар" @click="prev">←</button>
                    <button type="button" class="hero-arrow" aria-label="Следующий товар" @click="next">→</button>
                </div>
            </div>

            <div ref="rail" class="hero-rail" role="tablist" aria-label="Товары">
                <button
                    v-for="(slide, i) in slides"
                    :key="slide.id"
                    :ref="(el) => (railItems[i] = el)"
                    type="button"
                    role="tab"
                    :aria-selected="i === index"
                    :class="['hero-thumb', i === index ? 'is-active' : '']"
                    @click="select(i)"
                >
                    <img :src="slide.image.thumb" :alt="slide.title" loading="lazy" decoding="async" />
                    <span>
                        <b>{{ slide.title }}</b>
                        <em>{{ slide.thumbSpec }}</em>
                    </span>
                </button>
            </div>

            <div class="hero-progress" aria-hidden="true">
                <i :style="{ transform: `scaleX(${progress})` }" />
            </div>
        </div>
    </section>
</template>
