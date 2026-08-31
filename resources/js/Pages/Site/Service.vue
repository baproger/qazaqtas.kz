<script setup>
/** Детальная страница услуги (ЧПУ). */
import { Link } from '@inertiajs/vue3';
import SiteLayout from '@/Layouts/SiteLayout.vue';

defineProps({ service: Object, related: Array, seo: Object });
const money = (v) => new Intl.NumberFormat('ru-RU').format(Math.round(v));
</script>

<template>
    <SiteLayout :seo="seo">
        <section class="mx-auto max-w-5xl px-5 py-16 sm:px-8 sm:py-24">
            <Link :href="$r('site.services')" class="text-sm text-sand-100/50 hover:text-sand-50">← {{ $t('site.nav.services') }}</Link>
            <div class="card mt-6 overflow-hidden rounded-3xl">
                <div v-if="service.photo" class="relative aspect-[16/8] overflow-hidden">
                    <picture>
                        <source v-if="service.photo_webp" :srcset="service.photo_webp" type="image/webp" />
                        <img :src="service.photo" :alt="service.title" class="h-full w-full object-cover" />
                    </picture>
                    <div class="pointer-events-none absolute inset-0 bg-gradient-to-t from-black/60 to-transparent" />
                </div>
                <div class="p-7 sm:p-10">
                    <div class="flex flex-wrap items-center gap-3">
                        <span v-if="service.category" class="rounded-full border border-sand-100/15 px-3 py-1 text-xs text-sand-100/60">{{ service.category.name }}</span>
                        <span v-if="service.city" class="text-xs text-sand-100/40">📍 {{ service.city }}</span>
                    </div>
                    <h1 class="display mt-4 text-3xl text-sand-50 sm:text-4xl">{{ service.title }}</h1>
                    <p class="mt-4 whitespace-pre-line text-sm leading-relaxed text-sand-100/60 sm:text-base">{{ service.description_full }}</p>
                    <div class="mt-8 flex flex-wrap items-center gap-4">
                        <span class="display text-2xl text-sand-300">{{ service.price ? `${$t('site.services.price_from')} ${money(service.price)} ₸` : $t('site.services.negotiable') }}</span>
                        <a :href="`tel:${service.contact_phone}`" class="btn-sand">{{ $t('site.services.contact') }}: {{ service.contact_name }}</a>
                    </div>
                </div>
            </div>

            <template v-if="related.length">
                <h2 class="display mt-14 text-2xl text-sand-50">{{ $t('site.services.related') }}</h2>
                <div class="mt-5 grid gap-4 sm:grid-cols-3">
                    <Link v-for="s in related" :key="s.id" :href="$r('site.service', s.slug)" class="card card-hover overflow-hidden rounded-2xl">
                        <img v-if="s.thumb" :src="s.thumb" :alt="s.title" loading="lazy" class="aspect-[16/10] w-full object-cover" />
                        <div class="p-4"><div class="text-sm font-medium text-sand-50">{{ s.title }}</div></div>
                    </Link>
                </div>
            </template>
        </section>
    </SiteLayout>
</template>
