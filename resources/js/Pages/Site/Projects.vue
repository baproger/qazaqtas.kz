<script setup>
import { onMounted, onBeforeUnmount } from 'vue';
import { Link } from '@inertiajs/vue3';
import SiteLayout from '@/Layouts/SiteLayout.vue';
import CtaEstimate from '@/Components/site/CtaEstimate.vue';
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

            <div class="mt-16">
                <CtaEstimate
                    :title="$t('site.projects.cta_title')"
                    :lead="$t('site.projects.cta_lead')"
                    :primary-href="$r('site.contacts')"
                    :primary-label="$t('site.cta.write')"
                    :secondary-href="$r('site.catalog')"
                    :secondary-label="$t('site.cta.catalog')"
                />
            </div>
        </section>
    </SiteLayout>
</template>
