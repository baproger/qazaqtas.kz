/**
 * Живой фон витрины.
 *
 * Градиент не перерисовывается — вместо этого три фиксированных слоя света
 * перетекают друг в друга по мере прокрутки. Меняется только opacity, а это
 * композитная операция: страница не перекрашивается, и рендер-цикл
 * Three.js на первом экране не спотыкается.
 *
 * Настроение по высоте страницы:
 *   верх    — тёплый студийный свет над первым экраном
 *   середина — холодный камень: каталог, цех, характеристики
 *   низ     — глубокая тёплая подсветка под призывом и подвалом
 */
import { onBeforeUnmount, onMounted } from 'vue';

const clamp01 = (v) => Math.min(1, Math.max(0, v));

/** Колокол с вершиной в center: слой разгорается и гаснет. */
const bell = (p, center, width) => clamp01(1 - Math.abs(p - center) / width);

export function useScrollAmbience(target) {
    let raf = null;
    let last = -1;

    const apply = () => {
        raf = null;

        const el = target?.value;
        if (!el) return;

        const scrollable = document.documentElement.scrollHeight - window.innerHeight;
        const p = scrollable > 0 ? clamp01(window.scrollY / scrollable) : 0;

        // Пишем в стиль только когда прогресс реально сдвинулся: на длинной
        // странице соседние кадры дают одно и то же число.
        if (Math.abs(p - last) < 0.002) return;
        last = p;

        el.style.setProperty('--amb-warm', (1 - clamp01(p / 0.45)).toFixed(3));
        el.style.setProperty('--amb-cool', bell(p, 0.55, 0.45).toFixed(3));
        el.style.setProperty('--amb-deep', clamp01((p - 0.55) / 0.45).toFixed(3));
    };

    const onScroll = () => {
        if (raf === null) raf = requestAnimationFrame(apply);
    };

    onMounted(() => {
        // При «уменьшить движение» фон остаётся ровным: смена настроения —
        // украшение, а не смысл.
        if (window.matchMedia?.('(prefers-reduced-motion: reduce)').matches) return;

        apply();
        window.addEventListener('scroll', onScroll, { passive: true });
        window.addEventListener('resize', onScroll, { passive: true });
    });

    onBeforeUnmount(() => {
        if (raf !== null) cancelAnimationFrame(raf);
        window.removeEventListener('scroll', onScroll);
        window.removeEventListener('resize', onScroll);
    });
}
