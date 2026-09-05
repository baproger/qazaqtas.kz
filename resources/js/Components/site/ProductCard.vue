<script setup>
import { computed, onBeforeUnmount, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import ProductVisual from './ProductVisual.vue';
import { money } from '@/utils/site';
import { useSiteRoute } from '@/composables/useTranslations';

const { siteRoute } = useSiteRoute();

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
    router.post(siteRoute('site.cart.add', props.product.slug), {
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
        <div class="relative m-2 overflow-hidden rounded-2xl">
            <Link :href="$r('site.product', product.slug)" class="block overflow-hidden" :aria-label="product.name">
                <ProductVisual :product="product" ratio="aspect-square" shape="rounded-none" />
            </Link>

            <button
                class="absolute right-2.5 top-2.5 grid h-9 w-9 place-items-center rounded-full border border-white/20 bg-black/45 backdrop-blur-md transition hover:border-sand-300/60"
                :aria-pressed="favorite"
                :aria-label="favorite ? $t('site.product.fav_remove') : $t('site.product.fav_add')"
                @click.prevent="favorite = !favorite; emit('favorite', product.id)"
            >
                <svg class="h-4 w-4" viewBox="0 0 24 24" :fill="favorite ? '#C8B79A' : 'none'" stroke="#C8B79A" stroke-width="1.6">
                    <path d="M12 20s-7-4.35-7-9.5A4.5 4.5 0 0 1 12 7a4.5 4.5 0 0 1 7 3.5C19 15.65 12 20 12 20z" />
                </svg>
            </button>

            <!-- Наличие: важно для решения, поэтому видно сразу на снимке -->
            <span class="absolute left-2.5 top-2.5 inline-flex items-center gap-1.5 rounded-lg bg-black/45 px-2 py-1 text-[11px] font-medium text-white/90 backdrop-blur-md">
                <span class="h-1.5 w-1.5 rounded-full" :class="product.in_stock ? 'bg-emerald-400' : 'bg-amber-400'" />
                {{ product.in_stock ? $t('site.product.in_stock') : $t('site.product.on_order') }}
            </span>
        </div>

        <div class="flex flex-1 flex-col px-4 pb-4 pt-1">
            <p v-if="product.category" class="eyebrow">{{ product.category.name }}</p>

            <h3 class="mt-1.5 line-clamp-2 text-[15px] font-semibold leading-tight text-sand-50">
                <Link :href="$r('site.product', product.slug)" class="transition hover:text-sand-300">{{ product.name }}</Link>
            </h3>

            <!-- Размер чипом: сравнивать позиции удобнее, чем в строке текста -->
            <p v-if="size" class="mt-2 inline-flex w-fit rounded-lg bg-white/[0.06] px-2.5 py-1 text-[11px] text-sand-100/60">
                {{ size }}
            </p>

            <!-- Распорка прижимает цену и кнопку к низу: карточки в ряду
                 заканчиваются на одной линии независимо от длины названия. -->
            <div class="flex-1" />

            <div class="mt-4 border-t border-sand-100/10 pt-3">
                <!-- Одна линия: цена слева, действия справа -->
                <div class="flex items-center justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex items-baseline gap-1.5">
                            <span class="display text-[21px] leading-none tracking-tight text-sand-50">{{ money(product.price).replace(' ₸', '') }}</span>
                            <span class="text-sm font-medium text-sand-300">₸</span>
                            <span class="text-xs text-sand-100/45">/ {{ product.unit }}</span>
                        </div>
                        <p class="mt-1 truncate text-[11px] leading-none text-sand-100/35">
                            <span v-if="product.old_price > 0" class="mr-1.5 line-through">{{ money(product.old_price) }}</span>
                            <template v-if="product.min_order > 0">{{ $t('site.product.min', null, { count: Number(product.min_order), unit: product.unit }) }}</template>
                        </p>
                    </div>

                    <div class="flex shrink-0 items-center gap-1.5">
                        <!-- В корзину: компактная, текст + галочка-подтверждение -->
                        <!-- Только иконка: подпись — в title/aria, цене остаётся вся ширина -->
                        <button
                            class="btn-cart grid h-10 w-10 place-items-center !p-0"
                            :class="state === 'added' ? 'is-added' : ''"
                            :disabled="state === 'adding'"
                            :aria-label="state === 'added' ? $t('site.product.in_cart') : $t('site.product.to_cart')"
                            :title="state === 'added' ? $t('site.product.in_cart') : $t('site.product.to_cart')"
                            @click="addToCart"
                        >
                            <svg v-if="state !== 'added'" class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="9" cy="20" r="1.4"/><circle cx="17" cy="20" r="1.4"/><path d="M3 4h2l2.6 11.2a1.5 1.5 0 0 0 1.47 1.16h7.9a1.5 1.5 0 0 0 1.46-1.13L20.5 8H6"/>
                            </svg>
                            <svg v-else class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 12.5 9.5 18 20 6.5"/></svg>
                        </button>
                        <!-- Перейти к товару -->
                        <Link
                            :href="$r('site.product', product.slug)"
                            class="grid h-10 w-10 flex-shrink-0 place-items-center rounded-xl border border-white/12 text-sand-100/60 transition hover:border-sand-300/60 hover:bg-sand-300 hover:text-ink-900"
                            :aria-label="$t('site.product.more')"
                        >
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 17 17 7M9 7h8v8"/></svg>
                        </Link>
                    </div>
                </div>
            </div>
        </div>
    </article>
</template>
