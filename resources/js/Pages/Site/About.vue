<script setup>
import { onMounted, onBeforeUnmount } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import SiteLayout from '@/Layouts/SiteLayout.vue';
import CountUp from '@/Components/site/CountUp.vue';
import { observeReveal } from '@/utils/site';
import { computed } from 'vue';

defineProps({
    stats: { type: Array, default: () => [] },
    production: { type: Array, default: () => [] },
    advantages: { type: Array, default: () => [] },
    seo: { type: Object, default: () => ({}) },
});

const branches = computed(() => usePage().props.site?.branches ?? []);

let stop = () => {};
onMounted(() => (stop = observeReveal()));
onBeforeUnmount(() => stop());
</script>

<template>
    <SiteLayout :seo="seo">
        <section>
            <div class="mx-auto max-w-7xl px-5 py-16 sm:px-8 sm:py-24">
                <p class="eyebrow">{{ $t('site.about.eyebrow') }}</p>
                <h1 class="display mt-6 max-w-4xl text-[clamp(2.25rem,6vw,4.5rem)] text-sand-50">
                    {{ $t('site.about.title') }}
                </h1>
                <p class="mt-6 max-w-2xl text-sm leading-relaxed text-sand-100/55 sm:text-lg">
                    {{ $t('site.about.lead') }}
                </p>
            </div>
        </section>

        <section>
            <div class="mx-auto max-w-7xl px-5 py-14 sm:px-8">
                <div class="spotlight card reveal relative grid grid-cols-2 overflow-hidden rounded-3xl lg:grid-cols-4">
                    <div v-for="s in stats" :key="s.label" class="stat-cell group px-6 py-10 sm:px-8 sm:py-14">
                        <span class="pointer-events-none absolute left-6 top-7 h-px w-10 bg-gradient-to-r from-sand-300/80 to-emerald-400/60 transition-all duration-300 ease-premium group-hover:w-16 sm:left-8" aria-hidden="true" />
                        <p class="display text-3xl tabular-nums text-sand-50 sm:text-5xl"><CountUp :value="s.value" /></p>
                        <p class="mt-3 text-sm text-sand-100/50">{{ s.label }}</p>
                    </div>
                </div>
            </div>
        </section>

        <section class="mx-auto max-w-7xl px-5 py-20 sm:px-8 sm:py-28">
            <div class="reveal max-w-2xl">
                <p class="eyebrow">{{ $t('site.about.tech_eyebrow') }}</p>
                <h2 class="display mt-4 text-[clamp(2rem,5vw,3.5rem)] text-sand-50">{{ $t('site.about.tech_title') }}</h2>
            </div>

            <!-- Таймлайн: золотая направляющая ведёт от сырья к отгрузке —
                 процесс читается как путь, а не как пять одинаковых полос. -->
            <ol class="relative mt-14 space-y-8 before:absolute before:bottom-4 before:left-[27px] before:top-4 before:w-px before:bg-gradient-to-b before:from-sand-300/50 before:via-sand-300/20 before:to-transparent">
                <li
                    v-for="p in production"
                    :key="p.step"
                    class="reveal relative grid gap-3 pl-20 sm:grid-cols-[240px_1fr] sm:gap-10"
                >
                    <span class="display absolute left-0 top-1/2 grid h-14 w-14 -translate-y-1/2 place-items-center rounded-2xl border border-sand-300/25 bg-sand-300/10 text-lg text-sand-300 backdrop-blur">{{ p.step }}</span>
                    <h3 class="display self-center text-2xl text-sand-50">{{ p.title }}</h3>
                    <p class="self-center text-sm leading-relaxed text-sand-100/55">{{ p.text }}</p>
                </li>
            </ol>
        </section>

        <section>
            <div class="mx-auto max-w-7xl px-5 py-20 sm:px-8 sm:py-28">
                <p class="eyebrow reveal">{{ $t('site.about.advantages') }}</p>
                <div class="mt-12 grid gap-4 sm:grid-cols-2 lg:gap-6">
                    <div v-for="(a, i) in advantages" :key="a.title" class="spotlight card card-hover reveal relative overflow-hidden rounded-3xl p-8 sm:p-10">
                        <!-- Номер-водяной знак: даёт карточке характер, не съедая текст. -->
                        <span class="display pointer-events-none absolute -right-2 -top-7 text-[7rem] leading-none text-sand-300/[0.07]">{{ String(i + 1).padStart(2, '0') }}</span>
                        <h3 class="display text-2xl text-sand-50 sm:text-3xl">{{ a.title }}</h3>
                        <p class="mt-4 max-w-md text-sm leading-relaxed text-sand-100/55">{{ a.text }}</p>
                    </div>
                </div>
            </div>
        </section>

        <section class="mx-auto max-w-7xl px-5 py-20 sm:px-8 sm:py-28">
            <p class="eyebrow reveal">{{ $t('site.about.sites') }}</p>
            <div class="mt-10 grid gap-4 sm:grid-cols-3">
                <article v-for="b in branches" :key="b.city" class="spotlight card card-hover reveal p-8">
                    <h3 class="display text-2xl text-sand-50">{{ b.city }}</h3>
                    <p class="mt-2 text-xs uppercase tracking-[0.2em] text-sand-300/60">{{ b.role }}</p>
                    <p class="mt-5 text-sm text-sand-100/55">{{ b.address }}</p>
                    <p class="mt-1 text-sm text-sand-100/55">{{ b.phone }}</p>
                </article>
            </div>

            <div class="mt-16 flex flex-wrap gap-3">
                <Link :href="$r('site.catalog')" class="btn-sand">{{ $t('site.cta.catalog') }}</Link>
                <Link :href="$r('site.contacts')" class="btn-ghost">{{ $t('site.cta.contact') }}</Link>
            </div>
        </section>
    </SiteLayout>
</template>
