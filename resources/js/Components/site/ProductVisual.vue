<script setup>
import { computed } from 'vue';

/**
 * Превью изделия. Пока в карточке ERP нет загруженных фото, рисуем честную
 * векторную схему по типу изделия и выбранному цвету — она масштабируется,
 * ничего не весит и всегда соответствует данным каталога.
 * Как только в ERP появляется первое фото (product.images), показываем его.
 */
const props = defineProps({
    product: { type: Object, required: true },
    color: { type: String, default: null },
    ratio: { type: String, default: 'aspect-[4/3]' },
});

const image = computed(() => props.product?.images?.[0] ?? null);
const slug = computed(() => props.product?.category?.slug ?? 'trotuarnaya-plitka');
const base = computed(() => props.color || props.product?.colors?.[0]?.hex || '#B9B3A9');

/** Затемнение/осветление тона — грани изделия и швы. */
const shade = (hex, amount) => {
    const clean = String(hex).replace('#', '');
    const full = clean.length === 3 ? clean.split('').map((c) => c + c).join('') : clean;
    const num = parseInt(full || 'B9B3A9', 16);
    const clamp = (v) => Math.max(0, Math.min(255, Math.round(v)));
    const r = clamp(((num >> 16) & 255) * amount);
    const g = clamp(((num >> 8) & 255) * amount);
    const b = clamp((num & 255) * amount);
    return `rgb(${r}, ${g}, ${b})`;
};

const light = computed(() => shade(base.value, 1.12));
const dark = computed(() => shade(base.value, 0.72));
const deep = computed(() => shade(base.value, 0.5));

/** Раскладка плитки: элементы со смещением в половину ряда. */
const tiles = computed(() => {
    const rows = [];
    for (let row = 0; row < 5; row++) {
        const offset = row % 2 ? -30 : 0;
        for (let col = -1; col < 5; col++) {
            rows.push({ x: 20 + col * 62 + offset, y: 30 + row * 34, w: 58, h: 30 });
        }
    }
    return rows;
});
</script>

<template>
    <div class="concrete relative overflow-hidden rounded-2xl bg-ink-700" :class="ratio">
        <img
            v-if="image"
            :src="image"
            :alt="product.name"
            loading="lazy"
            decoding="async"
            class="h-full w-full object-cover"
        />

        <svg v-else viewBox="0 0 300 220" class="h-full w-full" role="img" :aria-label="product.name">
            <defs>
                <linearGradient :id="`sky-${product.id}`" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0" stop-color="#1B1F24" />
                    <stop offset="1" stop-color="#0C0E11" />
                </linearGradient>
            </defs>
            <rect width="300" height="220" :fill="`url(#sky-${product.id})`" />

            <!-- Тротуарная плитка: перевязка в перспективе -->
            <g v-if="slug === 'trotuarnaya-plitka'">
                <g v-for="(t, i) in tiles" :key="i">
                    <rect :x="t.x" :y="t.y" :width="t.w" :height="t.h" rx="3"
                        :fill="i % 7 === 0 ? light : i % 5 === 0 ? dark : base" />
                </g>
                <rect x="0" y="0" width="300" height="30" fill="#0C0E11" opacity="0.85" />
                <rect x="0" y="196" width="300" height="24" :fill="deep" />
            </g>

            <!-- Бордюр: ряд блоков вдоль дорожки -->
            <g v-else-if="slug === 'bordyury'">
                <rect x="0" y="140" width="300" height="80" :fill="deep" />
                <g v-for="i in 5" :key="i">
                    <rect :x="-10 + (i - 1) * 66" y="112" width="62" height="34" rx="3" :fill="base" />
                    <rect :x="-10 + (i - 1) * 66" y="106" width="62" height="8" rx="2" :fill="light" />
                </g>
                <rect x="0" y="60" width="300" height="46" fill="#12161A" />
            </g>

            <!-- Вазон: усечённый конус с растением -->
            <g v-else-if="slug === 'vazony'">
                <ellipse cx="150" cy="72" rx="66" ry="16" :fill="light" />
                <path d="M84 72 L104 178 H196 L216 72 Z" :fill="base" />
                <path d="M150 72 L196 178 H150 Z" :fill="dark" opacity="0.55" />
                <ellipse cx="150" cy="178" rx="46" ry="11" :fill="deep" />
                <path d="M150 70 C132 44 120 36 112 26 M150 70 C168 46 182 40 192 30 M150 70 C150 44 150 34 150 22"
                    :stroke="'#4A6B5B'" stroke-width="5" stroke-linecap="round" fill="none" />
            </g>

            <!-- Скамья: опоры из композита + сиденье -->
            <g v-else-if="slug === 'skami'">
                <rect x="46" y="96" width="30" height="76" rx="4" :fill="base" />
                <rect x="224" y="96" width="30" height="76" rx="4" :fill="base" />
                <rect x="46" y="164" width="208" height="10" rx="3" :fill="deep" />
                <g v-for="i in 4" :key="i">
                    <rect x="34" :y="80 + (i - 1) * 13" width="232" height="9" rx="4" fill="#8A6B4A" />
                </g>
                <rect x="34" y="52" width="232" height="9" rx="4" fill="#8A6B4A" opacity="0.85" />
                <rect x="34" y="38" width="232" height="9" rx="4" fill="#8A6B4A" opacity="0.7" />
            </g>

            <!-- Урна: корпус с вкладышем -->
            <g v-else-if="slug === 'urny'">
                <path d="M112 66 L120 176 H180 L188 66 Z" :fill="base" />
                <path d="M150 66 L180 176 H150 Z" :fill="dark" opacity="0.5" />
                <ellipse cx="150" cy="66" rx="38" ry="10" :fill="light" />
                <ellipse cx="150" cy="66" rx="28" ry="7" fill="#0C0E11" />
                <ellipse cx="150" cy="176" rx="30" ry="8" :fill="deep" />
            </g>

            <!-- Ступени и облицовка: каскад плит -->
            <g v-else>
                <g v-for="i in 4" :key="i">
                    <rect :x="30 + (i - 1) * 14" :y="60 + (i - 1) * 32" :width="240 - (i - 1) * 28" height="22" rx="3" :fill="base" />
                    <rect :x="30 + (i - 1) * 14" :y="82 + (i - 1) * 32" :width="240 - (i - 1) * 28" height="10" rx="2" :fill="dark" />
                </g>
            </g>

            <!-- Мягкая виньетка: изображение садится в тёмную сцену -->
            <rect width="300" height="220" fill="url(#vignette)" opacity="0.5" />
            <defs>
                <radialGradient id="vignette" cx="0.5" cy="0.45" r="0.75">
                    <stop offset="0.55" stop-color="#000" stop-opacity="0" />
                    <stop offset="1" stop-color="#000" stop-opacity="0.8" />
                </radialGradient>
            </defs>
        </svg>
    </div>
</template>
