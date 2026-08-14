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
        <section>
            <div class="mx-auto max-w-7xl px-5 py-16 sm:px-8 sm:py-24">
                <p class="eyebrow">{{ $t('site.contacts.eyebrow') }}</p>
                <h1 class="display mt-6 text-[clamp(2.25rem,6vw,4.5rem)] text-sand-50">{{ $t('site.contacts.title') }}</h1>
                <div class="mt-10 flex flex-wrap gap-3">
                    <a :href="`https://wa.me/${contacts.whatsapp}`" target="_blank" rel="noopener" class="btn-whatsapp"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.45 1.32 4.95L2 22l5.25-1.38a9.9 9.9 0 0 0 4.79 1.22h.01c5.46 0 9.91-4.45 9.91-9.91 0-2.65-1.03-5.14-2.9-7.01A9.86 9.86 0 0 0 12.04 2Zm0 18.15h-.01a8.2 8.2 0 0 1-4.19-1.15l-.3-.18-3.12.82.83-3.04-.2-.31a8.2 8.2 0 0 1-1.26-4.38c0-4.54 3.7-8.23 8.25-8.23 2.2 0 4.27.86 5.83 2.41a8.19 8.19 0 0 1 2.41 5.83c0 4.54-3.7 8.23-8.24 8.23Zm4.52-6.16c-.25-.12-1.47-.72-1.69-.81-.23-.08-.39-.12-.56.13-.16.24-.64.8-.78.97-.15.16-.29.18-.53.06-.25-.13-1.05-.39-1.99-1.23-.74-.66-1.23-1.47-1.38-1.71-.14-.25-.01-.38.11-.5.11-.11.25-.29.37-.44.13-.15.17-.25.25-.41.08-.17.04-.31-.02-.44-.06-.12-.56-1.34-.76-1.84-.2-.48-.4-.42-.56-.42-.14-.01-.3-.01-.47-.01-.16 0-.43.06-.66.31-.22.24-.86.85-.86 2.07 0 1.22.89 2.4 1.01 2.56.12.17 1.75 2.67 4.23 3.74.59.26 1.05.41 1.41.52.59.19 1.13.16 1.56.1.48-.07 1.47-.6 1.68-1.18.2-.58.2-1.08.15-1.18-.06-.11-.22-.17-.47-.29Z" /></svg>{{ $t('site.cta.whatsapp') }}</a>
                    <a :href="`tel:${String(contacts.phone || '').replace(/[^\d+]/g, '')}`" class="btn-ghost">{{ contacts.phone }}</a>
                </div>
                <p class="mt-6 text-sm text-sand-100/50">{{ contacts.hours }} · {{ contacts.email }}</p>
            </div>
        </section>

        <section class="ambient mx-auto max-w-7xl px-5 py-16 sm:px-8 sm:py-24">
            <p class="eyebrow">{{ $t('site.contacts.branches') }}</p>
            <div class="mt-8 grid gap-4 sm:grid-cols-3">
                <article v-for="b in branches" :key="b.city" class="card card-hover p-8">
                    <h2 class="display text-2xl text-sand-50">{{ b.city }}</h2>
                    <p class="mt-2 text-xs uppercase tracking-[0.2em] text-sand-300/60">{{ b.role }}</p>
                    <p class="mt-5 text-sm text-sand-100/55">{{ b.address }}</p>
                    <a :href="`tel:${String(b.phone || '').replace(/[^\d+]/g, '')}`" class="mt-1 block text-sm text-sand-300 hover:underline">{{ b.phone }}</a>
                    <a
                        :href="`https://yandex.ru/maps/?text=${encodeURIComponent(b.city + ' ' + b.address)}`"
                        target="_blank" rel="noopener"
                        class="mt-6 inline-block text-sm text-sand-100/45 underline-offset-4 hover:text-sand-300 hover:underline"
                    >{{ $t('site.contacts.map') }}</a>
                </article>
            </div>
        </section>

        <section>
            <div class="mx-auto max-w-4xl px-5 py-20 sm:px-8 sm:py-28">
                <h2 class="display text-[clamp(1.75rem,4vw,3rem)] text-sand-50">{{ $t('site.contacts.faq') }}</h2>
                <div class="card mt-10 divide-y divide-white/10 px-6 sm:px-8">
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
