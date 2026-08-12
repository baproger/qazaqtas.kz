<script setup>
import { onMounted, onBeforeUnmount } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import SiteLayout from '@/Layouts/SiteLayout.vue';
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
        <section class="band band-hero">
            <div class="mx-auto max-w-7xl px-5 py-16 sm:px-8 sm:py-24">
                <p class="eyebrow">О заводе</p>
                <h1 class="display mt-6 max-w-4xl text-[clamp(2.25rem,6vw,4.5rem)] text-sand-50">
                    Делаем камень, который переживёт нас
                </h1>
                <p class="mt-6 max-w-2xl text-sm leading-relaxed text-sand-100/55 sm:text-lg">
                    QAZAQ TAS — производство изделий из мраморного композита. Мраморная крошка,
                    белый цемент, фиброволокно и пигмент вместо привычной пескобетонной смеси:
                    поверхность плотнее, цвет глубже, срок службы — десятилетия.
                </p>
            </div>
        </section>

        <section class="band band-stone">
            <div class="stat-grid mx-auto grid max-w-7xl grid-cols-2 lg:grid-cols-4">
                <div v-for="s in stats" :key="s.label" class="px-6 py-10 sm:px-8 sm:py-14">
                    <p class="display text-3xl text-sand-50 sm:text-5xl">{{ s.value }}</p>
                    <p class="mt-3 text-sm text-sand-100/50">{{ s.label }}</p>
                </div>
            </div>
        </section>

        <section class="mx-auto max-w-7xl px-5 py-20 sm:px-8 sm:py-28">
            <div class="reveal max-w-2xl">
                <p class="eyebrow">Технология</p>
                <h2 class="display mt-4 text-[clamp(2rem,5vw,3.5rem)] text-sand-50">Как рождается изделие</h2>
            </div>

            <ol class="mt-14 space-y-3">
                <li
                    v-for="p in production"
                    :key="p.step"
                    class="card card-hover reveal grid gap-4 p-6 sm:grid-cols-[80px_260px_1fr] sm:items-baseline sm:gap-8 sm:p-8"
                >
                    <span class="text-sm font-semibold tracking-[0.2em] text-sand-300/70">{{ p.step }}</span>
                    <h3 class="display text-2xl text-sand-50">{{ p.title }}</h3>
                    <p class="text-sm leading-relaxed text-sand-100/55">{{ p.text }}</p>
                </li>
            </ol>
        </section>

        <section class="band band-stone">
            <div class="mx-auto max-w-7xl px-5 py-20 sm:px-8 sm:py-28">
                <p class="eyebrow reveal">Преимущества</p>
                <div class="duo-grid mt-12 grid sm:grid-cols-2">
                    <div v-for="a in advantages" :key="a.title" class="reveal p-8 sm:p-12">
                        <h3 class="display text-2xl text-sand-50 sm:text-3xl">{{ a.title }}</h3>
                        <p class="mt-4 max-w-md text-sm leading-relaxed text-sand-100/55">{{ a.text }}</p>
                    </div>
                </div>
            </div>
        </section>

        <section class="mx-auto max-w-7xl px-5 py-20 sm:px-8 sm:py-28">
            <p class="eyebrow reveal">Производственные площадки</p>
            <div class="mt-10 grid gap-4 sm:grid-cols-3">
                <article v-for="b in branches" :key="b.city" class="card card-hover reveal p-8">
                    <h3 class="display text-2xl text-sand-50">{{ b.city }}</h3>
                    <p class="mt-2 text-xs uppercase tracking-[0.2em] text-sand-300/60">{{ b.role }}</p>
                    <p class="mt-5 text-sm text-sand-100/55">{{ b.address }}</p>
                    <p class="mt-1 text-sm text-sand-100/55">{{ b.phone }}</p>
                </article>
            </div>

            <div class="mt-16 flex flex-wrap gap-3">
                <Link :href="route('site.catalog')" class="btn-sand">Смотреть каталог</Link>
                <Link :href="route('site.contacts')" class="btn-ghost">Связаться</Link>
            </div>
        </section>
    </SiteLayout>
</template>
