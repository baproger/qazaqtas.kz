<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import SiteLayout from '@/Layouts/SiteLayout.vue';
import ProductVisual from '@/Components/site/ProductVisual.vue';
import ProductCard from '@/Components/site/ProductCard.vue';
import { money, number, recent, favorites } from '@/utils/site';
import { useT, useSiteRoute } from '@/composables/useTranslations';

const t = useT();
const { siteRoute } = useSiteRoute();

const props = defineProps({
    product: { type: Object, required: true },
    related: { type: Array, default: () => [] },
    seo: { type: Object, default: () => ({}) },
});

const color = ref(props.product.colors?.[0] ?? null);
const activeIndex = ref(0);

/**
 * Галерея под выбранный цвет: снимки этого цвета + снимки без привязки.
 * Если фото конкретно этого цвета нет — показываем всё, что есть, и честно
 * говорим об этом: цвет у композита сквозной, фактура на всех одинаковая.
 */
const allImages = computed(() => props.product.images ?? []);
const colorImages = computed(() => allImages.value.filter((i) => i.color === color.value?.name));
const gallery = computed(() => {
    if (!allImages.value.length) return [];
    const universal = allImages.value.filter((i) => !i.color);
    return colorImages.value.length ? [...colorImages.value, ...universal] : allImages.value;
});
const activeImage = computed(() => gallery.value[activeIndex.value] ?? gallery.value[0] ?? null);
const hasColorPhoto = computed(() => colorImages.value.length > 0);

// Смена цвета всегда показывает первый снимок новой подборки.
watch(color, () => (activeIndex.value = 0));
const quantity = ref(Number(props.product.min_order) || 1);
const area = ref(''); // калькулятор площади: м² → количество
const isFavorite = ref(false);
const tab = ref('specs');

const perM2 = computed(() => Number(props.product.specs?.pieces_per_m2) || null);
const isAreaBased = computed(() => props.product.unit === 'м²');

/** Из площади считаем количество с запасом на подрезку 5 %. */
const areaResult = computed(() => {
    const a = Number(area.value);
    if (!a || a <= 0) return null;
    const withWaste = a * 1.05;
    return {
        quantity: withWaste,
        pieces: perM2.value ? Math.ceil(withWaste * perM2.value) : null,
        sum: withWaste * Number(props.product.price),
    };
});

const total = computed(() => Number(quantity.value || 0) * Number(props.product.price));

const tabs = computed(() => [
    { key: 'specs', label: t('site.product.tab_specs') },
    { key: 'about', label: t('site.product.tab_about') },
    { key: 'delivery', label: t('site.product.tab_delivery') },
]);

// Ключи характеристик приходят из ERP латиницей — подписи к ним живут в
// словаре, поэтому раздел читается на языке страницы. Незнакомый ключ
// показываем как есть: словарь не должен молча прятать данные каталога.
const specRows = computed(() => Object.entries(props.product.specs ?? {}).map(([key, value]) => ({
    label: t(`site.specs.${key}`, key),
    value,
})));

const addToCart = () => {
    router.post(siteRoute('site.cart.add', props.product.slug), {
        quantity: Number(quantity.value),
        color: color.value?.name ?? null,
    }, { preserveScroll: true });
};

const useAreaResult = () => {
    if (areaResult.value) quantity.value = Number(areaResult.value.quantity.toFixed(2));
};

onMounted(() => {
    recent.push(props.product.id);
    isFavorite.value = favorites.has(props.product.id);
});
</script>

<template>
    <SiteLayout :seo="seo">
        <section class="ambient mx-auto max-w-7xl px-5 py-12 sm:px-8 sm:py-16">
            <nav class="flex flex-wrap items-center gap-2 text-xs text-sand-100/40" :aria-label="$t('site.a11y.breadcrumbs')">
                <Link :href="$r('site.home')" class="transition hover:text-sand-300">{{ $t('site.nav.home') }}</Link><span>/</span>
                <Link :href="$r('site.catalog')" class="transition hover:text-sand-300">{{ $t('site.nav.catalog') }}</Link>
                <template v-if="product.category">
                    <span>/</span>
                    <Link :href="$r('site.catalog', { category: product.category.slug })" class="transition hover:text-sand-300">{{ product.category.name }}</Link>
                </template>
            </nav>

            <div class="mt-8 grid gap-10 lg:grid-cols-2 lg:gap-16">
                <!-- Визуал -->
                <div class="lg:sticky lg:top-28 lg:self-start">
                    <ProductVisual :product="product" :color="color?.hex" :image="activeImage" ratio="aspect-[4/3]" />

                    <!-- Миниатюры галереи -->
                    <div v-if="gallery.length > 1" class="mt-3 flex gap-2 overflow-x-auto pb-1">
                        <button
                            v-for="(img, i) in gallery"
                            :key="img.path"
                            class="h-16 w-20 flex-shrink-0 overflow-hidden rounded-lg border-2 transition"
                            :class="i === activeIndex ? 'border-sand-300' : 'border-white/10 hover:border-white/30'"
                            :aria-label="$t('site.product.photo_n', null, { n: i + 1 })"
                            @click="activeIndex = i"
                        >
                            <img :src="img.thumb ?? img.path" :alt="img.alt || product.name" loading="lazy" class="h-full w-full object-cover" />
                        </button>
                    </div>

                    <div v-if="product.colors?.length" class="mt-6">
                        <p class="eyebrow">{{ $t('site.product.color') }} · {{ color?.name }}</p>
                        <div class="mt-3 flex flex-wrap gap-2.5">
                            <button
                                v-for="c in product.colors"
                                :key="c.hex"
                                class="h-11 w-11 rounded-full border-2 transition"
                                :class="color?.hex === c.hex ? 'border-sand-300 scale-110' : 'border-white/15 hover:border-white/40'"
                                :style="{ background: c.hex }"
                                :title="c.name"
                                :aria-label="c.name"
                                :aria-pressed="color?.hex === c.hex"
                                @click="color = c"
                            />
                        </div>
                        <p class="mt-3 text-xs text-sand-100/40">
                            <template v-if="allImages.length && !hasColorPhoto">
                                {{ $t('site.product.color_no_photo') }}
                            </template>
                            <template v-else>
                                {{ $t('site.product.color_note') }}
                            </template>
                        </p>
                    </div>
                </div>

                <!-- Покупка -->
                <div>
                    <p v-if="product.category" class="eyebrow">{{ product.category.name }}</p>
                    <h1 class="display mt-4 text-[clamp(1.9rem,4.5vw,3.25rem)] text-sand-50">{{ product.name }}</h1>
                    <p v-if="product.short_description" class="mt-4 text-sm text-sand-100/55 sm:text-base">{{ product.short_description }}</p>

                    <div class="mt-8 flex flex-wrap items-baseline gap-3">
                        <span class="display text-4xl text-sand-50">{{ money(product.price) }}</span>
                        <span class="text-sm text-sand-100/45">за {{ product.unit }}</span>
                        <span v-if="product.old_price > 0" class="text-sm text-sand-100/30 line-through">{{ money(product.old_price) }}</span>
                        <span
                            class="ml-auto rounded-full px-3 py-1 text-xs font-medium"
                            :class="product.in_stock ? 'bg-emerald-400/10 text-emerald-300' : 'bg-amber-400/10 text-amber-300'"
                        >{{ product.in_stock ? $t('site.product.in_stock') : $t('site.product.on_order') }}</span>
                    </div>

                    <!-- Калькулятор площади -->
                    <div v-if="isAreaBased" class="card card-sm mt-8 p-5 sm:p-6">
                        <p class="eyebrow">{{ $t('site.product.area_calc') }}</p>
                        <div class="mt-4 flex flex-wrap items-end gap-3">
                            <label class="flex-1">
                                <span class="text-xs text-sand-100/45">{{ $t('site.product.area_label') }}</span>
                                <input
                                    v-model="area"
                                    type="number"
                                    min="0"
                                    step="0.5"
                                    :placeholder="$t('site.product.area_hint')"
                                    class="mt-1.5 w-full rounded-xl border-white/12 bg-white/[0.04] px-4 py-3 text-sand-50 placeholder:text-sand-100/25 focus:border-sand-300 focus:ring-0"
                                />
                            </label>
                            <button class="btn-ghost !py-3" :disabled="!areaResult" @click="useAreaResult">{{ $t('site.product.area_apply') }}</button>
                        </div>
                        <div v-if="areaResult" class="mt-4 space-y-1 text-sm text-sand-100/70">
                            <p>{{ $t('site.product.area_need') }} <b class="text-sand-50">{{ number(areaResult.quantity) }} м²</b> <span class="text-sand-100/40">{{ $t('site.product.area_waste') }}</span></p>
                            <p v-if="areaResult.pieces">{{ $t('site.product.area_pieces') }} <b class="text-sand-50">{{ number(areaResult.pieces, 0) }}</b> {{ $t('site.common.pcs') }}</p>
                            <p>{{ $t('site.product.area_sum') }} <b class="text-sand-300">{{ money(areaResult.sum) }}</b></p>
                        </div>
                    </div>

                    <!-- Количество и корзина -->
                    <div class="mt-8 flex flex-wrap items-center gap-3">
                        <div class="flex items-center rounded-full border border-white/12">
                            <button class="h-12 w-12 text-lg text-sand-100/70 transition hover:text-sand-50" :aria-label="$t('site.cart.less')" @click="quantity = Math.max(Number(product.min_order) || 1, Number(quantity) - 1)">−</button>
                            <input v-model="quantity" type="number" min="0" step="0.5" class="h-12 w-24 border-0 bg-transparent text-center text-sand-50 focus:ring-0" />
                            <button class="h-12 w-12 text-lg text-sand-100/70 transition hover:text-sand-50" :aria-label="$t('site.cart.more')" @click="quantity = Number(quantity) + 1">+</button>
                        </div>
                        <span class="text-sm text-sand-100/45">{{ product.unit }}</span>
                        <button class="btn-cart flex-1 !py-3.5 sm:flex-none" @click="addToCart">
                            {{ $t('site.product.to_cart') }} · {{ money(total) }}
                            <svg class="btn-cart-arrow h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M5 12h14M13 6l6 6-6 6" />
                            </svg>
                        </button>
                        <button
                            class="grid h-12 w-12 place-items-center rounded-full border border-white/12 transition hover:border-sand-300/60"
                            :aria-pressed="isFavorite"
                            :aria-label="$t('site.product.fav_add')"
                            @click="isFavorite = favorites.toggle(product.id).includes(product.id)"
                        >
                            <svg class="h-5 w-5" viewBox="0 0 24 24" :fill="isFavorite ? '#C8B79A' : 'none'" stroke="#C8B79A" stroke-width="1.6">
                                <path d="M12 20s-7-4.35-7-9.5A4.5 4.5 0 0 1 12 7a4.5 4.5 0 0 1 7 3.5C19 15.65 12 20 12 20z" />
                            </svg>
                        </button>
                    </div>

                    <p v-if="product.min_order > 0" class="mt-3 text-xs text-sand-100/35">
                        {{ $t('site.product.min_order', null, { count: number(product.min_order), unit: product.unit }) }}
                    </p>

                    <!-- Вкладки -->
                    <div class="divider-bottom mt-12">
                        <div class="flex gap-6">
                            <button
                                v-for="item in tabs"
                                :key="item.key"
                                class="border-b-2 pb-3 text-sm transition"
                                :class="tab === item.key ? 'border-sand-300 text-sand-50' : 'border-transparent text-sand-100/45 hover:text-sand-100'"
                                @click="tab = item.key"
                            >{{ item.label }}</button>
                        </div>
                    </div>

                    <div class="mt-6 text-sm leading-relaxed text-sand-100/60">
                        <dl v-if="tab === 'specs'" class="divide-y divide-white/5">
                            <div v-for="row in specRows" :key="row.label" class="flex justify-between gap-6 py-3">
                                <dt class="text-sand-100/45">{{ row.label }}</dt>
                                <dd class="text-right text-sand-50">{{ row.value }}</dd>
                            </div>
                            <div v-if="!specRows.length" class="py-3 text-sand-100/40">{{ $t('site.product.specs_empty') }}</div>
                        </dl>

                        <p v-else-if="tab === 'about'">{{ product.description }}</p>

                        <div v-else class="space-y-3">
                            <p>{{ $t('site.product.delivery_1') }}</p>
                            <p>{{ $t('site.product.delivery_2') }}</p>
                        </div>
                    </div>

                    <!-- Документы -->
                    <div v-if="product.documents?.length" class="mt-8">
                        <p class="eyebrow">{{ $t('site.product.documents') }}</p>
                        <ul class="mt-3 space-y-2">
                            <li v-for="d in product.documents" :key="d.path">
                                <a :href="d.path" target="_blank" rel="noopener" class="text-sm text-sand-300 underline-offset-4 hover:underline">↓ {{ d.name }}</a>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </section>

        <!-- Похожие -->
        <section v-if="related.length" >
            <div class="mx-auto max-w-7xl px-5 py-20 sm:px-8">
                <h2 class="display text-2xl text-sand-50 sm:text-3xl">{{ $t('site.product.similar') }}</h2>
                <div class="mt-8 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                    <ProductCard v-for="p in related" :key="p.id" :product="p" compact />
                </div>
            </div>
        </section>
    </SiteLayout>
</template>
