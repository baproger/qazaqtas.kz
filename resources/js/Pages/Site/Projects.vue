<script setup>
import { onMounted, onBeforeUnmount } from 'vue';
import { Link } from '@inertiajs/vue3';
import SiteLayout from '@/Layouts/SiteLayout.vue';
import { observeReveal } from '@/utils/site';

defineProps({
    projects: { type: Array, default: () => [] },
    seo: { type: Object, default: () => ({}) },
});

let stop = () => {};
onMounted(() => (stop = observeReveal()));
onBeforeUnmount(() => stop());
</script>

<template>
    <SiteLayout :seo="seo">
        <section class="band band-hero">
            <div class="mx-auto max-w-7xl px-5 py-16 sm:px-8 sm:py-24">
                <p class="eyebrow">Реализовано</p>
                <h1 class="display mt-6 max-w-3xl text-[clamp(2.25rem,6vw,4.5rem)] text-sand-50">Объекты, где уложен наш камень</h1>
                <p class="mt-5 max-w-2xl text-sm leading-relaxed text-sand-100/55 sm:text-base">
                    Дворы жилых комплексов, парки, набережные и школьные территории.
                    По каждому объекту делали расчёт раскладки, изготовление и отгрузку.
                </p>
            </div>
        </section>

        <section class="mx-auto max-w-7xl px-5 py-16 sm:px-8 sm:py-24">
            <div class="grid gap-4 sm:grid-cols-2">
                <article
                    v-for="p in projects"
                    :key="p.title"
                    class="reveal group overflow-hidden rounded-3xl border border-white/10 bg-ink-800/60 transition duration-500 ease-premium hover:-translate-y-1 hover:border-sand-300/40"
                >
                    <div class="relative aspect-[16/10] overflow-hidden">
                        <img
                            v-if="p.image"
                            :src="p.image"
                            :srcset="p.thumb ? `${p.thumb} 600w, ${p.image} 1600w` : undefined"
                            sizes="(max-width: 640px) 100vw, 50vw"
                            :alt="p.title"
                            loading="lazy"
                            decoding="async"
                            class="h-full w-full object-cover transition duration-700 ease-premium group-hover:scale-105"
                        />
                        <div v-else class="paving-pattern flex h-full w-full items-center justify-center">
                            <span class="text-[11px] uppercase tracking-[0.28em] text-sand-100/25">фото объекта</span>
                        </div>
                        <div class="pointer-events-none absolute inset-0 bg-gradient-to-t from-ink-900 via-ink-900/20 to-transparent" />
                        <p v-if="p.area" class="absolute bottom-5 left-6 display text-3xl text-sand-50 sm:text-4xl">{{ p.area }}</p>
                    </div>

                    <div class="p-7 sm:p-9">
                        <p class="text-xs uppercase tracking-[0.24em] text-sand-300/60">
                            {{ p.city }}<template v-if="p.year"> · {{ p.year }}</template>
                        </p>
                        <h2 class="display mt-3 text-2xl text-sand-50 sm:text-3xl">{{ p.title }}</h2>
                        <p v-if="p.products" class="mt-4 text-sm leading-relaxed text-sand-100/50">{{ p.products }}</p>
                        <p v-if="p.description" class="mt-3 text-sm leading-relaxed text-sand-100/40">{{ p.description }}</p>
                    </div>
                </article>
            </div>

            <div class="mt-16 rounded-3xl border border-white/10 bg-ink-800/40 p-10 text-center sm:p-16">
                <h2 class="display text-[clamp(1.75rem,4vw,3rem)] text-sand-50">Посчитаем ваш объект</h2>
                <p class="mx-auto mt-4 max-w-lg text-sm text-sand-100/55">
                    Пришлите площадь и пожелания — подготовим раскладку, смету и сроки.
                </p>
                <div class="mt-8 flex flex-wrap justify-center gap-3">
                    <Link :href="route('site.configurator')" class="btn-sand">Собрать в 3D</Link>
                    <Link :href="route('site.contacts')" class="btn-ghost">Написать нам</Link>
                </div>
            </div>
        </section>
    </SiteLayout>
</template>
