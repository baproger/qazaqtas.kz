import { onBeforeUnmount, onMounted } from 'vue';

/**
 * Плавный скролл (Lenis) + синхронизация с ScrollTrigger.
 *
 * Подключается ТОЛЬКО на страницах с прокруточной анимацией (главная,
 * конфигуратор): на обычных страницах нативный скролл быстрее и привычнее.
 * При prefers-reduced-motion не включается вовсе.
 */
export function useSmoothScroll(enabled = true) {
    let lenis = null;
    let onVisibility = null;
    let rafId = null;
    let scrollTrigger = null;

    onMounted(async () => {
        if (!enabled || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

        const [{ default: Lenis }, gsapModule, stModule] = await Promise.all([
            import('lenis'),
            import('gsap'),
            import('gsap/ScrollTrigger'),
        ]);

        const gsap = gsapModule.gsap ?? gsapModule.default;
        scrollTrigger = stModule.ScrollTrigger ?? stModule.default;
        gsap.registerPlugin(scrollTrigger);

        lenis = new Lenis({ duration: 1.1, smoothWheel: true, wheelMultiplier: 0.9 });
        lenis.on('scroll', scrollTrigger.update);

        const raf = (time) => {
            lenis.raf(time);
            rafId = requestAnimationFrame(raf);
        };
        rafId = requestAnimationFrame(raf);

        // Вкладка скрыта — кадры не нужны: вечный rAF грел процессор,
        // даже когда сайт просто открыт в фоне.
        onVisibility = () => {
            if (document.hidden) { if (rafId) cancelAnimationFrame(rafId); rafId = null; }
            else if (!rafId) rafId = requestAnimationFrame(raf);
        };
        document.addEventListener('visibilitychange', onVisibility);
    });

    onBeforeUnmount(() => {
        if (onVisibility) document.removeEventListener('visibilitychange', onVisibility);
        if (rafId) cancelAnimationFrame(rafId);
        lenis?.destroy();
        scrollTrigger?.getAll().forEach((t) => t.kill());
    });
}

/** Ленивая загрузка GSAP + ScrollTrigger одним промисом. */
export async function loadScrollTrigger() {
    const [gsapModule, stModule] = await Promise.all([import('gsap'), import('gsap/ScrollTrigger')]);
    const gsap = gsapModule.gsap ?? gsapModule.default;
    const ScrollTrigger = stModule.ScrollTrigger ?? stModule.default;
    gsap.registerPlugin(ScrollTrigger);

    return { gsap, ScrollTrigger };
}
