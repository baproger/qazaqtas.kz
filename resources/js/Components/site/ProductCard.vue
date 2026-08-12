<script setup>
import { computed, onBeforeUnmount, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import ProductVisual from './ProductVisual.vue';
import { money } from '@/utils/site';

const props = defineProps({
    product: { type: Object, required: true },
    compact: { type: Boolean, default: false },
});

const emit = defineEmits(['favorite']);

const favorite = defineModel('favorite', { type: Boolean, default: false });

/** Размер изделия — главная характеристика при выборе, выносим на карточку. */
const size = computed(() => props.product.specs?.size ?? null);

/**
 * Состояние кнопки: idle → adding → added. Галочка держится пару секунд —
 * подтверждение должно случиться на самой кнопке, иначе покупатель жмёт
 * второй раз, не поняв, сработало ли.
 */
const state = ref('idle');
let resetTimer = null;

const addToCart = () => {
    if (state.value === 'adding') return;
    state.value = 'adding';
    router.post(route('site.cart.add', props.product.slug), {
        quantity: Number(props.product.min_order) || 1,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            state.value = 'added';
            clearTimeout(resetTimer);
            resetTimer = setTimeout(() => (state.value = 'idle'), 2200);
        },
        onError: () => (state.value = 'idle'),
    });
};

onBeforeUnmount(() => clearTimeout(resetTimer));
</script>

<template>
    <article class="card card-sm card-lift group flex h-full flex-col overflow-hidden">
        <!-- Изображение: фиксированная пропорция, чтобы плитка карточек
             не «прыгала» при разной высоте фото. -->
        <!-- Зона снимка: своя подложка с внутренней тенью, чтобы изделие
             стояло в нише, а не лежало на плоскости. -->
        <div class="media-well relative">
            <Link :href="route('site.product', product.slug)" class="block overflow-hidden" :aria-label="product.name">
                <ProductVisual :product="product" :ratio="compact ? 'aspect-[16/10]' : 'aspect-[4/3]'" shape="rounded-none" />
            </Link>

            <button
                class="absolute right-3 top-3 grid h-9 w-9 place-items-center rounded-full border border-white/15 bg-ink-900/70 backdrop-blur transition hover:border-sand-300/60"
                :aria-pressed="favorite"
                :aria-label="favorite ? 'Убрать из избранного' : 'В избранное'"
                @click.prevent="favorite = !favorite; emit('favorite', product.id)"
            >
                <svg class="h-4 w-4" viewBox="0 0 24 24" :fill="favorite ? '#C8B79A' : 'none'" stroke="#C8B79A" stroke-width="1.6">
                    <path d="M12 20s-7-4.35-7-9.5A4.5 4.5 0 0 1 12 7a4.5 4.5 0 0 1 7 3.5C19 15.65 12 20 12 20z" />
                </svg>
            </button>

            <!-- Наличие: важно для решения, поэтому видно сразу на снимке -->
            <span class="absolute left-3 top-3 inline-flex items-center gap-1.5 rounded-full border border-white/10 bg-ink-900/75 px-2.5 py-1 text-[11px] font-medium text-sand-50 backdrop-blur">
                <span class="h-1.5 w-1.5 rounded-full" :class="product.in_stock ? 'bg-emerald-400' : 'bg-amber-400'" />
                {{ product.in_stock ? 'На складе' : 'Под заказ' }}
            </span>
        </div>

        <div class="flex flex-1 flex-col p-5 sm:p-6">
            <p v-if="product.category" class="eyebrow">{{ product.category.name }}</p>

            <h3 class="mt-2.5 text-[17px] font-medium leading-snug text-sand-50">
                <Link :href="route('site.product', product.slug)" class="transition hover:text-sand-300">{{ product.name }}</Link>
            </h3>

            <!-- Размер чипом: сравнивать позиции удобнее, чем в строке текста -->
            <p v-if="size" class="mt-3 inline-flex w-fit rounded-full bg-white/[0.06] px-2.5 py-1 text-[11px] text-sand-100/60">
                {{ size }}
            </p>

            <!-- Распорка прижимает цену и кнопку к низу: карточки в ряду
                 заканчиваются на одной линии независимо от длины названия. -->
            <div class="flex-1" />

            <div class="divider-top mt-5 pt-4">
                <div class="flex items-baseline gap-2">
                    <span class="text-xl font-semibold text-sand-50">{{ money(product.price) }}</span>
                    <span class="text-sm text-sand-100/45">/ {{ product.unit }}</span>
                    <span v-if="product.old_price > 0" class="ml-auto text-xs text-sand-100/35 line-through">{{ money(product.old_price) }}</span>
                </div>

                <p v-if="product.min_order > 0" class="mt-1 text-[11px] text-sand-100/35">
                    минимум {{ Number(product.min_order) }} {{ product.unit }}
                </p>

                <!-- Действие видно всегда: скрывать его до наведения — значит
                     терять покупателей на телефоне и на первом взгляде. -->
                <div class="mt-4 flex gap-2">
                    <button
                        class="btn-sand btn-cart flex-1 !px-4 !py-2.5 text-[13px]"
                        :class="state === 'added' ? 'is-added' : ''"
                        :disabled="state === 'adding'"
                        @click="addToCart"
                    >
                        <svg v-if="state !== 'added'" class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M3 4h2l2.4 11.2A2 2 0 0 0 9.35 17h8.4a2 2 0 0 0 1.95-1.55L21 8H6" />
                            <circle cx="10" cy="20" r="1.2" /><circle cx="18" cy="20" r="1.2" />
                        </svg>
                        <svg v-else class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M4 12.5 9.5 18 20 6.5" />
                        </svg>
                        <span>{{ state === 'added' ? 'В корзине' : 'В корзину' }}</span>
                    </button>
                    <Link
                        :href="route('site.product', product.slug)"
                        class="grid h-11 w-11 flex-shrink-0 place-items-center rounded-full border border-white/12 text-sand-100/70 transition hover:border-sand-300/60 hover:text-sand-50"
                        aria-label="Подробнее о товаре"
                    >
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6" /></svg>
                    </Link>
                </div>
            </div>
        </div>
    </article>
</template>
