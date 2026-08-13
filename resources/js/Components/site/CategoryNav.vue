<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useT, useSiteRoute } from '@/composables/useTranslations';

const t = useT();
const { siteRoute } = useSiteRoute();

/**
 * Сегмент-контрол категорий.
 *
 * Плашка — один общий элемент, который переезжает между сегментами. Её
 * геометрия целиком на transform: позиция через translateX, ширина через
 * scaleX. Ни width, ни left не анимируются — раскладка не пересчитывается,
 * и рендер-цикл Three.js на других экранах не задевается.
 *
 * Масштаб по горизонтали вытянул бы углы капсулы в эллипс, поэтому
 * горизонтальный радиус делим на тот же множитель: border-radius принимает
 * горизонтальный и вертикальный радиусы раздельно, и после scaleX капсула
 * отрисовывается ровной.
 */
const props = defineProps({
    categories: { type: Array, default: () => [] },
    current: { type: String, default: '' },
});

/** Опорная ширина плашки: от неё считается множитель scaleX. */
const BASE_WIDTH = 100;

const bar = ref(null);
const items = ref([]);

/** «Все» — такой же сегмент, просто без slug. */
const segments = computed(() => [
    { id: 'all', slug: '', name: t('site.catalog.all_categories') },
    ...props.categories,
]);

const activeIndex = computed(() => {
    const found = segments.value.findIndex((s) => s.slug === (props.current ?? ''));
    return found === -1 ? 0 : found;
});

const pill = ref({ x: 0, scale: 1, radius: 18, ready: false });

/**
 * Первый замер выставляет плашку без перехода: иначе при заходе на страницу
 * она вылетала бы из левого угла к активной категории.
 */
const instant = ref(true);

const place = (el) => {
    if (!el) return;
    const height = el.offsetHeight || 36;
    pill.value = {
        x: el.offsetLeft,
        scale: (el.offsetWidth || BASE_WIDTH) / BASE_WIDTH,
        // 13px вместо половины высоты: плашка перестаёт быть капсулой и
        // ложится в общий язык форм витрины.
        radius: 13,
        ready: true,
    };
};

const measure = () => place(items.value[activeIndex.value]);

/**
 * Двигаем плашку по клику, не дожидаясь ответа сервера: отклик мгновенный, а
 * когда придут новые пропсы, позиция уже совпадает и повторной анимации нет.
 */
const onSelect = (event, index) => {
    instant.value = false;
    place(event.currentTarget);
    // Активный сегмент может стоять за краем на узком экране.
    items.value[index]?.scrollIntoView({ block: 'nearest', inline: 'nearest', behavior: 'smooth' });
};

const style = computed(() => ({
    width: `${BASE_WIDTH}px`,
    transform: `translate3d(${pill.value.x}px, 0, 0) scaleX(${pill.value.scale})`,
    borderRadius: `${pill.value.radius / pill.value.scale}px / ${pill.value.radius}px`,
    opacity: pill.value.ready ? 1 : 0,
}));

let observer = null;

onMounted(() => {
    nextTick(() => {
        measure();
        // Разрешаем анимацию только после того, как плашка встала на место.
        requestAnimationFrame(() => (instant.value = false));
    });

    // Шрифт догружается, панель меняет ширину при повороте экрана — следим за
    // самой панелью, а не за окном: срабатывает точнее и реже.
    if (window.ResizeObserver) {
        observer = new ResizeObserver(() => {
            instant.value = true;
            measure();
            requestAnimationFrame(() => (instant.value = false));
        });
        if (bar.value) observer.observe(bar.value);
    }
});

onBeforeUnmount(() => observer?.disconnect());
</script>

<template>
    <div ref="bar" class="segmented">
        <span
            class="segmented-pill"
            :class="instant ? 'is-instant' : ''"
            :style="style"
            aria-hidden="true"
        />

        <Link
            v-for="(s, i) in segments"
            :key="s.id"
            :ref="(el) => (items[i] = el?.$el ?? el)"
            :href="s.slug ? siteRoute('site.catalog', { category: s.slug }) : siteRoute('site.catalog')"
            preserve-scroll
            preserve-state
            class="segmented-item"
            :class="i === activeIndex ? 'is-active' : ''"
            :aria-current="i === activeIndex ? 'page' : undefined"
            @click="onSelect($event, i)"
        >{{ s.name }}</Link>
    </div>
</template>
