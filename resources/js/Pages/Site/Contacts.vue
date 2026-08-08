<script setup>
import { computed, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import SiteLayout from '@/Layouts/SiteLayout.vue';

defineProps({
    faq: { type: Array, default: () => [] },
    seo: { type: Object, default: () => ({}) },
});

const page = usePage();
const contacts = computed(() => page.props.site?.contacts ?? {});
const branches = computed(() => page.props.site?.branches ?? []);
const openFaq = ref(0);
</script>

<template>
    <SiteLayout :seo="seo">
        <section class="border-b border-white/10 bg-ink-800/40">
            <div class="mx-auto max-w-7xl px-5 py-16 sm:px-8 sm:py-24">
                <p class="eyebrow">Контакты</p>
                <h1 class="display mt-6 text-[clamp(2.25rem,6vw,4.5rem)] text-sand-50">Поговорим о вашем объекте</h1>
                <div class="mt-10 flex flex-wrap gap-3">
                    <a :href="`https://wa.me/${contacts.whatsapp}`" target="_blank" rel="noopener" class="btn-sand">Написать в WhatsApp</a>
                    <a :href="`tel:${String(contacts.phone || '').replace(/[^\d+]/g, '')}`" class="btn-ghost">{{ contacts.phone }}</a>
                </div>
                <p class="mt-6 text-sm text-sand-100/50">{{ contacts.hours }} · {{ contacts.email }}</p>
            </div>
        </section>

        <section class="mx-auto max-w-7xl px-5 py-16 sm:px-8 sm:py-24">
            <p class="eyebrow">Производство и склады</p>
            <div class="mt-8 grid gap-4 sm:grid-cols-3">
                <article v-for="b in branches" :key="b.city" class="concrete rounded-3xl border border-white/10 bg-ink-800/60 p-8">
                    <h2 class="display text-2xl text-sand-50">{{ b.city }}</h2>
                    <p class="mt-2 text-xs uppercase tracking-[0.2em] text-sand-300/60">{{ b.role }}</p>
                    <p class="mt-5 text-sm text-sand-100/55">{{ b.address }}</p>
                    <a :href="`tel:${String(b.phone || '').replace(/[^\d+]/g, '')}`" class="mt-1 block text-sm text-sand-300 hover:underline">{{ b.phone }}</a>
                    <a
                        :href="`https://yandex.ru/maps/?text=${encodeURIComponent(b.city + ' ' + b.address)}`"
                        target="_blank" rel="noopener"
                        class="mt-6 inline-block text-sm text-sand-100/45 underline-offset-4 hover:text-sand-300 hover:underline"
                    >Показать на карте →</a>
                </article>
            </div>
        </section>

        <section class="border-t border-white/10 bg-ink-800/30">
            <div class="mx-auto max-w-4xl px-5 py-20 sm:px-8 sm:py-28">
                <h2 class="display text-[clamp(1.75rem,4vw,3rem)] text-sand-50">Частые вопросы</h2>
                <div class="mt-10 divide-y divide-white/10 border-y border-white/10">
                    <div v-for="(item, i) in faq" :key="i">
                        <button
                            class="flex w-full items-center justify-between gap-6 py-6 text-left"
                            :aria-expanded="openFaq === i"
                            @click="openFaq = openFaq === i ? -1 : i"
                        >
                            <span class="text-base font-medium text-sand-50 sm:text-lg">{{ item.q }}</span>
                            <span class="shrink-0 text-2xl text-sand-300 transition" :class="openFaq === i ? 'rotate-45' : ''">+</span>
                        </button>
                        <p v-show="openFaq === i" class="pb-6 text-sm leading-relaxed text-sand-100/55">{{ item.a }}</p>
                    </div>
                </div>
            </div>
        </section>
    </SiteLayout>
</template>
