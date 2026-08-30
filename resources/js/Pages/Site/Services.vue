<script setup>
/** Публичный каталог услуг: категории, поиск, сетка карточек. */
import { ref, onMounted, onBeforeUnmount } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import SiteLayout from '@/Layouts/SiteLayout.vue';
import { siteRoute } from '@/i18n';
import { observeReveal } from '@/utils/site';

const props = defineProps({ services: Object, categories: Array, filters: Object, seo: Object });
const search = ref(props.filters.search ?? '');
let timer = null;
const apply = (extra = {}) => router.get(siteRoute('site.services'), { category: props.filters.category || undefined, search: search.value || undefined, ...extra }, { preserveState: true, preserveScroll: true, replace: true });
const onSearch = () => { clearTimeout(timer); timer = setTimeout(apply, 350); };
const money = (v) => new Intl.NumberFormat('ru-RU').format(Math.round(v));

// Класс .reveal прячет карточки до появления в кадре — без наблюдателя они
// оставались прозрачными навсегда.
let stopReveal = () => {};
onMounted(() => (stopReveal = observeReveal()));
onBeforeUnmount(() => stopReveal());
</script>

<template>
    <SiteLayout :seo="seo">
        <section>
            <div class="mx-auto max-w-7xl px-5 py-16 sm:px-8 sm:py-24">
                <p class="eyebrow">{{ $t('site.services.eyebrow') }}</p>
                <h1 class="display mt-6 max-w-3xl text-[clamp(2.25rem,6vw,4.5rem)] text-sand-50">{{ $t('site.services.title') }}</h1>
                <p class="mt-5 max-w-2xl text-sm leading-relaxed text-sand-100/55 sm:text-base">{{ $t('site.services.lead') }}</p>
            </div>
        </section>

        <section class="ambient mx-auto max-w-7xl px-5 pb-16 sm:px-8 sm:pb-24">
            <div class="mb-8 flex flex-wrap items-center gap-2">
                <Link :href="$r('site.services')" class="rounded-full border px-4 py-1.5 text-sm transition"
                    :class="!filters.category ? 'border-sand-300 bg-sand-300/15 text-sand-50' : 'border-sand-100/15 text-sand-100/60 hover:text-sand-50'">{{ $t('site.services.all') }}</Link>
                <Link v-for="c in categories" :key="c.id" :href="$r('site.services', { category: c.slug })" class="rounded-full border px-4 py-1.5 text-sm transition"
                    :class="filters.category === c.slug ? 'border-sand-300 bg-sand-300/15 text-sand-50' : 'border-sand-100/15 text-sand-100/60 hover:text-sand-50'">{{ c.name }} <span class="opacity-50">{{ c.n }}</span></Link>
                <input v-model="search" @input="onSearch" type="search" :placeholder="$t('site.services.search')"
                    class="ml-auto w-56 rounded-full border-sand-100/15 bg-transparent px-4 py-1.5 text-sm text-sand-50 placeholder-sand-100/30 focus:border-sand-300 focus:ring-sand-300" />
            </div>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 lg:gap-6">
                <Link v-for="s in services.data" :key="s.id" :href="$r('site.service', s.slug)" class="card card-hover reveal group overflow-hidden rounded-3xl">
                    <div class="relative aspect-[16/10] overflow-hidden">
                        <picture v-if="s.photo">
                            <source v-if="s.photo_webp" :srcset="s.photo_webp" type="image/webp" />
                            <img :src="s.photo" :alt="s.title" loading="lazy" decoding="async" class="h-full w-full object-cover transition duration-700 ease-premium group-hover:scale-105" />
                        </picture>
                        <div v-else class="paving-pattern h-full w-full" />
                        <div class="pointer-events-none absolute inset-0 bg-gradient-to-t from-ink-900 via-ink-900/20 to-transparent" />
                        <span v-if="s.category" class="absolute left-4 top-4 rounded-full bg-ink-900/60 px-3 py-1 text-xs text-sand-100/80 backdrop-blur">{{ s.category.name }}</span>
                    </div>
                    <div class="p-6">
                        <h2 class="display text-xl text-sand-50">{{ s.title }}</h2>
                        <p class="mt-2 text-sm leading-relaxed text-sand-100/50">{{ s.description }}</p>
                        <div class="mt-3 flex items-center justify-between text-sm">
                            <span class="text-sand-300">{{ s.price ? `${$t('site.services.price_from')} ${money(s.price)} ₸` : $t('site.services.negotiable') }}</span>
                            <span v-if="s.city" class="text-xs text-sand-100/40">📍 {{ s.city }}</span>
                        </div>
                    </div>
                </Link>
            </div>
            <p v-if="!services.data.length" class="card mt-4 p-10 text-center text-sm text-sand-100/50">{{ $t('site.services.empty') }}</p>
        </section>
    </SiteLayout>
</template>
