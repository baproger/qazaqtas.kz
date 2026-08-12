<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Link } from '@inertiajs/vue3';

/**
 * Сегмент-контрол категорий. Активную позицию подсвечивает не рамка у
 * каждой ссылки, а одна плашка, которая переезжает между ними — глаз
 * следит за движением и понимает, что раздел сменился.
 */
const props = defineProps({
    categories: { type: Array, default: () => [] },
    current: { type: String, default: '' },
});

const bar = ref(null);
const items = ref([]);

/** «Все» — такой же сегмент, просто без slug. */
const segments = computed(() => [{ id: 'all', slug: '', name: 'Все' }, ...props.categories]);

const activeIndex = computed(() => Math.max(0, segments.value.findIndex((s) => s.slug === (props.current ?? ''))));

const pill = ref({ left: 0, width: 0, ready: false });

const measure = () => {
    const el = items.value[activeIndex.value];
    if (!el || !bar.value) return;
    pill.value = {
        left: el.offsetLeft,
        width: el.offsetWidth,
        ready: true,
    };
    // Активный сегмент может уехать за край на узком экране.
    el.scrollIntoView({ block: 'nearest', inline: 'nearest', behavior: pill.value.ready ? 'smooth' : 'auto' });
};

watch(activeIndex, () => nextTick(measure));
onMounted(() => {
    nextTick(measure);
    window.addEventListener('resize', measure, { passive: true });
});
onBeforeUnmount(() => window.removeEventListener('resize', measure));
</script>

<template>
    <div class="segmented" ref="bar">
        <!-- Плашка едет под сегментами; до первого замера её не видно,
             иначе она прыгает из левого угла. -->
        <span
            class="segmented-pill"
            :style="{ transform: `translateX(${pill.left}px)`, width: `${pill.width}px`, opacity: pill.ready ? 1 : 0 }"
            aria-hidden="true"
        />
        <Link
            v-for="(s, i) in segments"
            :key="s.id"
            :ref="(el) => (items[i] = el?.$el ?? el)"
            :href="s.slug ? route('site.catalog', { category: s.slug }) : route('site.catalog')"
            preserve-scroll
            class="segmented-item"
            :class="i === activeIndex ? 'is-active' : ''"
            :aria-current="i === activeIndex ? 'page' : undefined"
        >{{ s.name }}</Link>
    </div>
</template>
