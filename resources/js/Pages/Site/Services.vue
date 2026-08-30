<script setup>
/**
 * Каталог услуг: фильтры (категория, город, цена, сортировка, поиск) и
 * плотная сетка в 4 колонки с акцентом на цену.
 */
import { ref, onMounted, onBeforeUnmount } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import SiteLayout from '@/Layouts/SiteLayout.vue';
import { siteRoute } from '@/i18n';
import { observeReveal } from '@/utils/site';

const props = defineProps({ services: Object, categories: Array, filters: Object, cities: Array, seo: Object });
const search = ref(props.filters.search ?? '');
const city = ref(props.filters.city ?? '');
const sort = ref(props.filters.sort ?? 'new');
const priceMax = ref(props.filters.price_max ?? '');
let timer = null;
const apply = (extra = {}) => router.get(siteRoute('site.services'), {
    category: props.filters.category || undefined, search: search.value || undefined,
    city: city.value || undefined, sort: sort.value !== 'new' ? sort.value : undefined,
    price_max: priceMax.value || undefined, ...extra,
}, { preserveState: true, preserveScroll: true, replace: true });
const debounced = () => { clearTimeout(timer); timer = setTimeout(apply, 400); };
const reset = () => { search.value = ''; city.value = ''; sort.value = 'new'; priceMax.value = ''; apply({ category: undefined }); };
const hasFilters = () => props.filters.category || search.value || city.value || priceMax.value || sort.value !== 'new';
const money = (v) => new Intl.NumberFormat('ru-RU').format(Math.round(v));

let stopReveal = () => {};
onMounted(() => (stopReveal = observeReveal()));
onBeforeUnmount(() => stopReveal());
const field = 'rounded-full border-sand-100/15 bg-transparent px-4 py-1.5 text-sm text-sand-50 focus:border-sand-300 focus:ring-sand-300';
</script>

<template>
    <SiteLayout :seo="seo">
        <section>
            <div class="mx-auto max-w-7xl px-5 py-14 sm:px-8 sm:py-20">
                <p class="eyebrow">{{ $t('site.services.eyebrow') }}</p>
                <h1 class="display mt-5 max-w-3xl text-[clamp(2rem,5vw,3.5rem)] text-sand-50">{{ $t('site.services.title') }}</h1>
            </div>
        </section>

        <section class="ambient mx-auto max-w-7xl px-5 pb-16 sm:px-8 sm:pb-24">
            <!-- Фильтры -->
            <div class="card mb-8 rounded-3xl p-4">
                <div class="flex flex-wrap items-center gap-2">
                    <Link :href="$r('site.services')" class="rounded-full border px-4 py-1.5 text-sm transition"
                        :class="!filters.category ? 'border-sand-300 bg-sand-300/15 text-sand-50' : 'border-sand-100/15 text-sand-100/60 hover:text-sand-50'">{{ $t('site.services.all') }}</Link>
                    <Link v-for="c in categories" :key="c.id" :href="$r('site.services', { category: c.slug })" class="rounded-full border px-4 py-1.5 text-sm transition"
                        :class="filters.category === c.slug ? 'border-sand-300 bg-sand-300/15 text-sand-50' : 'border-sand-100/15 text-sand-100/60 hover:text-sand-50'">{{ c.name }} <span class="opacity-50">{{ c.n }}</span></Link>
                </div>
                <div class="mt-3 flex flex-wrap items-center gap-2">
                    <select v-if="cities.length" v-model="city" @change="apply()" :class="field">
                        <option value="" class="bg-ink-800">📍 {{ $t('site.services.any_city') }}</option>
                        <option v-for="c in cities" :key="c" :value="c" class="bg-ink-800">{{ c }}</option>
                    </select>
                    <select v-model="sort" @change="apply()" :class="field">
                        <option value="new" class="bg-ink-800">{{ $t('site.services.sort_new') }}</option>
                        <option value="cheap" class="bg-ink-800">{{ $t('site.services.sort_cheap') }}</option>
                        <option value="expensive" class="bg-ink-800">{{ $t('site.services.sort_expensive') }}</option>
                    </select>
                    <input v-model="priceMax" @input="debounced" type="number" min="0" step="1000" :placeholder="$t('site.services.price_to')" :class="field + ' w-36'" />
                    <input v-model="search" @input="debounced" type="search" :placeholder="$t('site.services.search')" :class="field + ' min-w-48 flex-1'" />
                    <button v-if="hasFilters()" type="button" @click="reset" class="rounded-full px-3 py-1.5 text-xs text-sand-100/50 transition hover:text-sand-50">✕ {{ $t('site.services.reset') }}</button>
                </div>
            </div>

            <!-- Сетка 4 колонки, акцент на цену -->
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                <Link v-for="s in services.data" :key="s.id" :href="$r('site.service', s.slug)" class="card card-hover reveal group overflow-hidden rounded-3xl">
                    <div class="relative aspect-[4/3] overflow-hidden">
                        <picture v-if="s.photo">
                            <source v-if="s.photo_webp" :srcset="s.photo_webp" type="image/webp" />
                            <img :src="s.thumb || s.photo" :alt="s.title" loading="lazy" decoding="async" class="h-full w-full object-cover transition duration-700 ease-premium group-hover:scale-105" />
                        </picture>
                        <div v-else class="paving-pattern h-full w-full" />
                        <div class="pointer-events-none absolute inset-0 bg-gradient-to-t from-ink-900/80 via-transparent to-transparent" />
                        <span v-if="s.category" class="absolute left-3 top-3 rounded-full bg-ink-900/60 px-2.5 py-0.5 text-[11px] text-sand-100/80 backdrop-blur">{{ s.category.name }}</span>
                        <!-- Цена — главный акцент карточки -->
                        <div class="absolute bottom-3 left-3 right-3 flex items-end justify-between gap-2">
                            <span class="display text-xl leading-none text-sand-300">
                                <template v-if="s.price"><span class="text-xs text-sand-100/60">{{ $t('site.services.price_from') }} </span>{{ money(s.price) }} ₸</template>
                                <template v-else><span class="text-sm">{{ $t('site.services.negotiable') }}</span></template>
                            </span>
                            <span v-if="s.city" class="text-[11px] text-sand-100/50">📍 {{ s.city }}</span>
                        </div>
                    </div>
                    <div class="p-4">
                        <h2 class="line-clamp-2 text-sm font-semibold leading-snug text-sand-50">{{ s.title }}</h2>
                        <p class="mt-1 line-clamp-1 text-xs text-sand-100/40">{{ s.description }}</p>
                    </div>
                </Link>
            </div>
            <p v-if="!services.data.length" class="card mt-4 rounded-3xl p-10 text-center text-sm text-sand-100/50">{{ $t('site.services.empty') }}</p>
        </section>
    </SiteLayout>
</template>
