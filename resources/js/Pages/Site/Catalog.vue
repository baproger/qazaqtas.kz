<script setup>
import { computed, nextTick, onMounted, onBeforeUnmount, ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import SiteLayout from '@/Layouts/SiteLayout.vue';
import ProductCard from '@/Components/site/ProductCard.vue';
import { favorites, recent, observeReveal } from '@/utils/site';
import { usePageLinks } from '@/utils/pagination';
import { useT, useSiteRoute } from '@/composables/useTranslations';

const t = useT();
const { siteRoute } = useSiteRoute();

const props = defineProps({
    categories: { type: Array, default: () => [] },
    products: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    bounds: { type: Object, default: () => ({ min: 0, max: 0 }) },
    currentCategory: { type: Object, default: null },
    seo: { type: Object, default: () => ({}) },
});

const search = ref(props.filters.search ?? '');
const sort = ref(props.filters.sort ?? 'popular');
const min = ref(props.filters.min ?? '');
const max = ref(props.filters.max ?? '');
const favIds = ref([]);
const recentProducts = ref([]);
const filtersOpen = ref(false);
let stopReveal = () => {};
let searchTimer = null;

// Подписи сортировки считаются заново при смене языка — литеральный массив
// остался бы на языке первой загрузки.
const sorts = computed(() => [
    { key: 'popular', label: t('site.catalog.sort.popular') },
    { key: 'price_asc', label: t('site.catalog.sort.price_asc') },
    { key: 'price_desc', label: t('site.catalog.sort.price_desc') },
    { key: 'name', label: t('site.catalog.sort.name') },
]);

const apply = (extra = {}) => {
    router.get(siteRoute('site.catalog'), {
        category: 'category' in extra ? extra.category : (props.filters.category || undefined),
        search: search.value || undefined,
        sort: sort.value !== 'popular' ? sort.value : undefined,
        min: min.value || undefined,
        max: max.value || undefined,
        ...extra,
    }, { preserveState: true, preserveScroll: true, replace: true });
};

watch(search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(apply, 350);
});
watch([sort, min, max], () => apply());

const reset = () => {
    search.value = '';
    sort.value = 'popular';
    min.value = '';
    max.value = '';
    router.get(siteRoute('site.catalog'), {}, { preserveScroll: true });
};

const toggleFavorite = (id) => (favIds.value = favorites.toggle(id));

const pageLinks = usePageLinks(() => props.products.links);

/**
 * Смена категории и сортировка идут без перезагрузки страницы: экземпляр
 * компонента сохраняется, поэтому onMounted второй раз не срабатывает. Новые
 * карточки при этом рождаются с классом .reveal — то есть прозрачными — и
 * без повторной подписки так и остаются невидимыми. Переподписываемся на
 * каждый новый набор товаров.
 */
watch(() => props.products.data, () => nextTick(() => {
    stopReveal();
    stopReveal = observeReveal();
}));

onMounted(async () => {
    stopReveal = observeReveal();
    favIds.value = favorites.all();

    // Недавно просмотренные подтягиваем из ERP по id из localStorage.
    const ids = recent.all();
    if (ids.length) {
        try {
            const res = await fetch(`${siteRoute('site.recent')}?ids=${ids.join(',')}`, { headers: { Accept: 'application/json' } });
            recentProducts.value = res.ok ? await res.json() : [];
        } catch {
            recentProducts.value = [];
        }
    }
});

onBeforeUnmount(() => stopReveal());
</script>

<template>
    <SiteLayout :seo="seo">
        <!-- Шапка раздела -->
        <section>
            <div class="mx-auto max-w-7xl px-5 py-12 sm:px-8 sm:py-16">
                <nav class="flex items-center gap-2 text-xs text-sand-100/40" :aria-label="$t('site.a11y.breadcrumbs')">
                    <Link :href="$r('site.home')" class="transition hover:text-sand-300">{{ $t('site.nav.home') }}</Link>
                    <span>/</span>
                    <Link :href="$r('site.catalog')" class="transition hover:text-sand-300">{{ $t('site.nav.catalog') }}</Link>
                    <template v-if="currentCategory">
                        <span>/</span><span class="text-sand-100/70">{{ currentCategory.name }}</span>
                    </template>
                </nav>

                <h1 class="display mt-5 max-w-3xl text-[clamp(2rem,5vw,3.5rem)] text-sand-50">
                    {{ currentCategory?.name ?? $t('site.catalog.title') }}
                </h1>
                <p class="mt-5 max-w-2xl text-sm leading-relaxed text-sand-100/55 sm:text-base">
                    {{ currentCategory?.description ?? $t('site.catalog.lead') }}
                </p>
            </div>
        </section>

        <section class="ambient mx-auto max-w-7xl px-5 py-10 sm:px-8 sm:py-14">
            <!-- Фильтры: одна стеклянная панель, как в «Услугах» -->
            <div class="card mb-8 rounded-3xl p-5 backdrop-blur-xl">
                <!-- Категории: сегменты в скролл-ленте, единый стиль с «Услугами» -->
                <div class="-mx-1 mb-4 flex gap-2 overflow-x-auto px-1 pb-1">
                    <!-- Кнопки, а не ссылки: фильтр меняется без «перезагрузки» —
                         прокрутка и состояние страницы остаются на месте. -->
                    <button type="button" @click="apply({ category: undefined })" class="shrink-0 rounded-xl px-4 py-2 text-sm font-medium transition"
                        :class="!filters.category ? 'bg-sand-300 text-ink-900' : 'bg-sand-100/5 text-sand-100/60 hover:bg-sand-100/10 hover:text-sand-50'">{{ $t('site.catalog.all') }}</button>
                    <button v-for="c in categories" :key="c.slug" type="button" @click="apply({ category: c.slug })"
                        class="shrink-0 rounded-xl px-4 py-2 text-sm font-medium transition"
                        :class="filters.category === c.slug ? 'bg-sand-300 text-ink-900' : 'bg-sand-100/5 text-sand-100/60 hover:bg-sand-100/10 hover:text-sand-50'">{{ c.name }}</button>
                </div>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-[1fr_auto_auto_auto]">
                    <!-- Поиск -->
                    <label class="relative block">
                        <svg class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-sand-100/30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
                        <input v-model="search" type="search" :placeholder="$t('site.catalog.search_hint')"
                            class="h-11 w-full rounded-xl border-0 bg-sand-100/5 pl-10 pr-4 text-sm text-sand-50 placeholder-sand-100/30 ring-1 ring-inset ring-sand-100/10 transition focus:bg-sand-100/10 focus:ring-2 focus:ring-sand-300/60" />
                    </label>
                    <!-- Цена от—до -->
                    <div class="flex items-center gap-2">
                        <label class="relative block">
                            <input v-model="min" type="number" min="0" :placeholder="String(Math.round(bounds.min))"
                                class="h-11 w-28 rounded-xl border-0 bg-sand-100/5 px-3 pr-7 text-sm text-sand-50 placeholder-sand-100/30 ring-1 ring-inset ring-sand-100/10 transition focus:ring-2 focus:ring-sand-300/60" />
                            <span class="pointer-events-none absolute right-2.5 top-1/2 -translate-y-1/2 text-xs text-sand-100/40">₸</span>
                        </label>
                        <span class="text-sand-100/30">—</span>
                        <label class="relative block">
                            <input v-model="max" type="number" min="0" :placeholder="String(Math.round(bounds.max))"
                                class="h-11 w-28 rounded-xl border-0 bg-sand-100/5 px-3 pr-7 text-sm text-sand-50 placeholder-sand-100/30 ring-1 ring-inset ring-sand-100/10 transition focus:ring-2 focus:ring-sand-300/60" />
                            <span class="pointer-events-none absolute right-2.5 top-1/2 -translate-y-1/2 text-xs text-sand-100/40">₸</span>
                        </label>
                    </div>
                    <!-- Сортировка: сегменты -->
                    <div class="flex h-11 items-center overflow-x-auto rounded-xl bg-sand-100/5 p-1 ring-1 ring-inset ring-sand-100/10">
                        <button v-for="sOpt in sorts" :key="sOpt.key" type="button" @click="sort = sOpt.key"
                            class="h-full shrink-0 whitespace-nowrap rounded-lg px-3 text-sm font-medium transition"
                            :class="sort === sOpt.key ? 'bg-sand-300 text-ink-900' : 'text-sand-100/50 hover:text-sand-50'">{{ sOpt.label }}</button>
                    </div>
                    <!-- Итог + сброс -->
                    <div class="flex h-11 items-center justify-end gap-3 text-sm text-sand-100/40">
                        <span>{{ $t('site.catalog.found', null, { count: products.total }) }}</span>
                        <button v-if="search || min || max || sort !== 'popular' || filters.category" type="button" @click="reset"
                            class="rounded-lg px-2 py-1 text-xs text-sand-100/50 transition hover:bg-sand-100/10 hover:text-sand-50">✕ {{ $t('site.catalog.reset') }}</button>
                    </div>
                </div>
            </div>

            <!-- Товары: 4 колонки -->
            <div>
                    <div v-if="products.data.length" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 lg:gap-5">
                        <div v-for="p in products.data" :key="p.id" class="reveal flex flex-col">
                            <ProductCard
                                :product="p"
                                :favorite="favIds.includes(p.id)"
                                @favorite="toggleFavorite"
                            />
                        </div>
                    </div>

                    <div v-else class="card px-8 py-20 text-center">
                        <p class="display text-2xl text-sand-50">{{ $t('site.catalog.empty_title') }}</p>
                        <p class="mt-3 text-sm text-sand-100/50">{{ $t('site.catalog.empty_lead') }}</p>
                        <button class="btn-ghost mt-8" @click="reset">{{ $t('site.catalog.reset') }}</button>
                    </div>

                    <!-- Пагинация -->
                    <nav v-if="products.last_page > 1" class="mt-12 flex flex-wrap justify-center gap-2">
                        <Link
                            v-for="link in pageLinks"
                            :key="link.key"
                            :href="link.url ?? ''"
                            preserve-scroll
                            class="grid h-10 min-w-10 place-items-center rounded-xl border px-3 text-center text-sm transition"
                            :class="[
                                link.active ? 'border-sand-300 bg-sand-300 text-ink-900' : 'border-white/12 text-sand-100/60 hover:border-sand-300/50',
                                !link.url && 'pointer-events-none opacity-30',
                            ]"
                            :aria-label="link.aria"
                        >
                            <svg v-if="link.arrow" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                <path :d="link.arrow === 'prev' ? 'M19 12H5M11 18l-6-6 6-6' : 'M5 12h14M13 6l6 6-6 6'" />
                            </svg>
                            <template v-else>{{ link.label }}</template>
                        </Link>
                    </nav>

                    <!-- Недавно смотрели -->
                    <section v-if="recentProducts.length" class="mt-20">
                        <h2 class="display text-2xl text-sand-50">{{ $t('site.catalog.recent') }}</h2>
                        <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <ProductCard v-for="p in recentProducts.slice(0, 4)" :key="p.id" :product="p" compact />
                        </div>
                    </section>
            </div>
        </section>
    </SiteLayout>
</template>
