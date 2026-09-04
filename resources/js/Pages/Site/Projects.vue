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
            <ProjectsBento :projects="projects" />

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
