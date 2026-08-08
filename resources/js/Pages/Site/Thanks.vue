<script setup>
import { Link } from '@inertiajs/vue3';
import SiteLayout from '@/Layouts/SiteLayout.vue';
import { money } from '@/utils/site';

defineProps({
    order: { type: Object, default: null },
    seo: { type: Object, default: () => ({}) },
});
</script>

<template>
    <SiteLayout :seo="seo">
        <section class="mx-auto flex min-h-[70vh] max-w-3xl flex-col justify-center px-5 py-24 text-center sm:px-8">
            <span class="mx-auto grid h-16 w-16 place-items-center rounded-full border border-sand-300/40 bg-sand-300/10">
                <svg class="h-7 w-7 text-sand-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 12.5l5 5L20 7" /></svg>
            </span>

            <h1 class="display mt-8 text-[clamp(2rem,5vw,3.5rem)] text-sand-50">Заявка принята</h1>
            <p class="mx-auto mt-5 max-w-lg text-sm leading-relaxed text-sand-100/55 sm:text-base">
                Менеджер свяжется с вами в рабочее время, уточнит объём и сроки.
                Заявка уже в работе у отдела продаж.
            </p>

            <div v-if="order" class="mx-auto mt-10 w-full max-w-md rounded-3xl border border-white/10 bg-ink-800/50 p-6 text-left">
                <p class="eyebrow">Заказ {{ order.number }}</p>
                <ul class="mt-4 space-y-2 text-sm">
                    <li v-for="(item, i) in order.items" :key="i" class="flex justify-between gap-4 text-sand-100/60">
                        <span class="min-w-0 flex-1 truncate">{{ item.name }} · {{ item.quantity }} {{ item.unit }}</span>
                        <b class="text-sand-50">{{ money(item.sum) }}</b>
                    </li>
                </ul>
                <div class="mt-5 flex justify-between border-t border-white/10 pt-4">
                    <span class="text-sand-100/60">Итого</span>
                    <b class="text-xl text-sand-50">{{ money(order.total) }}</b>
                </div>
            </div>

            <div class="mt-10 flex flex-wrap justify-center gap-3">
                <Link :href="route('site.catalog')" class="btn-sand">Вернуться в каталог</Link>
                <Link :href="route('site.home')" class="btn-ghost">На главную</Link>
            </div>
        </section>
    </SiteLayout>
</template>
