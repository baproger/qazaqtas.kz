<script setup>
/**
 * Каталог услуг: фильтры (категория, город, цена, сортировка, поиск) и
 * плотная сетка в 4 колонки с акцентом на цену.
 */
import { ref, watch, nextTick, onMounted, onBeforeUnmount } from 'vue';
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
    category: 'category' in extra ? extra.category : (props.filters.category || undefined), search: search.value || undefined,
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
// Фильтры меняют список без перемонтирования (preserveState): новые карточки
// появляются ПОСЛЕ привязки наблюдателя и оставались прозрачными — казалось,
// что «Сбросить» показывает старые данные. Перепривязываем на каждое обновление.
watch(() => props.services.data, async () => {
    await nextTick();
    stopReveal();
    stopReveal = observeReveal();
});
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
            <!-- Фильтры: стеклянная панель, сегменты и подписанные контролы -->
            <div class="spotlight card mb-8 rounded-3xl p-5 backdrop-blur-xl">
                <!-- Категории: скролл-лента чипов -->
                <div class="-mx-1 flex gap-2 overflow-x-auto px-1 pb-1">
                    <button type="button" @click="apply({ category: undefined })" class="shrink-0 rounded-xl px-4 py-2 text-sm font-medium transition"
                        :class="!filters.category ? 'bg-sand-300 text-ink-900' : 'bg-sand-100/5 text-sand-100/60 hover:bg-sand-100/10 hover:text-sand-50'">{{ $t('site.services.all') }}</button>
                    <button v-for="c in categories" :key="c.id" type="button" @click="apply({ category: c.slug })"
                        class="shrink-0 rounded-xl px-4 py-2 text-sm font-medium transition"
                        :class="filters.category === c.slug ? 'bg-sand-300 text-ink-900' : 'bg-sand-100/5 text-sand-100/60 hover:bg-sand-100/10 hover:text-sand-50'">
                        {{ c.name }} <span class="ml-1 text-xs opacity-60">{{ c.n }}</span>
                    </button>
                </div>

                <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-[1fr_auto_auto_auto_auto]">
                    <!-- Поиск -->
                    <label class="relative block">
                        <svg class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-sand-100/30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
                        <input v-model="search" @input="debounced" type="search" :placeholder="$t('site.services.search')"
                            class="h-11 w-full rounded-xl border-0 bg-sand-100/5 pl-10 pr-4 text-sm text-sand-50 placeholder-sand-100/30 ring-1 ring-inset ring-sand-100/10 transition focus:bg-sand-100/10 focus:ring-2 focus:ring-sand-300/60" />
                    </label>
                    <!-- Город -->
                    <label v-if="cities.length" class="relative block">
                        <span class="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-sm">📍</span>
                        <select v-model="city" @change="apply()"
                            class="h-11 w-full appearance-none rounded-xl border-0 bg-sand-100/5 pl-9 pr-9 text-sm text-sand-50 ring-1 ring-inset ring-sand-100/10 transition focus:ring-2 focus:ring-sand-300/60 sm:w-44">
                            <option value="" class="bg-ink-800">{{ $t('site.services.any_city') }}</option>
                            <option v-for="c in cities" :key="c" :value="c" class="bg-ink-800">{{ c }}</option>
                        </select>
                        <svg class="pointer-events-none absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-sand-100/30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="m6 9 6 6 6-6"/></svg>
                    </label>
                    <!-- Цена до -->
                    <label class="relative block">
                        <input v-model="priceMax" @input="debounced" type="number" min="0" step="1000" :placeholder="$t('site.services.price_to')"
                            class="h-11 w-full rounded-xl border-0 bg-sand-100/5 px-4 pr-9 text-sm text-sand-50 placeholder-sand-100/30 ring-1 ring-inset ring-sand-100/10 transition focus:ring-2 focus:ring-sand-300/60 sm:w-40" />
                        <span class="pointer-events-none absolute right-3.5 top-1/2 -translate-y-1/2 text-sm text-sand-100/40">₸</span>
                    </label>
                    <!-- Сортировка: сегменты -->
                    <div class="flex h-11 items-center rounded-xl bg-sand-100/5 p-1 ring-1 ring-inset ring-sand-100/10">
                        <button v-for="o in [['new', $t('site.services.sort_new')], ['cheap', '₸↑'], ['expensive', '₸↓']]" :key="o[0]" type="button"
                            @click="sort = o[0]; apply()" :title="o[0] === 'cheap' ? $t('site.services.sort_cheap') : o[0] === 'expensive' ? $t('site.services.sort_expensive') : ''"
                            class="h-full rounded-lg px-3 text-sm font-medium transition"
                            :class="sort === o[0] ? 'bg-sand-300 text-ink-900' : 'text-sand-100/50 hover:text-sand-50'">{{ o[1] }}</button>
                    </div>
                    <!-- Итог + сброс -->
                    <div class="flex h-11 items-center justify-end gap-3 text-sm text-sand-100/40">
                        <span>{{ services.total ?? services.data.length }} {{ $t('site.services.found') }}</span>
                        <button v-if="hasFilters()" type="button" @click="reset" class="rounded-lg px-2 py-1 text-xs text-sand-100/50 transition hover:bg-sand-100/10 hover:text-sand-50">✕ {{ $t('site.services.reset') }}</button>
                    </div>
                </div>
            </div>

            <!-- Сетка 4 колонки, акцент на цену -->
            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3 lg:gap-6">
                <Link v-for="s in services.data" :key="s.id" :href="$r('site.service', s.slug)" class="spotlight card card-hover reveal group overflow-hidden rounded-3xl">
                    <!-- Фото: чистое, без плашек поверх — только категория -->
                    <div class="relative m-2 aspect-[4/3] overflow-hidden rounded-2xl">
                        <picture v-if="s.photo">
                            <source v-if="s.photo_webp" :srcset="s.photo_webp" type="image/webp" />
                            <img :src="s.thumb || s.photo" :alt="s.title" loading="lazy" decoding="async" class="h-full w-full object-cover transition duration-700 ease-premium group-hover:scale-[1.04]" />
                        </picture>
                        <div v-else class="paving-pattern h-full w-full" />
                        <span v-if="s.category" class="absolute left-2.5 top-2.5 rounded-lg bg-black/50 px-2 py-1 text-[11px] font-medium text-white/90 backdrop-blur-md">{{ s.category.name }}</span>
                    </div>

                    <div class="px-4 pb-4 pt-1">
                        <h2 class="line-clamp-2 min-h-[2.5rem] text-[15px] font-semibold leading-tight text-sand-50">{{ s.title }}</h2>
                        <p class="mt-1 line-clamp-1 text-xs text-sand-100/35">
                            <template v-if="s.city">📍 {{ s.city }} · </template>{{ s.description }}
                        </p>

                        <!-- Цена: типографика, а не плашка. Разделитель + стрелка-действие -->
                        <div class="mt-3 flex items-center justify-between border-t border-sand-100/10 pt-3">
                            <span v-if="s.price" class="flex items-baseline gap-1">
                                <span class="text-[11px] text-sand-100/40">{{ $t('site.services.price_from') }}</span>
                                <span class="display text-[22px] leading-none tracking-tight text-sand-50">{{ money(s.price) }}</span>
                                <span class="text-sm font-medium text-sand-300">₸</span>
                            </span>
                            <span v-else class="text-sm font-medium text-sand-100/60">{{ $t('site.services.negotiable') }}</span>
                            <span class="flex h-8 w-8 items-center justify-center rounded-full border border-sand-100/15 text-sand-100/50 transition duration-300 group-hover:border-sand-300 group-hover:bg-sand-300 group-hover:text-ink-900">
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 17 17 7M9 7h8v8"/></svg>
                            </span>
                        </div>
                    </div>
                </Link>
            </div>
            <p v-if="!services.data.length" class="spotlight card mt-4 rounded-3xl p-10 text-center text-sm text-sand-100/50">{{ $t('site.services.empty') }}</p>
        </section>
    </SiteLayout>
</template>
