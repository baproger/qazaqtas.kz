<script setup>
/**
 * Призыв «посчитаем ваш двор/объект» — один дизайн на всех страницах.
 *
 * Панель без рамок и обводок (просьба владельца): глубокий градиентный
 * фон и два glow-пятна фирменных цветов, никаких кромок по периметру.
 * Всё на токенах витрины — обе темы из коробки.
 */
import { Link } from '@inertiajs/vue3';

defineProps({
    title: { type: String, required: true },
    lead: { type: String, default: '' },
    primaryHref: { type: String, required: true },
    primaryLabel: { type: String, required: true },
    secondaryHref: { type: String, default: '' },
    secondaryLabel: { type: String, default: '' },
});
</script>

<template>
    <div class="reveal relative overflow-hidden rounded-3xl bg-gradient-to-br from-ink-700 via-ink-800 to-ink-900 px-8 py-16 text-center sm:px-16 sm:py-20">
            <!-- Glow-пятна: тёплый песок сверху, зелёный снизу — та же пара,
                 что подсвечивает полотно витрины. -->
            <div class="pointer-events-none absolute -left-24 -top-32 h-80 w-80 rounded-full bg-sand-300/20 blur-3xl" aria-hidden="true" />
            <div class="pointer-events-none absolute -bottom-36 -right-24 h-96 w-96 rounded-full bg-emerald-400/15 blur-3xl" aria-hidden="true" />

            <p class="eyebrow relative">{{ $t('site.cta.free_estimate') }}</p>
            <h2 class="display relative mx-auto mt-4 max-w-2xl text-balance text-[clamp(1.75rem,4vw,2.75rem)] text-sand-50">
                {{ title }}
            </h2>
            <p v-if="lead" class="relative mx-auto mt-5 max-w-md text-pretty text-sm leading-relaxed text-sand-100/60">
                {{ lead }}
            </p>
            <div class="relative mt-9 flex flex-wrap justify-center gap-3">
                <Link :href="primaryHref" class="btn-sand">{{ primaryLabel }}</Link>
                <Link v-if="secondaryHref" :href="secondaryHref" class="btn-ghost">{{ secondaryLabel }}</Link>
        </div>
    </div>
</template>
