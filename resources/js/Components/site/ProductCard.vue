<script setup>
import { computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import ProductVisual from './ProductVisual.vue';
import { money } from '@/utils/site';

const props = defineProps({
    product: { type: Object, required: true },
    compact: { type: Boolean, default: false },
});

const emit = defineEmits(['favorite']);

const favorite = defineModel('favorite', { type: Boolean, default: false });

const perUnit = computed(() => `${money(props.product.price)} / ${props.product.unit}`);

const addToCart = () => {
    router.post(route('site.cart.add', props.product.slug), {
        quantity: Number(props.product.min_order) || 1,
    }, { preserveScroll: true });
};
</script>

<template>
    <article class="group relative flex flex-col overflow-hidden rounded-3xl border border-white/10 bg-ink-800/60 transition duration-500 ease-premium hover:-translate-y-1 hover:border-sand-300/40">
        <Link :href="route('site.product', product.slug)" class="block" :aria-label="product.name">
            <ProductVisual :product="product" :ratio="compact ? 'aspect-[16/10]' : 'aspect-[4/3]'" />
        </Link>

        <button
            class="absolute right-4 top-4 grid h-9 w-9 place-items-center rounded-full border border-white/15 bg-ink-900/60 backdrop-blur transition hover:border-sand-300/60"
            :aria-pressed="favorite"
            :aria-label="favorite ? 'Убрать из избранного' : 'В избранное'"
            @click.prevent="favorite = !favorite; emit('favorite', product.id)"
        >
            <svg class="h-4 w-4" viewBox="0 0 24 24" :fill="favorite ? '#C8B79A' : 'none'" stroke="#C8B79A" stroke-width="1.6">
                <path d="M12 20s-7-4.35-7-9.5A4.5 4.5 0 0 1 12 7a4.5 4.5 0 0 1 7 3.5C19 15.65 12 20 12 20z" />
            </svg>
        </button>

        <div class="flex flex-1 flex-col p-5 sm:p-6">
            <p v-if="product.category" class="eyebrow">{{ product.category.name }}</p>

            <h3 class="mt-2 text-lg font-medium leading-snug text-sand-50">
                <Link :href="route('site.product', product.slug)" class="transition hover:text-sand-300">{{ product.name }}</Link>
            </h3>

            <p v-if="product.short_description" class="mt-2 line-clamp-2 text-sm text-sand-100/50">
                {{ product.short_description }}
            </p>

            <div class="mt-5 flex items-end justify-between gap-4 pt-4">
                <div>
                    <p class="text-xl font-semibold text-sand-50">{{ perUnit }}</p>
                    <p v-if="product.old_price > 0" class="text-xs text-sand-100/40 line-through">{{ money(product.old_price) }}</p>
                </div>
                <button class="btn-sand !px-5 !py-2.5 opacity-0 transition group-hover:opacity-100 focus-visible:opacity-100" @click="addToCart">
                    В корзину
                </button>
            </div>

            <!-- На тач-устройствах кнопка всегда видна -->
            <button class="btn-sand mt-4 w-full sm:hidden" @click="addToCart">В корзину</button>
        </div>
    </article>
</template>
