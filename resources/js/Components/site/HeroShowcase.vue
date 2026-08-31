<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import { loadSilhouette } from '@/utils/silhouette';

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
/* Выноски: точка — на предмете                                        */
/* ------------------------------------------------------------------ */

/*
 * Позиции выносок в CSS — четыре фиксированных места под «средний» кадр.
 * Силуэты у снимков разные (низкий бордюр против урны во весь кадр), и на
 * фиксированных местах точка попадала в пустоту рядом с предметом. Поэтому
 * по альфа-каналу снимка один раз считается силуэт, и каждая точка
 * дотягивается до кромки предмета на своей высоте. Пока силуэт не готов
 * (или canvas недоступен) — работают запасные позиции из CSS.
 */
const silhouettes = ref({});
const specsBox = ref(null);
/** Размер слоя выносок: без него не восстановить contain-раскладку снимка. */
const boxSize = ref({ w: 1, h: 1 });
let boxObserver = null;

/** Слот → сторона подписи и высота точки в долях силуэта (0 — макушка). */
const SPEC_SLOTS = {
    'top-right': { side: 'right', at: 0.18 },
    left: { side: 'left', at: 0.45 },
    right: { side: 'right', at: 0.65 },
    bottom: { side: 'left', at: 0.88 },
};
/** Точка стоит чуть внутри предмета, а не на самом срезе кромки. */
const DOT_INSET = 0.035;

const analyzeSlide = (slide) => {
    const src = slide?.image?.path;
    if (!src || src in silhouettes.value) return;
    silhouettes.value = { ...silhouettes.value, [src]: null };
    loadSilhouette(src).then((sil) => {
        if (sil) silhouettes.value = { ...silhouettes.value, [src]: sil };
    });
};
watch(current, analyzeSlide, { immediate: true });

/** Инлайн-позиция выноски; null — остаёмся на запасной позиции из CSS. */
const specStyle = (spec) => {
    const sil = silhouettes.value[current.value?.image?.path];
    const slot = SPEC_SLOTS[spec.pos];
    if (!sil || !slot) return null;

    const edge = sil.edgeAt(slot.at);
    if (!edge) return null;

    // Снимок лежит в слое по object-fit: contain — восстанавливаем его
    // реальный прямоугольник, чтобы доли кадра стали долями слоя.
    const { w: boxW, h: boxH } = boxSize.value;
    const scale = Math.min(boxW / sil.width, boxH / sil.height);
    const offX = (boxW - sil.width * scale) / 2;
    const offY = (boxH - sil.height * scale) / 2;
    const toX = (frac) => ((offX + frac * sil.width * scale) / boxW) * 100;

    // Полоса тянется от края слоя ровно до кромки. Плашка при этом не
    // сжимается (см. .is-anchored в hero.css): если кромка у самого края и
    // места мало, содержимое переполняет полосу в сторону предмета — точка
    // сама уходит чуть глубже на изделие, а текст остаётся читаемым.
    const width = slot.side === 'right'
        ? 100 - toX(edge.right - DOT_INSET)
        : toX(edge.left + DOT_INSET);
    const y = ((offY + edge.y * sil.height * scale) / boxH) * 100;

    const base = {
        top: `${y.toFixed(2)}%`,
        bottom: 'auto',
        transform: 'translateY(-50%)',
        maxWidth: 'none',
        width: `${width.toFixed(2)}%`,
    };
    return slot.side === 'right'
        ? { ...base, right: '0', left: 'auto' }
        : { ...base, left: '0', right: 'auto' };
};

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
        // Нижняя граница — страховка от скачка часов назад: при t < 0
        // кубическая кривая уводила цену в сотни миллионов.
        const t = Math.min(1, Math.max(0, (now - start) / 420));
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

let autoTimer = null;
let resumeTimer = null;
const paused = ref(false);

const stopAutoplay = () => {
    clearInterval(autoTimer);
    autoTimer = null;
};

const startAutoplay = () => {
    if (reduced.value || paused.value || props.slides.length < 2 || document.hidden) return;
    stopAutoplay();
    autoTimer = window.setInterval(() => goTo(index.value + 1, 1), AUTOPLAY);
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

    if (specsBox.value && 'ResizeObserver' in window) {
        boxObserver = new ResizeObserver(([entry]) => {
            boxSize.value = { w: entry.contentRect.width || 1, h: entry.contentRect.height || 1 };
        });
        boxObserver.observe(specsBox.value);
    }

    document.addEventListener('visibilitychange', onVisibility);
    startAutoplay();
});

onBeforeUnmount(() => {
    boxObserver?.disconnect();
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
        class="hero-showcase ambient"
        :class="reduced ? 'is-reduced' : ''"
        :style="{ '--dir': direction }"
        aria-roledescription="carousel"
        :aria-label="$t('site.hero.showcase')"
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
                    <span class="text-base text-sand-100/50">{{ $t('site.common.from') }}</span>
                    <span class="display text-4xl text-sand-50 sm:text-5xl">{{ money(shownPrice) }} ₸</span>
                    <span class="text-base text-sand-100/50">/ {{ current.unit }}</span>
                </p>

                <p class="hero-anim hero-lead mt-6 max-w-xl text-sm leading-relaxed text-sand-100/60 sm:text-base" style="--d: 160ms">
                    {{ current.lead }}
                </p>

                <div class="hero-anim mt-9 flex flex-wrap items-center gap-3" style="--d: 210ms">
                    <Link :href="current.href" class="btn-sand">
                        {{ $tc('site.hero.view_count', current.count) }}
                    </Link>
                    <Link :href="$r('site.contacts')" class="btn-ghost">{{ $t('site.cta.estimate') }}</Link>
                </div>

                <p class="hero-anim mt-5 text-xs text-sand-100/40" style="--d: 260ms">
                    {{ $t('site.hero.note') }}
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

                <!-- WebP берётся первым, исходный PNG остаётся запасным для
                     браузеров, которые его не понимают. -->
                <picture :key="current.image.path">
                    <source v-if="current.image.webp" :srcset="current.image.webp" type="image/webp" />
                    <img
                        class="hero-img"
                        :src="current.image.path"
                        :alt="current.image.alt"
                        :loading="index === 0 ? 'eager' : 'lazy'"
                        decoding="async"
                    />
                </picture>

                <ul ref="specsBox" class="hero-specs" aria-hidden="true">
                    <li
                        v-for="(spec, i) in current.specs"
                        :key="`${current.id}-${spec.label}`"
                        class="hero-spec"
                        :class="[`is-${spec.pos}`, specStyle(spec) ? 'is-anchored' : '']"
                        :style="[{ '--d': `${i * 70}ms` }, specStyle(spec)]"
                    >
                        <span class="hero-spec-pill" :class="{ 'is-short': String(spec.value).length <= 20 }">
                            <b>{{ spec.value }}</b>
                            <em>{{ spec.label }}</em>
                        </span>
                        <i class="hero-spec-line" />
                        <i class="hero-spec-dot" />
                    </li>
                </ul>
            </div>
        </div>

        <!-- Лента переключателей -->
        <div class="mx-auto max-w-7xl px-5 pb-14 sm:px-8">
            <div class="mb-3 flex items-center justify-between gap-4">
                <p class="eyebrow">{{ $t('site.hero.showcase') }}</p>
                <div class="flex gap-2">
                    <button type="button" class="hero-arrow" :aria-label="$t('site.hero.prev')" @click="prev">←</button>
                    <button type="button" class="hero-arrow" :aria-label="$t('site.hero.next')" @click="next">→</button>
                </div>
            </div>

            <div ref="rail" class="hero-rail" role="tablist" :aria-label="$t('site.hero.products')">
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
                    <img class="hero-thumb-img" :src="slide.image.thumb" :alt="slide.name" loading="lazy" decoding="async" />
                    <span class="hero-thumb-text">
                        <b>{{ slide.name }}</b>
                        <em>{{ $tc('site.hero.count', slide.count) }}</em>
                    </span>
                    <span class="hero-thumb-mark" aria-hidden="true" />
                </button>
            </div>

        </div>
    </section>
</template>
