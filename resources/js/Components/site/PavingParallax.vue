<script setup>
import { computed } from 'vue';

/**
 * Слой глубины из снимков брусчатки.
 *
 * Плиты висят над 3D-двором и расходятся по мере сборки сцены: каждая идёт
 * со своей скоростью и слегка доворачивается — глаз читает это как разные
 * планы в объёме, а не как наложенные картинки.
 *
 * Движение считается от прогресса, который уже вычисляет ScrollTrigger
 * первого экрана: собственного триггера и собственного слушателя скролла
 * здесь нет. Трогаем только transform и opacity, поэтому раскладка не
 * пересчитывается и цикл Three.js не спотыкается.
 */
const props = defineProps({
    photos: { type: Array, default: () => [] },
    progress: { type: Number, default: 0 },
    /** Больше шести плит рядом с живым WebGL не держим — это бюджет кадра. */
    limit: { type: Number, default: 6 },
});

/**
 * Раскладка плит. Центр кадра оставлен пустым: там собирается двор, и
 * перекрывать его снимками нельзя.
 */
const SLOTS = [
    { top: '14%', left: '3%', w: '17vw', depth: 1.0, tilt: -7, ratio: '4 / 5' },
    { top: '52%', left: '7%', w: '13vw', depth: 1.6, tilt: 5, ratio: '1 / 1' },
    { top: '22%', right: '4%', w: '15vw', depth: 1.25, tilt: 6, ratio: '3 / 4' },
    { top: '60%', right: '8%', w: '12vw', depth: 1.9, tilt: -4, ratio: '1 / 1' },
    { top: '5%', right: '26%', w: '10vw', depth: 2.3, tilt: 8, ratio: '4 / 3' },
    { top: '72%', left: '28%', w: '11vw', depth: 2.6, tilt: -6, ratio: '4 / 3' },
];

const plates = computed(() =>
    props.photos.slice(0, Math.min(props.limit, SLOTS.length)).map((photo, i) => ({
        photo,
        slot: SLOTS[i],
        // Каждая следующая плита вступает чуть позже предыдущей.
        from: 0.18 + i * 0.05,
    })),
);

/** Прогресс внутри окна [from, to]: 0 до окна, 1 после. */
const stage = (p, from, to) => Math.min(1, Math.max(0, (p - from) / (to - from)));

const styleFor = (plate) => {
    const t = stage(props.progress, plate.from, plate.from + 0.22);
    // К самому финалу двор собран — плиты уходят, чтобы не спорить с ним.
    const out = stage(props.progress, 0.88, 1);
    const { depth, tilt } = plate.slot;

    const y = (1 - t) * 90 * depth - props.progress * 40 * depth;
    const scale = 0.88 + t * 0.12;
    const rotate = tilt * (1 - t) * 0.6 + (props.progress - 0.5) * tilt * 0.5;

    return {
        top: plate.slot.top,
        left: plate.slot.left,
        right: plate.slot.right,
        width: plate.slot.w,
        aspectRatio: plate.slot.ratio,
        transform: `translate3d(0, ${y.toFixed(2)}px, 0) rotate(${rotate.toFixed(2)}deg) scale(${scale.toFixed(3)})`,
        opacity: (t * (1 - out)).toFixed(3),
    };
};
</script>

<template>
    <div v-if="plates.length" class="pointer-events-none absolute inset-0 z-20" aria-hidden="true">
        <figure
            v-for="(plate, i) in plates"
            :key="i"
            class="paving-plate absolute"
            :style="styleFor(plate)"
        >
            <img
                :src="plate.photo.thumb ?? plate.photo.path"
                :srcset="plate.photo.thumb ? `${plate.photo.thumb} 600w, ${plate.photo.path} 1600w` : undefined"
                sizes="20vw"
                :alt="plate.photo.alt ?? ''"
                loading="lazy"
                decoding="async"
                class="h-full w-full object-cover"
            />
        </figure>
    </div>
</template>
