<script setup>
import { onMounted, onBeforeUnmount } from 'vue';
import { Link } from '@inertiajs/vue3';
import SiteLayout from '@/Layouts/SiteLayout.vue';
import ProjectsBento from '@/Components/site/ProjectsBento.vue';
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
        <section>
            <div class="mx-auto max-w-7xl px-5 py-16 sm:px-8 sm:py-24">
                <p class="eyebrow">{{ $t('site.projects.eyebrow') }}</p>
                <h1 class="display mt-6 max-w-3xl text-[clamp(2.25rem,6vw,4.5rem)] text-sand-50">{{ $t('site.projects.title') }}</h1>
                <p class="mt-5 max-w-2xl text-sm leading-relaxed text-sand-100/55 sm:text-base">
                    {{ $t('site.projects.lead') }}
                </p>
            </div>
        </section>

        <section class="ambient mx-auto max-w-7xl px-5 py-16 sm:px-8 sm:py-24">
            <ProjectsBento :projects="projects.slice(0, 4)" />

            <!-- Остальные объекты — ровной сеткой под bento -->
            <div v-if="projects.length > 4" class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:mt-6 lg:grid-cols-3 lg:gap-6">
                <article v-for="p in projects.slice(4)" :key="p.title" class="card card-hover reveal group overflow-hidden rounded-3xl">
                    <div class="relative aspect-[16/10] overflow-hidden">
                        <img v-if="p.image" :src="p.thumb || p.image" :alt="p.title" loading="lazy" decoding="async"
                            class="h-full w-full object-cover transition duration-700 ease-premium group-hover:scale-105" />
                        <div v-else class="paving-pattern flex h-full w-full items-center justify-center">
                            <span class="text-xs uppercase tracking-[0.28em] text-sand-100/25">{{ $t('site.projects.photo_placeholder') }}</span>
                        </div>
                        <div class="pointer-events-none absolute inset-0 bg-gradient-to-t from-ink-900 via-ink-900/20 to-transparent" />
                        <p v-if="p.area" class="absolute bottom-4 left-5 display text-2xl text-sand-50">{{ p.area }}</p>
                    </div>
                    <div class="p-6">
                        <p class="text-xs uppercase tracking-[0.24em] text-sand-300/60">{{ p.city }}<template v-if="p.year"> · {{ p.year }}</template></p>
                        <h2 class="display mt-2 text-xl text-sand-50">{{ p.title }}</h2>
                        <p v-if="p.products" class="mt-3 text-sm leading-relaxed text-sand-100/50">{{ p.products }}</p>
                    </div>
                </article>
            </div>

            <div class="card mt-16 p-10 text-center sm:p-16">
                <h2 class="display text-[clamp(1.75rem,4vw,3rem)] text-sand-50">{{ $t('site.projects.cta_title') }}</h2>
                <p class="mx-auto mt-4 max-w-lg text-sm text-sand-100/55">
                    {{ $t('site.projects.cta_lead') }}
                </p>
                <div class="mt-8 flex flex-wrap justify-center gap-3">
                    <Link :href="$r('site.contacts')" class="btn-ghost">{{ $t('site.cta.write') }}</Link>
                </div>
            </div>
        </section>
    </SiteLayout>
</template>
