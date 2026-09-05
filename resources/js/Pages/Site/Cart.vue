<script setup>
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import SiteLayout from '@/Layouts/SiteLayout.vue';
import ProductCard from '@/Components/site/ProductCard.vue';
import ProductVisual from '@/Components/site/ProductVisual.vue';
import { money, number } from '@/utils/site';
import { useSiteRoute } from '@/composables/useTranslations';

// Запросы уходят на маршрут текущего языка — иначе переход с /ru/ сбрасывал
// бы посетителя на казахскую версию корзины.
const { siteRoute } = useSiteRoute();

const props = defineProps({
    cart: { type: Object, required: true },
    whatsapp: { type: String, default: '' },
    recommended: { type: Array, default: () => [] },
    delivery: { type: Array, default: () => [] },
    seo: { type: Object, default: () => ({}) },
});

const city = ref(props.delivery[0]?.city ?? '');
const distance = ref(0);

/** Прикидка доставки: база города + километраж, бесплатно от суммы. */
const deliveryCost = computed(() => {
    const rate = props.delivery.find((d) => d.city === city.value);
    if (!rate) return null;
    if (props.cart.total >= Number(rate.free_from)) return 0;
    return Number(rate.base) + Number(rate.per_km) * Math.max(0, Number(distance.value) || 0);
});

const grandTotal = computed(() => props.cart.total + (deliveryCost.value ?? 0));

const setQuantity = (key, value) => {
    router.patch(siteRoute('site.cart.update'), { key, quantity: Number(value) }, { preserveScroll: true });
};

const removeItem = (key) => router.delete(siteRoute('site.cart.remove'), { data: { key }, preserveScroll: true });
const clearCart = () => router.delete(siteRoute('site.cart.clear'), { preserveScroll: true });
</script>

<template>
    <SiteLayout :seo="seo">
        <section class="ambient mx-auto max-w-7xl px-5 py-12 sm:px-8 sm:py-16">
            <h1 class="display text-[clamp(2rem,5vw,3.5rem)] text-sand-50">{{ $t('site.cart.title') }}</h1>

            <!-- Пустая корзина — та же градиентная панель без рамок, что и блок
                 «Посчитаем ваш двор»: глубокий ink-градиент и два glow-пятна. -->
            <div v-if="!cart.items.length" class="spotlight-soft relative mt-12 overflow-hidden rounded-3xl bg-gradient-to-br from-ink-700 via-ink-800 to-ink-900 px-8 py-20 text-center">
                <div class="pointer-events-none absolute -left-24 -top-32 h-80 w-80 rounded-full bg-sand-300/20 blur-3xl" aria-hidden="true" />
                <div class="pointer-events-none absolute -bottom-36 -right-24 h-96 w-96 rounded-full bg-emerald-400/15 blur-3xl" aria-hidden="true" />
                <div class="relative mx-auto mb-6 grid h-16 w-16 place-items-center rounded-2xl border border-sand-300/20 bg-sand-300/10 text-sand-300 backdrop-blur">
                    <svg viewBox="0 0 24 24" class="h-7 w-7" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M4 6h2l2.4 10.2a1.5 1.5 0 0 0 1.46 1.15h6.9a1.5 1.5 0 0 0 1.45-1.1L20.5 9H7"/><circle cx="10.5" cy="20" r="1.2"/><circle cx="17" cy="20" r="1.2"/></svg>
                </div>
                <p class="display relative text-2xl text-sand-50">{{ $t('site.cart.empty_title') }}</p>
                <p class="relative mx-auto mt-3 max-w-md text-sm leading-relaxed text-sand-100/60">{{ $t('site.cart.empty_lead') }}</p>
                <div class="relative mt-8 flex flex-wrap justify-center gap-3">
                    <Link :href="$r('site.catalog')" class="btn-sand">{{ $t('site.cart.to_catalog') }}</Link>
                </div>
            </div>

            <div v-else class="mt-10 grid gap-8 lg:grid-cols-[1fr_380px]">
                <!-- Позиции -->
                <div class="space-y-3">
                    <article
                        v-for="item in cart.items"
                        :key="item.key"
                        class="spotlight card card-sm flex flex-wrap items-center gap-5 p-5"
                    >
                        <!-- Снимок как в каталоге: есть фото — фото, нет —
                             векторная схема по типу изделия и цвету. -->
                        <Link :href="$r('site.product', item.slug)" class="shrink-0">
                            <ProductVisual
                                :product="{
                                    name: item.name,
                                    images: item.image ? [item.image] : [],
                                    colors: item.colors,
                                    category: { slug: item.category_slug },
                                }"
                                :color="item.color_hex"
                                ratio="aspect-square"
                                shape="rounded-xl"
                                class="w-20"
                            />
                        </Link>

                        <div class="min-w-0 flex-1">
                            <p class="text-xs text-sand-100/40">{{ item.category }}</p>
                            <Link :href="$r('site.product', item.slug)" class="mt-1 block truncate text-base font-medium text-sand-50 transition hover:text-sand-300">
                                {{ item.name }}
                            </Link>
                            <p class="mt-1 text-xs text-sand-100/45">
                                {{ money(item.price) }} / {{ item.unit }}
                                <span v-if="item.color"> · {{ item.color }}</span>
                            </p>
                        </div>

                        <div class="flex items-center rounded-full border border-white/12">
                            <button class="h-10 w-10 text-sand-100/70 transition hover:text-sand-50" :aria-label="$t('site.cart.less')" @click="setQuantity(item.key, Math.max(0, item.quantity - 1))">−</button>
                            <input
                                :value="item.quantity"
                                type="number"
                                min="0"
                                step="0.5"
                                class="h-10 w-20 border-0 bg-transparent text-center text-sm text-sand-50 focus:ring-0"
                                @change="setQuantity(item.key, $event.target.value)"
                            />
                            <button class="h-10 w-10 text-sand-100/70 transition hover:text-sand-50" :aria-label="$t('site.cart.more')" @click="setQuantity(item.key, item.quantity + 1)">+</button>
                        </div>

                        <p class="w-32 text-right text-base font-semibold text-sand-50">{{ money(item.sum) }}</p>

                        <button class="text-sand-100/30 transition hover:text-rose-400" :aria-label="$t('site.common.remove')" @click="removeItem(item.key)">✕</button>
                    </article>

                    <div class="flex flex-wrap justify-between gap-3 pt-2">
                        <Link :href="$r('site.catalog')" class="btn-clean btn-clean-muted">{{ $t('site.cart.continue') }}</Link>
                        <button class="btn-clean btn-clean-danger" @click="clearCart">{{ $t('site.cart.clear') }}</button>
                    </div>
                </div>

                <!-- Итоги -->
                <aside class="lg:sticky lg:top-28 lg:self-start">
                    <div class="spotlight card p-6 sm:p-7">
                        <p class="eyebrow">{{ $t('site.common.total') }}</p>

                        <div class="mt-5 space-y-3 text-sm">
                            <div class="flex justify-between text-sand-100/60">
                                <span>{{ $t('site.cart.materials') }}</span><b class="text-sand-50">{{ money(cart.total) }}</b>
                            </div>

                            <div class="divider-top pt-4">
                                <label class="text-xs text-sand-100/45">{{ $t('site.cart.delivery_city') }}</label>
                                <select v-model="city" class="mt-1.5 w-full rounded-xl border-white/12 bg-white/[0.04] px-3 py-2.5 text-sm text-sand-50 focus:border-sand-300 focus:ring-0">
                                    <option v-for="d in delivery" :key="d.city" :value="d.city" class="bg-ink-800">{{ d.city }}</option>
                                </select>

                                <label class="mt-3 block text-xs text-sand-100/45">{{ $t('site.cart.distance') }}</label>
                                <input v-model="distance" type="number" min="0" class="mt-1.5 w-full rounded-xl border-white/12 bg-white/[0.04] px-3 py-2.5 text-sm text-sand-50 focus:border-sand-300 focus:ring-0" />

                                <div class="mt-3 flex justify-between text-sand-100/60">
                                    <span>{{ $t('site.cart.delivery') }}</span>
                                    <b class="text-sand-50">{{ deliveryCost === 0 ? $t('site.cart.free') : money(deliveryCost ?? 0) }}</b>
                                </div>
                            </div>

                            <div class="divider-top flex items-baseline justify-between pt-4">
                                <span class="text-sand-100/60">{{ $t('site.cart.to_pay') }}</span>
                                <b class="display text-3xl text-sand-50">{{ money(grandTotal) }}</b>
                            </div>
                        </div>

                        <Link :href="$r('site.checkout')" class="btn-clean btn-clean-brand mt-6 w-full">{{ $t('site.cart.checkout') }}</Link>
                        <a :href="whatsapp" target="_blank" rel="noopener" class="btn-whatsapp mt-3 w-full"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.45 1.32 4.95L2 22l5.25-1.38a9.9 9.9 0 0 0 4.79 1.22h.01c5.46 0 9.91-4.45 9.91-9.91 0-2.65-1.03-5.14-2.9-7.01A9.86 9.86 0 0 0 12.04 2Zm0 18.15h-.01a8.2 8.2 0 0 1-4.19-1.15l-.3-.18-3.12.82.83-3.04-.2-.31a8.2 8.2 0 0 1-1.26-4.38c0-4.54 3.7-8.23 8.25-8.23 2.2 0 4.27.86 5.83 2.41a8.19 8.19 0 0 1 2.41 5.83c0 4.54-3.7 8.23-8.24 8.23Zm4.52-6.16c-.25-.12-1.47-.72-1.69-.81-.23-.08-.39-.12-.56.13-.16.24-.64.8-.78.97-.15.16-.29.18-.53.06-.25-.13-1.05-.39-1.99-1.23-.74-.66-1.23-1.47-1.38-1.71-.14-.25-.01-.38.11-.5.11-.11.25-.29.37-.44.13-.15.17-.25.25-.41.08-.17.04-.31-.02-.44-.06-.12-.56-1.34-.76-1.84-.2-.48-.4-.42-.56-.42-.14-.01-.3-.01-.47-.01-.16 0-.43.06-.66.31-.22.24-.86.85-.86 2.07 0 1.22.89 2.4 1.01 2.56.12.17 1.75 2.67 4.23 3.74.59.26 1.05.41 1.41.52.59.19 1.13.16 1.56.1.48-.07 1.47-.6 1.68-1.18.2-.58.2-1.08.15-1.18-.06-.11-.22-.17-.47-.29Z" /></svg>{{ $t('site.cart.whatsapp_order') }}</a>
                        <a :href="$r('site.quotation')" class="btn-ghost mt-3 w-full">{{ $t('site.cart.quotation') }}</a>

                        <p class="mt-4 text-[11px] leading-relaxed text-sand-100/35">
                            {{ $t('site.cart.delivery_note') }}
                        </p>
                    </div>
                </aside>
            </div>

            <section v-if="recommended.length" class="mt-24">
                <h2 class="display text-2xl text-sand-50 sm:text-3xl">{{ $t('site.cart.recommended') }}</h2>
                <div class="mt-8 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                    <ProductCard v-for="p in recommended" :key="p.id" :product="p" compact />
                </div>
            </section>
        </section>
    </SiteLayout>
</template>
