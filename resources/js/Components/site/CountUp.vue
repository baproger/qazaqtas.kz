<script setup>
/**
 * Цифра, «набегающая» при попадании в вьюпорт: 0 → значение за ~1.4 с
 * с сильным ease-out. Нечисловые части строки («лет», «F», «м²»)
 * остаются на месте — анимируется только число.
 * При prefers-reduced-motion значение показывается сразу.
 */
import { ref, onMounted, onBeforeUnmount } from 'vue';

const props = defineProps({
    value: { type: [String, Number], default: '' },
    duration: { type: Number, default: 1400 },
});

const el = ref(null);
const shown = ref(String(props.value));

const m = String(props.value).match(/^([^0-9]*)([\d\s  ]*\d)([\s\S]*)$/);
const prefix = m ? m[1] : '';
const target = m ? parseInt(m[2].replace(/\D/g, ''), 10) : null;
const suffix = m ? m[3] : '';
const fmt = (n) => new Intl.NumberFormat('ru-RU').format(n);

let io = null;
onMounted(() => {
    if (target === null || !el.value) return;
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

    shown.value = prefix + fmt(0) + suffix;
    io = new IntersectionObserver(([entry]) => {
        if (!entry.isIntersecting) return;
        io.disconnect();
        io = null;
        const t0 = performance.now();
        const easeOut = (t) => 1 - Math.pow(1 - t, 3);
        const tick = (now) => {
            const p = Math.min(1, (now - t0) / props.duration);
            shown.value = prefix + fmt(Math.round(target * easeOut(p))) + suffix;
            if (p < 1) requestAnimationFrame(tick);
        };
        requestAnimationFrame(tick);
    }, { threshold: 0.4 });
    io.observe(el.value);
});
onBeforeUnmount(() => io?.disconnect());
</script>

<template>
    <span ref="el">{{ shown }}</span>
</template>
